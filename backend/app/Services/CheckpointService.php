<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestVisitStatus;
use App\Exceptions\ConsentRequiredException;
use App\Exceptions\EntryNotPermittedException;
use App\Exceptions\VisitAlreadyClosedException;
use App\Guests\AccessCodeGenerator;
use App\Guests\CheckpointCard;
use App\Guests\GuestRequestStateMachine;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The security post: FR-18 (find the guest), FR-19 (record the entry and the
 * exit), and the consent gate §2.7.1 puts between the two.
 *
 * **Verification and the entry are two operations and that is the design.**
 * §3.5.1 splits them deliberately: `verify()` only reads and renders the card
 * for comparison with the document in the officer's hand, `checkIn()` changes
 * state. The split is what lets an officer refuse entry without leaving a
 * false record of an entry that never happened — a single «admit» call would
 * force the choice between recording an admission that did not occur and
 * recording nothing at all about a person who turned up. It is also why FR-19's
 * «two clicks» and the refusal path do not conflict: the card is already on
 * the screen when the officer decides.
 *
 * **Consent is taken here and not at submission.** The request is filed by the
 * resident while the personal data belong to the guest, so consent under
 * art. 9 part 1 of Federal Law No. 152-FZ cannot be given «on the guest's
 * behalf» (§2.7.1). It is taken from the guest, in person, at the desk, before
 * the entry is written — which is the first line of `checkIn()` and the reason
 * `ConsentRegistry::requireGrantedForGuest()` exists.
 *
 * **The system restricts nobody's movement.** FR-20 states it and §2.4.2
 * explains it: the ground for refusing a person entry to a dormitory is the
 * university's local act and the action of the security service. What this
 * class refuses is a *record* — it will not write that an entry was within the
 * rules when the interval says it was not. The officer may still admit the
 * guest, on the responsible officer's decision, with a reason that is stored.
 */
