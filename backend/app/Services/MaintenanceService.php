<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceLocation;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceUrgency;
use App\Enums\Permission;
use App\Enums\RoleCode;
use App\Exceptions\ConfirmationWindowClosedException;
use App\Exceptions\IllegalTransitionException;
use App\Maintenance\MaintenanceRequestStateMachine;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkLog;
use App\Models\Residency;
use App\Models\Room;
use App\Models\User;
use App\Notifications\MaintenanceRequestFiled;
use App\Notifications\MaintenanceRequestStatusChanged;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The maintenance module's one owner of state (FR-36 … FR-39), built to the
 * specification §3.3.4 sets for a service of this layer.
 *
 * *Purpose*: owns the life of a maintenance request — submission, triage, the
 * work, the reporter's confirmation, the reopening and the scheduled closure.
 * *Subordinates*: `MaintenanceRequestStateMachine`, `AuditRecorder`,
 * `Notifier`. *Dependencies*: the domain layer only; no controller, no HTTP
 * object, no status code (§3.3.1) — the photographs arrive as paths, which is
 * why `App\Files\PhotoStore` exists.
 *
 * **Every public method below is one call to `move()`, and that is the design
 * rather than an economy.** §3.4.1's sixth decision says each status change is
 * recorded as a row with actor, moment and comment instead of overwriting a
 * field; FR-37's third criterion says the same in the language of acceptance.
 * A rule of that shape is kept by making it impossible to change a status
 * without writing the row — so there is exactly one private method that writes
 * `status`, it asserts the transition, it writes the log row in the same
 * transaction, and nothing else in the application assigns to that column.
 * Nine methods each doing it themselves would have been nine chances for one
 * of them to forget, and the one that forgot would be invisible until somebody
 * asked when a request had been fixed.
 *
 * **The three ordering rules of §3.3.4 are kept here as they are in
 * `GuestRequestService`.** The row is taken under `SELECT … FOR UPDATE`, so
 * two wardens with the same queue open do not both move it. The audit record
 * is written **inside** the transaction of the change it describes. The
 * notification is dispatched **after** the commit, because a message queued
 * from inside a transaction that then rolls back is a message about something
 * that did not happen, and the queue cannot take it back.
 *
 * **The refusal is logged outside the transaction**, for the reason §3.9.6
 * gives: a refusal written inside the transaction it refuses is carried away
 * by the rollback, leaving a log that records every reopening and no refused
 * one.
 */
