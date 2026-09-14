<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Enums\GuestRequestStatus;
use App\Exceptions\GuestQuotaExceededException;
use App\Exceptions\IllegalTransitionException;
use App\Guests\AccessCodeGenerator;
use App\Guests\GuestQuota;
use App\Guests\GuestRequestStateMachine;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\User;
use App\Notifications\GuestRequestDecided;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The design entity §3.3.4 specifies in full over IEEE 1016-1998 clause 5.3,
 * built to that specification.
 *
 * *Purpose*: owns the guest-request lifecycle — submission, the duty officer's
 * decision, cancellation, expiry (FR-16, FR-17, FR-20).
 * *Subordinates*: `GuestRequestStateMachine`, `AccessCodeGenerator`,
 * `GuestQuota`, `AuditRecorder`. *Dependencies*: the domain layer only; no
 * controller, no HTTP object, no status code (§3.3.1).
 *
 * Three things in the *Processing* column of that table are load-bearing and
 * each is written out below where it happens: the row is taken under
 * `SELECT … FOR UPDATE`; the audit record is written **inside** the same
 * transaction as the change; and the notification is dispatched **after** the
 * commit.
 *
 * **The refusal is logged outside the transaction, and that is not a detail.**
 * §3.9.6 counts a refusal among the events the log must hold, and a refusal
 * written inside the transaction it refuses is carried away by the rollback —
 * leaving a log that records every approval and no quota breach, which is the
 * one shape of log that is worse than none. So the quota is asserted inside,
 * and its exception is caught outside and recorded there.
 */