final readonly class CheckpointService
{
    public function __construct(
        private GuestRequestStateMachine $states,
        private ConsentRegistry $consents,
        private AuditRecorder $audit,
    ) {}

    /**
     * FR-18: find the guest by code or by surname, inside one dormitory.
     *
     * FR-18 asks for code, QR and surname; the QR carries the code, so two
     * searches cover all three.
     *
     * @return Collection<int, GuestRequest>
     */
    public function search(
        User $officer,
        Building $building,
        ?string $code = null,
        ?string $surname = null,
        ?CarbonInterface $now = null,
        ?string $ipAddress = null,
    ): Collection {
        $now ??= CarbonImmutable::now();

        $query = GuestRequest::query()
            ->inBuilding($building)
            ->with(['student.residencies.bed.room', 'building', 'visit']);

        if ($code !== null && $code !== '') {
            $query->where('access_code', AccessCodeGenerator::normalise($code));
        } else {
            /*
             * A surname search is scoped to the day. The post is looking for
             * somebody standing in front of it, not through the archive: a
             * common surname over a whole year would put a list on the screen
             * that nobody can compare a document against, and the archive is
             * what the register of FR-21 is for.
             */
            $query
                ->where('guest_full_name', 'ilike', '%'.trim((string) $surname).'%')
                ->whereDate('visit_date', $now->toDateString());
        }

        $found = $query->orderBy('visit_date')->orderBy('planned_from')->limit(25)->get();

        $this->audit->record(
            action: AuditAction::GuestVerifiedAtCheckpoint,
            actor: $officer,
            subject: $building,
            payload: [
                'by' => $code !== null && $code !== '' ? 'access_code' : 'surname',
                'matches' => $found->count(),
                'guest_request_ids' => $found->modelKeys(),
            ],
            ipAddress: $ipAddress,
        );

        return $found;
    }

    /**
     * The card of FR-18, assembled for one request.
     *
     * The verdict and the five fields are produced together, by one call, so
     * that the screen the officer reads and the rule the entry is refused by
     * cannot say different things about the same moment.
     */
    public function cardFor(GuestRequest $request, ?CarbonInterface $now = null): CheckpointCard
    {
        $now ??= CarbonImmutable::now();

        $request->loadMissing(['student', 'building', 'visit', 'consentRecords']);

        return new CheckpointCard(
            request: $request,
            visit: $request->visit,
            room: CheckpointCard::roomOf($request, $now),
            refusal: $this->admissionRefusal($request, $now),
            at: $now,
        );
    }

    /**
     * Why this guest may not be admitted right now, or null if they may.
     *
     * The same method answers `verify` and guards `check-in`, which is the
     * point of it existing: the card the officer reads and the rule the entry
     * is refused by cannot disagree, because they are the same call. A second
     * copy of this logic inside the check-in would be the defect §2.4.2's
     * second scenario exists to catch — a screen that offers a button the
     * server then refuses.
     */
    public function admissionRefusal(GuestRequest $request, ?CarbonInterface $now = null): ?EntryNotPermittedException
    {
        $now ??= CarbonImmutable::now();

        if ($request->status->isInsideTheBuilding()) {
            return EntryNotPermittedException::alreadyInside();
        }

        if (! $request->status->admitsAnEntry()) {
            return EntryNotPermittedException::notApproved($request->status->label());
        }

        $window = $request->plannedWindow();

        if ($request->visit_date !== null && ! $request->visit_date->isSameDay($now)
            && ! $window->contains($now)) {
            return EntryNotPermittedException::wrongDay($request->visit_date->toDateString());
        }

        if (! $window->contains($now)) {
            // §2.4.2, second scenario: «the system shows the status "outside
            // the permitted interval" and blocks the entry record».
            return EntryNotPermittedException::outsideInterval(
                from: $window->from->format('H:i'),
                to: $window->to->format('H:i'),
                now: $now->format('H:i'),
            );
        }

        return null;
    }

    /**
     * FR-19: record the entry.
     *
     * @param  string|null  $overrideReason  §2.4.2's «admit on the responsible officer's decision»,
     *                                       mandatory when the refusal is one that may be set aside
     *
     * @throws ConsentRequiredException no consent from the guest (409)
     * @throws EntryNotPermittedException the rules admit no entry, and no reason was given (422)
     */
    public function checkIn(
        User $officer,
        GuestRequest $request,
        ?string $overrideReason = null,
        ?CarbonInterface $now = null,
        ?string $ipAddress = null,
    ): GuestVisit {
        // §2.7.1, first line and deliberately first: for a guest, consent is
        // the sole ground for the processing, so nothing about this evening is
        // written down until it is on record.
        $this->consents->requireGrantedForGuest($request);

        $now ??= CarbonImmutable::now();
        $refusal = $this->admissionRefusal($request, $now);
        $override = $refusal !== null;

        if ($refusal !== null && ! $this->mayBeSetAside($refusal, $overrideReason)) {
            // Outside the transaction, because there is no transaction: the
            // refusal writes nothing but this row, and §3.9.6 names the
            // refusal of entry among the events the log must hold.
            $this->recordEntryRefusal($officer, $request, $refusal, $ipAddress);

            throw $refusal;
        }

        $visit = DB::transaction(function () use ($officer, $request, $now, $override, $overrideReason, $ipAddress): GuestVisit {
            $locked = GuestRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $locked->setRelation('building', $request->building ?? $locked->building()->first());

            $this->states->assert($locked->status, GuestRequestStatus::InProgress);

            $visit = GuestVisit::query()->create([
                'guest_request_id' => $locked->getKey(),
                'checked_in_at' => $now,
                'checked_in_by' => $officer->getKey(),
                'status' => GuestVisitStatus::InBuilding,
                'due_at' => $locked->dueAt(),
                'admitted_on_decision' => $override,
                'admission_note' => $override ? $overrideReason : null,
            ]);

            $locked->status = GuestRequestStatus::InProgress;
            $locked->save();

            $this->audit->record(
                action: AuditAction::GuestEntryRecorded,
                actor: $officer,
                subject: $visit,
                payload: [
                    'guest_request_id' => $locked->getKey(),
                    'building_id' => $locked->building_id,
                    'student_id' => $locked->student_id,
                    'checked_in_at' => $now->toIso8601String(),
                    'due_at' => $visit->due_at?->toIso8601String(),
                    'admitted_on_decision' => $override,
                ],
                ipAddress: $ipAddress,
            );

            if ($override) {
                // A second row, not a field on the first. An admission against
                // the interval is an exception somebody has to answer for, and
                // the log is read by action: «show me every entry admitted on
                // a decision» has to be one query and not a scan of payloads.
                $this->audit->record(
                    action: AuditAction::GuestAdmittedOnDecision,
                    actor: $officer,
                    subject: $visit,
                    payload: [
                        'guest_request_id' => $locked->getKey(),
                        'building_id' => $locked->building_id,
                        'reason' => $overrideReason,
                    ],
                    result: AuditResult::Denied,
                    ipAddress: $ipAddress,
                );
            }

            return $visit;
        });

        return $visit;
    }

    /**
     * FR-19: record the exit.
     *
     * The exit is written once. The database refuses a second one through the
     * write-once trigger and the ordering CHECK; this method refuses it first,
     * with a message that says what to do instead — FR-21's «a correction is
     * made as a correcting entry», which is `recordCorrection()` below.
     *
     * `closed` or `closed_late` is decided against the deadline frozen on the
     * visit, not against the building's setting as it stands today. A register
     * whose verdict on last week changes when somebody edits a column is not
     * a register.
     *
     * @throws VisitAlreadyClosedException (409)
     */
    public function checkOut(
        User $officer,
        GuestVisit $visit,
        ?CarbonInterface $now = null,
        ?string $ipAddress = null,
    ): GuestVisit {
        $now ??= CarbonImmutable::now();

        if ($visit->checked_out_at !== null) {
            throw new VisitAlreadyClosedException(
                visitId: (int) $visit->getKey(),
                closedAt: $visit->checked_out_at->toIso8601String(),
            );
        }

        return DB::transaction(function () use ($officer, $visit, $now, $ipAddress): GuestVisit {
            $locked = GuestVisit::query()->lockForUpdate()->findOrFail($visit->getKey());

            if ($locked->checked_out_at !== null) {
                throw new VisitAlreadyClosedException(
                    visitId: (int) $locked->getKey(),
                    closedAt: $locked->checked_out_at->toIso8601String(),
                );
            }

            $late = $locked->due_at !== null && $now->greaterThan($locked->due_at);

            $locked->checked_out_at = $now;
            $locked->checked_out_by = $officer->getKey();
            $locked->status = $late ? GuestVisitStatus::ClosedLate : GuestVisitStatus::Closed;
            $locked->save();

            $request = GuestRequest::query()->lockForUpdate()->findOrFail($locked->guest_request_id);
            $this->states->assert($request->status, GuestRequestStatus::Completed);
            $request->status = GuestRequestStatus::Completed;
            $request->save();

            $this->audit->record(
                action: AuditAction::GuestExitRecorded,
                actor: $officer,
                subject: $locked,
                payload: [
                    'guest_request_id' => $request->getKey(),
                    'building_id' => $request->building_id,
                    'checked_out_at' => $now->toIso8601String(),
                    'due_at' => $locked->due_at?->toIso8601String(),
                    'late' => $late,
                ],
                ipAddress: $ipAddress,
            );

            return $locked;
        });
    }

    /**
     * FR-20, the state change half: the control time has passed and no exit is
     * recorded.
     *
     * Called by the quarter-hourly sweep and by nothing else. It writes
     * `overdue_notified_at`, which is what makes a second run send nothing —
     * and the database refuses to overwrite that column, so the guarantee
     * survives two sweeps racing each other on the same row.
     *
     * The notification is not sent here. It is sent by the command, after this
     * transaction commits, for the same reason the decision's notification is:
     * a message queued inside a transaction that rolls back is a message about
     * something that did not happen.
     */
    public function markOverdue(GuestVisit $visit, ?CarbonInterface $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($visit, $now): bool {
            $locked = GuestVisit::query()->lockForUpdate()->find($visit->getKey());

            if ($locked === null || ! $locked->isOpen() || $locked->overdue_notified_at !== null) {
                return false;
            }

            $locked->status = GuestVisitStatus::Overdue;
            $locked->overdue_notified_at = $now;
            $locked->save();

            $request = GuestRequest::query()->lockForUpdate()->findOrFail($locked->guest_request_id);

            if ($this->states->allows($request->status, GuestRequestStatus::Overdue)) {
                $request->status = GuestRequestStatus::Overdue;
                $request->save();
            }

            $this->audit->record(
                action: AuditAction::GuestVisitOverdue,
                actor: null,
                subject: $locked,
                payload: [
                    'guest_request_id' => $request->getKey(),
                    'building_id' => $request->building_id,
                    'student_id' => $request->student_id,
                    'due_at' => $locked->due_at?->toIso8601String(),
                    'detected_at' => $now->toIso8601String(),
                ],
                result: AuditResult::Denied,
            );

            return true;
        });
    }

    /**
     * FR-21, second criterion: «entries are immutable; a correction is made as
     * a correcting entry».
     *
     * The correcting entry is a row in `audit_logs` naming the visit, and
     * that is the whole mechanism. It is not a lesser form of an edit: the
     * original fact stays readable beside the correction, which is exactly
     * what a numbered paper journal gives and what an editable row does not.
     * The register export shows both.
     */
    public function recordCorrection(
        User $author,
        GuestVisit $visit,
        string $correction,
        ?string $ipAddress = null,
    ): void {
        $request = $visit->request()->first();

        $this->audit->record(
            action: AuditAction::GuestVisitCorrected,
            actor: $author,
            subject: $visit,
            payload: [
                'guest_request_id' => $visit->guest_request_id,
                'building_id' => $request?->building_id,
                'correction' => $correction,
                'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
                'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            ],
            ipAddress: $ipAddress,
        );
    }

    /**
     * The open visit of this request, if the guest is still recorded inside.
     */
    public function openVisitOf(GuestRequest $request): ?GuestVisit
    {
        return GuestVisit::query()
            ->where('guest_request_id', $request->getKey())
            ->open()
            ->first();
    }

    private function mayBeSetAside(EntryNotPermittedException $refusal, ?string $reason): bool
    {
        return $refusal->overrideAvailable && $reason !== null && trim($reason) !== '';
    }

    private function recordEntryRefusal(
        User $officer,
        GuestRequest $request,
        EntryNotPermittedException $refusal,
        ?string $ipAddress,
    ): void {
        $this->audit->record(
            action: AuditAction::GuestEntryRefused,
            actor: $officer,
            subject: $request,
            payload: [
                'building_id' => $request->building_id,
                'guest_full_name' => $request->guest_full_name,
                'reason' => $refusal->getMessage(),
            ] + $refusal->context(),
            result: AuditResult::Denied,
            ipAddress: $ipAddress,
        );
    }
}