final readonly class MaintenanceService
{
    public function __construct(
        private MaintenanceRequestStateMachine $states,
        private AuditRecorder $audit,
        private Notifier $notifier,
        private int $confirmationWindowDays,
    ) {}

    /**
     * FR-36: the resident files a request.
     *
     * **The room is read from the register and never from the body.** FR-36's
     * acceptance criterion binds an «own room» request to «the room of the
     * submitter's active residency record», and the emphasis is on *the
     * register's* answer: a client that could name the room could name
     * somebody else's, and the request would then carry a defect reported
     * against a stranger's door. A resident with no active residency has no
     * room to resolve and cannot file at all — that half is asserted in
     * `MaintenanceRequestPolicy::create` before this method is reached,
     * because it is a question about the register rather than about the input,
     * and it is asserted again here so that no other caller can walk past it.
     *
     * @param  list<string>  $photoPaths  Already in the object store; see `PhotoStore`.
     */
    public function submit(
        User $reporter,
        Building $building,
        MaintenanceCategory $category,
        MaintenanceLocation $location,
        string $description,
        MaintenanceUrgency $urgency = MaintenanceUrgency::Routine,
        ?string $locationNote = null,
        ?string $title = null,
        array $photoPaths = [],
        ?string $ipAddress = null,
    ): MaintenanceRequest {
        $room = $location->bindsToARoom()
            ? $this->roomOfActiveResidency($reporter, $building)
            : null;

        $filed = DB::transaction(function () use (
            $reporter, $building, $room, $category, $location, $locationNote,
            $title, $description, $urgency, $photoPaths
        ): MaintenanceRequest {
            $request = MaintenanceRequest::query()->create([
                'building_id' => $building->getKey(),
                'room_id' => $room?->getKey(),
                'reporter_id' => $reporter->getKey(),
                'category' => $category,
                'location' => $location,
                'location_note' => $locationNote,
                'title' => $title,
                'description' => $description,
                'urgency' => $urgency,
                'photo_paths' => $photoPaths,
                'status' => MaintenanceRequestStatus::Submitted,
            ]);

            /*
             * The first row of the log, with a null `from_status`: the request
             * did not move, it came into being. Written inside the same
             * transaction as the request itself, so a request without a
             * history is not a state the database can be left in.
             */
            $this->log(
                request: $request,
                actor: $reporter,
                from: null,
                to: MaintenanceRequestStatus::Submitted,
                comment: $description,
            );

            return $request;
        });

        /*
         * FR-36, last line of the Gherkin. The staff who triage, which by
         * `RoleCode::permissions()` is the warden and the manager of this
         * dormitory — the same circle everywhere the contract says «the warden
         * of this building» about operative work.
         */
        $this->notifier->sendOnce(
            $this->triageStaffOf($building),
            new MaintenanceRequestFiled(
                requestId: (int) $filed->getKey(),
                buildingName: (string) $building->name,
                category: $category->label(),
                urgency: $urgency->label(),
                place: $filed->placeDescription(),
            ),
        );

        return $filed;
    }

    /**
     * FR-37: the warden takes the request into work.
     *
     * The planned completion date is a required argument rather than a
     * nullable one checked at the top, for the reason `GuestRequestService::
     * reject()` requires its reason: a signature that admits the acceptance
     * without a date is a signature that will one day be called that way.
     * `AcceptMaintenanceRequestRequest` states the same rule at the boundary
     * for the sake of the 422, and the CHECK constraint states it a third time
     * for the sake of everything that is neither.
     *
     * @throws IllegalTransitionException the request is no longer submitted (409)
     */
    public function accept(
        User $actor,
        MaintenanceRequest $request,
        CarbonInterface $targetDate,
        ?User $assignee = null,
        ?MaintenanceUrgency $urgency = null,
        ?string $comment = null,
        ?string $ipAddress = null,
    ): MaintenanceRequest {
        return $this->move(
            actor: $actor,
            request: $request,
            to: MaintenanceRequestStatus::Accepted,
            comment: $comment,
            apply: function (MaintenanceRequest $locked) use ($targetDate, $assignee, $urgency): void {
                $locked->target_date = $targetDate->toDateString();

                if ($assignee !== null) {
                    $locked->assigned_to = $assignee->getKey();
                    $locked->assigned_at = now();
                }

                // FR-37 names reassignment and a change of priority in the
                // same breath as the acceptance. The resident's urgency is
                // what they knew; the warden's is what the queue is ordered
                // by, and the change is in the log like everything else.
                if ($urgency !== null) {
                    $locked->urgency = $urgency;
                }
            },
            auditAction: AuditAction::MaintenanceRequestAccepted,
            auditPayload: [
                'target_date' => $targetDate->toDateString(),
                'assigned_to' => $assignee?->getKey(),
                'urgency' => $urgency?->value,
            ],
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-37, first criterion: «rejection without a reason is impossible».
     *
     * The reason is a required argument here and a required field in
     * `RejectMaintenanceRequestRequest`. Two statements of one rule, and
     * neither is redundant: a rule that lives only at the boundary is a rule
     * any other caller walks past, and a rule that lives only in the service
     * produces a 500 where the client deserves a 422 naming the field.
     *
     * The reason is stored in the work log and not in a column of its own
     * (§3.4.1, decision 6). A second copy on the request would be the field
     * that quietly disagrees with the history.
     *
     * @throws IllegalTransitionException
     */
    public function reject(
        User $actor,
        MaintenanceRequest $request,
        string $reason,
        ?string $ipAddress = null,
    ): MaintenanceRequest {
        return $this->move(
            actor: $actor,
            request: $request,
            to: MaintenanceRequestStatus::Rejected,
            comment: $reason,
            auditAction: AuditAction::MaintenanceRequestRejected,
            auditPayload: ['reason' => $reason],
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-38: the work has begun.
     *
     * @throws IllegalTransitionException
     */
    public function start(
        User $actor,
        MaintenanceRequest $request,
        ?string $comment = null,
        ?string $ipAddress = null,
    ): MaintenanceRequest {
        return $this->move(
            actor: $actor,
            request: $request,
            to: MaintenanceRequestStatus::InProgress,
            comment: $comment,
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-38: the work is reported done — and the request is **not** closed.
     *
     * This is the line §3.5.2 calls the reason the module is worth building:
     * «a status set by whoever did the work proves nothing, whereas
     * confirmation by the resident produces a two-sided record». `completed`
     * is therefore a waiting state, not an ending, and the only two ways out
     * of it are the reporter's word (`confirm`, `reopen`) and the clock
     * (`closeUnconfirmed`).
     *
     * `completed_at` is rewritten on each completion, which is what restarts
     * the confirmation window of FR-39 after a reopening.
     *
     * @throws IllegalTransitionException
     */
    public function complete(
        User $actor,
        MaintenanceRequest $request,
        ?string $comment = null,
        ?string $ipAddress = null,
    ): MaintenanceRequest {
        return $this->move(
            actor: $actor,
            request: $request,
            to: MaintenanceRequestStatus::Completed,
            comment: $comment,
            apply: static function (MaintenanceRequest $locked): void {
                $locked->completed_at = now();
            },
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-39: the reporter confirms the work and the request closes.
     *
     * The actor is the reporter and the policy has already said so. Nothing
     * here re-asks it, for the same reason nothing here re-asks whether the
     * warden may triage: an authorisation question answered in two places is
     * an authorisation question with two answers.
     *
     * @throws IllegalTransitionException
     */
    public function confirm(
        User $reporter,
        MaintenanceRequest $request,
        ?string $comment = null,
        ?string $ipAddress = null,
    ): MaintenanceRequest {
        return $this->move(
            actor: $reporter,
            request: $request,
            to: MaintenanceRequestStatus::Closed,
            comment: $comment,
            apply: static function (MaintenanceRequest $locked): void {
                $locked->confirmed_at = now();
                $locked->closed_at = now();
                $locked->auto_closed = false;
            },
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-39, first criterion: «reopening within the configurable window
     * returns the request to accepted and increments a reopen counter».
     *
     * Back to `accepted` and not to `submitted`, which is the requirement's
     * word and the right one: the dormitory has already agreed that the defect
     * is theirs and has already named a date, and sending it back to the queue
     * of untriaged requests would make the resident queue twice for one
     * defect. The planned date stays on the row — it is now visibly missed,
     * which is what the overdue flag of FR-40 is for.
     *
     * @throws ConfirmationWindowClosedException the window has run out (409)
     * @throws IllegalTransitionException
     */
    public function reopen(
        User $reporter,
        MaintenanceRequest $request,
        ?string $reason = null,
        ?CarbonInterface $now = null,
        ?string $ipAddress = null,
    ): MaintenanceRequest {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        try {
            $this->assertTheWindowIsOpen($request, $now);
        } catch (ConfirmationWindowClosedException $refusal) {
            $this->audit->record(
                action: AuditAction::MaintenanceReopeningRefused,
                actor: $reporter,
                subject: $request,
                payload: ['reason' => $refusal->getMessage()] + $refusal->context(),
                result: AuditResult::Denied,
                ipAddress: $ipAddress,
            );

            throw $refusal;
        }

        $reopened = $this->move(
            actor: $reporter,
            request: $request,
            to: MaintenanceRequestStatus::Accepted,
            comment: $reason ?? 'The reporter says the defect is not fixed.',
            apply: static function (MaintenanceRequest $locked): void {
                $locked->reopen_count = (int) $locked->reopen_count + 1;
                $locked->completed_at = null;
            },
            auditAction: AuditAction::MaintenanceRequestReopened,
            auditPayload: ['reason' => $reason],
            ipAddress: $ipAddress,
        );

        /*
         * FR-39's Gherkin ends «and the warden is notified». The reporter is
         * the actor, so `move()` told nobody; the people who have to know are
         * the ones who will do the work again.
         */
        $this->notifier->sendOnce(
            $this->triageStaffOf($reopened->building()->firstOrFail()),
            new MaintenanceRequestStatusChanged(
                requestId: (int) $reopened->getKey(),
                fromStatus: MaintenanceRequestStatus::Completed->value,
                toStatus: MaintenanceRequestStatus::Accepted->value,
                comment: $reason ?? 'The reporter says the defect is not fixed.',
            ),
        );

        return $reopened;
    }

    /**
     * FR-39, second criterion: «a request not confirmed within the window
     * closes automatically».
     *
     * Marked as automatically closed and **not** as confirmed, which the CHECK
     * constraint of the table also refuses to confuse. The distinction is the
     * whole value of the record: a request the resident confirmed is evidence
     * that the defect was fixed; a request that closed because nobody answered
     * is evidence of nothing except that the dormitory stopped waiting, and a
     * system that recorded the second as the first would be manufacturing the
     * two-sided record the module exists to produce.
     *
     * There is no actor. The null in `maintenance_work_logs.actor_id` is the
     * log's way of saying that the calendar decided.
     *
     * @throws IllegalTransitionException
     */
    public function closeUnconfirmed(
        MaintenanceRequest $request,
        ?CarbonInterface $now = null,
    ): MaintenanceRequest {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        $comment = sprintf(
            'Closed automatically: the confirmation window of %d day(s) passed with no answer from the reporter.',
            $this->confirmationWindowDays,
        );

        return $this->move(
            actor: null,
            request: $request,
            to: MaintenanceRequestStatus::Closed,
            comment: $comment,
            apply: static function (MaintenanceRequest $locked) use ($now): void {
                $locked->auto_closed = true;
                $locked->confirmed_at = null;
                // The pass's own moment rather than `now()`: one run closes a
                // batch and they are closed by one run at one time, not at
                // whatever the clock said as each row came round.
                $locked->closed_at = $now;
            },
            auditAction: AuditAction::MaintenanceRequestAutoClosed,
            auditPayload: [
                'confirmation_window_days' => $this->confirmationWindowDays,
                'completed_at' => $request->completed_at?->toIso8601String(),
            ],
            at: $now,
        );
    }

    /**
     * The confirmation window in days, as configured. Read by the queue, by
     * the API resource and by `AutoCloseConfirmedWork`, so that the number a
     * resident is shown and the number the job acts on are the same number.
     */
    public function confirmationWindowDays(): int
    {
        return $this->confirmationWindowDays;
    }

    /**
     * FR-40's digest: the overdue fact recorded as an event.
     *
     * One row per request per pass, written by `ScanOverdueMaintenance`. It is
     * not a transition — an overdue request is still `accepted` or
     * `in_progress` and nothing about it has changed except the date — so it
     * goes to the audit log and not to the work log, which records movements.
     */
    public function recordOverdue(MaintenanceRequest $request, int $thresholdDays): void
    {
        $this->audit->record(
            action: AuditAction::MaintenanceRequestOverdue,
            actor: null,
            subject: $request,
            payload: [
                'building_id' => $request->building_id,
                'status' => $request->status?->value,
                'age_days' => $request->ageInDaysAt(),
                'target_date' => $request->target_date?->toDateString(),
                'threshold_days' => $thresholdDays,
            ],
            result: AuditResult::Denied,
        );
    }

    /**
     * The warden and the manager of one dormitory: everybody whose role
     * carries the triage capability there.
     *
     * Named as a capability and not as two role codes, for §3.3.3's reason. A
     * seventh role that triages is added to `RoleCode::permissions()` and
     * starts receiving these notifications with no edit here.
     *
     * @return Collection<int, User>
     */
    public function triageStaffOf(Building $building): Collection
    {
        $codes = array_values(array_map(
            static fn (RoleCode $code): string => $code->value,
            array_filter(
                RoleCode::cases(),
                static fn (RoleCode $code): bool => $code->grants(Permission::TriageMaintenanceRequests),
            ),
        ));

        if ($codes === []) {
            return new Collection;
        }

        return User::query()
            ->whereHas('roleGrants', fn (Builder $grant) => $grant
                ->where('building_id', $building->getKey())
                ->whereHas('role', fn (Builder $role) => $role->whereIn('code', $codes)))
            ->get();
    }

    /**
     * The one method in the application that writes `maintenance_requests.status`.
     *
     * Assert, write, log, audit — in that order and in one transaction — then
     * notify once the transaction has committed.
     *
     * @param  Closure(MaintenanceRequest): void|null  $apply
     * @param  array<string, mixed>  $auditPayload
     *
     * @throws IllegalTransitionException
     */
    private function move(
        ?User $actor,
        MaintenanceRequest $request,
        MaintenanceRequestStatus $to,
        ?string $comment = null,
        ?Closure $apply = null,
        ?AuditAction $auditAction = null,
        array $auditPayload = [],
        ?string $ipAddress = null,
        ?CarbonInterface $at = null,
    ): MaintenanceRequest {
        [$moved, $from] = DB::transaction(function () use (
            $actor, $request, $to, $comment, $apply, $auditAction, $auditPayload, $ipAddress, $at
        ): array {
            // §3.3.4: «load the request under SELECT … FOR UPDATE». Two
            // wardens with the same queue open reach this line together and
            // the second waits, which is what makes the assertion below mean
            // anything at all.
            $locked = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $from = $locked->status ?? MaintenanceRequestStatus::Submitted;

            $this->states->assert($from, $to);

            $locked->status = $to;

            if ($apply !== null) {
                $apply($locked);
            }

            // A refusal is an ending too, and the row says when it ended.
            // `closed_at` is not the `closed` status: it is the moment the
            // request stopped being anybody's work, and FR-40's queue reads it
            // to take a refused request out of the open list.
            if ($to === MaintenanceRequestStatus::Rejected) {
                $locked->closed_at = $at ?? now();
            }

            $locked->save();

            $this->log(
                request: $locked,
                actor: $actor,
                from: $from,
                to: $to,
                comment: $comment,
                at: $at,
            );

            if ($auditAction !== null) {
                $this->audit->record(
                    action: $auditAction,
                    actor: $actor,
                    subject: $locked,
                    payload: [
                        'building_id' => $locked->building_id,
                        'reporter_id' => $locked->reporter_id,
                        'from_status' => $from->value,
                        'to_status' => $to->value,
                    ] + $auditPayload,
                    ipAddress: $ipAddress,
                );
            }

            return [$locked, $from];
        });

        $this->notifyTheReporter($moved, $from, $to, $comment, $actor);

        return $moved;
    }

    /**
     * FR-38, fourth criterion: «the submitter is notified on every change».
     *
     * Every change except the ones the submitter made. A message telling
     * somebody what they have just pressed is an echo rather than a
     * notification, and FR-34's whole subject is which messages are worth a
     * person's attention; the movement is on their own screen either way. The
     * reopening, which is the reporter's own act, notifies the staff instead —
     * see `reopen()`.
     */
    private function notifyTheReporter(
        MaintenanceRequest $request,
        MaintenanceRequestStatus $from,
        MaintenanceRequestStatus $to,
        ?string $comment,
        ?User $actor,
    ): void {
        $reporter = $request->relationLoaded('reporter')
            ? $request->reporter
            : $request->reporter()->first();

        if ($reporter === null || $actor?->getKey() === $reporter->getKey()) {
            return;
        }

        $reporter->notify(new MaintenanceRequestStatusChanged(
            requestId: (int) $request->getKey(),
            fromStatus: $from->value,
            toStatus: $to->value,
            comment: $this->messageFor($request, $to, $comment),
        ));
    }

    /**
     * What the resident reads under the status line.
     *
     * The planned date is spelled into the acceptance message because the
     * Gherkin of §2.4.3 asks for it in so many words — «the resident receives
     * a notification containing the planned date» — and because a resident
     * told only «accepted» has been told nothing they can plan around.
     */
    private function messageFor(
        MaintenanceRequest $request,
        MaintenanceRequestStatus $to,
        ?string $comment,
    ): ?string {
        if ($to === MaintenanceRequestStatus::Accepted && $request->target_date !== null) {
            $planned = sprintf('Planned completion: %s.', $request->target_date->toDateString());

            return $comment === null || $comment === '' ? $planned : $planned.' '.$comment;
        }

        return $comment;
    }

    /**
     * §3.4.1, decision 6, in one place.
     */
    private function log(
        MaintenanceRequest $request,
        ?User $actor,
        ?MaintenanceRequestStatus $from,
        MaintenanceRequestStatus $to,
        ?string $comment,
        ?CarbonInterface $at = null,
    ): MaintenanceWorkLog {
        return MaintenanceWorkLog::query()->create([
            'maintenance_request_id' => $request->getKey(),
            'actor_id' => $actor?->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'comment' => $comment === null || trim($comment) === '' ? null : trim($comment),
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * @throws ConfirmationWindowClosedException
     */
    private function assertTheWindowIsOpen(MaintenanceRequest $request, CarbonImmutable $now): void
    {
        if ($request->status !== MaintenanceRequestStatus::Completed) {
            // Not a window question at all: the state machine will refuse the
            // move, and it gives the better message.
            return;
        }

        if ($request->confirmationWindowIsOpenAt($this->confirmationWindowDays, $now)) {
            return;
        }

        throw new ConfirmationWindowClosedException(
            requestId: (int) $request->getKey(),
            windowDays: $this->confirmationWindowDays,
            closedOn: $request->completed_at
                ?->copy()
                ->addDays($this->confirmationWindowDays)
                ->toDateString(),
        );
    }

    /**
     * FR-36: the room of the submitter's active residency record in this
     * dormitory, or null if the register knows of none.
     *
     * «Active» is `Residency::scopeCurrentOn`, the same question FR-05 asks
     * everywhere else: a termination recorded for a future date leaves the
     * person resident until that date arrives.
     */
    private function roomOfActiveResidency(User $reporter, Building $building): ?Room
    {
        $residency = Residency::query()
            ->where('user_id', $reporter->getKey())
            ->inBuilding($building)
            ->currentOn(CarbonImmutable::now())
            ->with('bed.room')
            ->first();

        return $residency?->bed?->room;
    }
}