final readonly class GuestRequestService
{
    public function __construct(
        private GuestRequestStateMachine $states,
        private AccessCodeGenerator $codes,
        private GuestQuota $quota,
        private AuditRecorder $audit,
    ) {}

    /**
     * FR-16: the resident submits a request.
     *
     * The validity of the interval — the lead time, the visiting window, the
     * date — is settled by `StoreGuestRequestRequest` before this method is
     * reached, because it is a property of the input and 422 is the answer
     * (§3.3.3). What is left here is the row and the record that it was made.
     */
    public function submit(
        User $student,
        Building $building,
        string $guestFullName,
        CarbonInterface $visitDate,
        string $plannedFrom,
        string $plannedTo,
        ?string $ipAddress = null,
    ): GuestRequest {
        return DB::transaction(function () use (
            $student, $building, $guestFullName, $visitDate, $plannedFrom, $plannedTo, $ipAddress
        ): GuestRequest {
            $request = GuestRequest::query()->create([
                'student_id' => $student->getKey(),
                'building_id' => $building->getKey(),
                'guest_full_name' => $guestFullName,
                'visit_date' => $visitDate->toDateString(),
                'planned_from' => $plannedFrom,
                'planned_to' => $plannedTo,
                'status' => GuestRequestStatus::PendingReview,
            ]);

            $this->audit->record(
                action: AuditAction::GuestRequestSubmitted,
                actor: $student,
                subject: $request,
                payload: [
                    'building_id' => $building->getKey(),
                    'guest_full_name' => $guestFullName,
                    'visit_date' => $visitDate->toDateString(),
                    'planned_from' => $plannedFrom,
                    'planned_to' => $plannedTo,
                ],
                ipAddress: $ipAddress,
            );

            return $request;
        });
    }

    /**
     * FR-17: the duty officer approves.
     *
     * @throws IllegalTransitionException the request is no longer pending (409)
     * @throws GuestQuotaExceededException the day is full (422)
     */
    public function approve(
        User $officer,
        GuestRequest $request,
        ?string $comment = null,
        ?string $ipAddress = null,
    ): GuestRequest {
        try {
            $approved = DB::transaction(function () use ($officer, $request, $comment, $ipAddress): GuestRequest {
                // §3.3.4: «load the request under SELECT … FOR UPDATE». Two
                // officers with the same queue open reach this line together
                // and the second one waits, which is what makes the transition
                // assertion below mean anything at all.
                $locked = GuestRequest::query()->lockForUpdate()->findOrFail($request->getKey());

                $this->states->assert($locked->status, GuestRequestStatus::Approved);

                $this->quota->assertRoomFor($locked);

                $locked->status = GuestRequestStatus::Approved;
                // The code is drawn here and nowhere earlier (§3.5.1): before
                // the decision there is nothing to present at the post.
                $locked->access_code = $this->codes->generate();
                $locked->decided_by = $officer->getKey();
                $locked->decided_at = now();
                $locked->decision_comment = $comment;
                $locked->save();

                $this->audit->record(
                    action: AuditAction::GuestRequestApproved,
                    actor: $officer,
                    subject: $locked,
                    payload: [
                        'building_id' => $locked->building_id,
                        'student_id' => $locked->student_id,
                        'visit_date' => $locked->visit_date?->toDateString(),
                        'comment' => $comment,
                    ],
                    ipAddress: $ipAddress,
                );

                return $locked;
            });
        } catch (GuestQuotaExceededException $refusal) {
            $this->recordDecisionRefusal($officer, $request, $refusal, $ipAddress);

            throw $refusal;
        }

        // After the commit, never inside it: a notification queued from inside
        // a transaction that then rolls back is a message about something that
        // did not happen, and the queue cannot take it back.
        $this->notifyApplicant($approved, approved: true, comment: $comment);

        return $approved;
    }

    /**
     * FR-17, second criterion: «on rejection a reason is selected from a
     * directory or entered as free text; rejection without a reason is
     * impossible».
     *
     * The reason is a required argument of this method rather than a nullable
     * one checked at the top. A signature that admits the refusal without a
     * reason is a signature that will one day be called that way; making it
     * unrepresentable costs nothing and the validator states the same rule at
     * the boundary for the sake of the 422.
     */
    public function reject(
        User $officer,
        GuestRequest $request,
        string $reason,
        ?string $ipAddress = null,
    ): GuestRequest {
        $rejected = DB::transaction(function () use ($officer, $request, $reason, $ipAddress): GuestRequest {
            $locked = GuestRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $this->states->assert($locked->status, GuestRequestStatus::Rejected);

            $locked->status = GuestRequestStatus::Rejected;
            $locked->decided_by = $officer->getKey();
            $locked->decided_at = now();
            $locked->decision_comment = $reason;
            $locked->save();

            $this->audit->record(
                action: AuditAction::GuestRequestRejected,
                actor: $officer,
                subject: $locked,
                payload: [
                    'building_id' => $locked->building_id,
                    'student_id' => $locked->student_id,
                    'visit_date' => $locked->visit_date?->toDateString(),
                    'reason' => $reason,
                ],
                ipAddress: $ipAddress,
            );

            return $locked;
        });

        $this->notifyApplicant($rejected, approved: false, comment: $reason);

        return $rejected;
    }

    /**
     * The resident withdraws their own request, before the decision or after
     * an approval they no longer need.
     */
    public function cancel(User $student, GuestRequest $request, ?string $ipAddress = null): GuestRequest
    {
        return DB::transaction(function () use ($student, $request, $ipAddress): GuestRequest {
            $locked = GuestRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $this->states->assert($locked->status, GuestRequestStatus::Cancelled);

            $locked->status = GuestRequestStatus::Cancelled;
            $locked->save();

            $this->audit->record(
                action: AuditAction::GuestRequestCancelled,
                actor: $student,
                subject: $locked,
                payload: [
                    'building_id' => $locked->building_id,
                    'had_access_code' => $locked->access_code !== null,
                ],
                ipAddress: $ipAddress,
            );

            return $locked;
        });
    }

    /**
     * FR-17, fourth criterion: «a request not processed by the start of the
     * visit is treated as rejected».
     *
     * Rejected and not a state of its own — see `GuestRequestStateMachine` on
     * why. `decided_by` stays null, because nobody decided: that null is the
     * only honest way for the row to say that the outcome was the calendar's
     * and not a person's, and it is the reason the CHECK on that table admits
     * a moment without an author.
     *
     * The resident is notified exactly as they would be for a refusal by an
     * officer. The applicant's interest is in knowing that no guest is coming,
     * and it does not depend on who or what closed the request.
     */
    public function expireUndecided(GuestRequest $request, ?CarbonInterface $now = null): GuestRequest
    {
        $now ??= CarbonImmutable::now();

        $reason = 'No decision was taken before the visit was due to begin, '
            .'so the request is treated as rejected.';

        $expired = DB::transaction(function () use ($request, $reason, $now): GuestRequest {
            $locked = GuestRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $this->states->assert($locked->status, GuestRequestStatus::Rejected);

            $locked->status = GuestRequestStatus::Rejected;
            // The sweep's own moment rather than `now()`: one pass closes a
            // batch of requests, and they are closed by one run at one time —
            // not at whatever the clock said as each row came round.
            $locked->decided_at = $now;
            $locked->decision_comment = $reason;
            $locked->save();

            $this->audit->record(
                action: AuditAction::GuestRequestRejected,
                actor: null,
                subject: $locked,
                payload: [
                    'building_id' => $locked->building_id,
                    'student_id' => $locked->student_id,
                    'visit_date' => $locked->visit_date?->toDateString(),
                    'reason' => $reason,
                    'decided_by' => 'the scheduler; no officer looked at this request',
                ],
                result: AuditResult::Denied,
            );

            return $locked;
        });

        $this->notifyApplicant($expired, approved: false, comment: $reason);

        return $expired;
    }

    /**
     * §3.5.4, `Approved → Expired`: the visit day ended and the guest never
     * came. No notification: nothing happened, and a message saying so would
     * be the system reporting the absence of an event.
     */
    public function expireUnused(GuestRequest $request): GuestRequest
    {
        return DB::transaction(function () use ($request): GuestRequest {
            $locked = GuestRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $this->states->assert($locked->status, GuestRequestStatus::Expired);

            $locked->status = GuestRequestStatus::Expired;
            $locked->save();

            $this->audit->record(
                action: AuditAction::GuestRequestExpired,
                actor: null,
                subject: $locked,
                payload: [
                    'building_id' => $locked->building_id,
                    'visit_date' => $locked->visit_date?->toDateString(),
                    'reason' => 'the visit day ended with no entry recorded',
                ],
            );

            return $locked;
        });
    }

    private function recordDecisionRefusal(
        User $officer,
        GuestRequest $request,
        GuestQuotaExceededException $refusal,
        ?string $ipAddress,
    ): void {
        $this->audit->record(
            action: AuditAction::GuestRequestDecisionRefused,
            actor: $officer,
            subject: $request,
            payload: ['reason' => $refusal->getMessage()] + $refusal->context(),
            result: AuditResult::Denied,
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-17, third criterion: «the applicant is notified of the decision».
     *
     * `GuestRequestDecided` takes plain values rather than a model — it was
     * written in increment 0 against a service that did not exist yet, and
     * this is the call it was written for.
     *
     * There is no longer anything between the decision and the message. The
     * dispatch used to be gated on the consent of FR-35, and an undelivered
     * decision was an audited event because a criterion phrased «the applicant
     * is notified» could not otherwise be checked against the log; with FR-35
     * out of the MVP the applicant is notified, full stop. A request whose
     * author no longer exists is the one case that sends nothing, and it
     * writes nothing either — there is nobody the message was owed to.
     */
    private function notifyApplicant(GuestRequest $request, bool $approved, ?string $comment): void
    {
        $student = $request->student()->first();

        if ($student === null) {
            return;
        }

        $student->notify(new GuestRequestDecided(
            requestId: (int) $request->getKey(),
            guestName: (string) $request->guest_full_name,
            approved: $approved,
            comment: $comment,
        ));
    }
}
