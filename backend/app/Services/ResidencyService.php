<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Enums\BedStatus;
use App\Enums\ResidencyStatus;
use App\Exceptions\BedAlreadyOccupiedException;
use App\Exceptions\BedNotAssignableException;
use App\Exceptions\ResidentAlreadyAccommodatedException;
use App\Models\Bed;
use App\Models\Residency;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `ResidencyService::assign` of §3.3.6, and the eviction FR-05 pairs with it.
 *
 * **Where the rule lives.** FR-03 forbids two residents holding one bed over
 * overlapping periods, and this class does not enforce that. The database
 * does, through the exclusion constraints `residencies_bed_no_overlap` and
 * `residencies_user_no_overlap` (§3.4.1, decision 3, as restated over the
 * period). What the method does is insert, let the constraint refuse, and turn
 * the refusal into the answer FR-03 asks for — the conflicting record, shown
 * to the warden.
 *
 * The reason for that order is worth stating, because reading first and
 * inserting second looks tidier. Between the read and the insert another
 * request can commit, and the tidier version then writes a second occupant
 * into a bed it has just verified as empty. No amount of care inside the
 * application closes that window; a constraint closes it in the one place
 * where both requests meet.
 *
 * **Eviction keeps the row.** §3.4.1 makes residency historical: nothing is
 * deleted, `moved_out_at` and its ground are written, and the person's whole
 * occupancy history stays readable on the card of FR-06. What eviction does
 * *not* do is free the bed before the stated date. A termination recorded on
 * the 13th of September for the 31st of December leaves the bed occupied,
 * because somebody is sleeping in it until the 31st of December; the
 * projection onto `BED.status` and onto `RESIDENCY.status` is moved on the day
 * the termination takes effect, by `housing:settle-residencies`.
 */
final readonly class ResidencyService
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * FR-03: place a resident in a bed.
     *
     * @throws BedNotAssignableException the bed or its room is out of use
     * @throws BedAlreadyOccupiedException the bed is held (FR-03, criterion 1)
     * @throws ResidentAlreadyAccommodatedException the person holds another bed (§3.4.4)
     */
    public function assign(
        User $actor,
        User $resident,
        Bed $bed,
        string $contractNumber,
        CarbonInterface $movedInAt,
        ?string $ground = null,
        ?string $ipAddress = null,
    ): Residency {
        $bed->loadMissing('room');

        // The two states no index can express: a bed withdrawn from use, and
        // a room under repair. Both are refused before the insert, because
        // there is no constraint waiting to refuse them afterwards.
        if ($bed->status === BedStatus::Blocked) {
            throw BedNotAssignableException::bedBlocked($bed);
        }

        if ($bed->room !== null && ! $bed->room->acceptsResidents()) {
            throw BedNotAssignableException::roomOutOfService($bed);
        }

        try {
            return DB::transaction(function () use (
                $actor, $resident, $bed, $contractNumber, $movedInAt, $ground, $ipAddress
            ): Residency {
                $residency = Residency::query()->create([
                    'user_id' => $resident->getKey(),
                    'bed_id' => $bed->getKey(),
                    'contract_number' => $contractNumber,
                    'moved_in_at' => $movedInAt->toDateString(),
                    'moved_in_ground' => $ground,
                    'moved_out_at' => null,
                    'moved_out_ground' => null,
                    'status' => ResidencyStatus::Active,
                ]);

                // The projection of §3.4.1's decision onto `BED.status`,
                // written inside the same transaction as the row the index
                // guards, so the two cannot disagree.
                $bed->status = BedStatus::Occupied;
                $bed->save();

                $this->audit->record(
                    action: AuditAction::ResidencyAssigned,
                    actor: $actor,
                    subject: $residency,
                    payload: [
                        'resident_id' => $resident->getKey(),
                        'bed_id' => $bed->getKey(),
                        'room_id' => $bed->room_id,
                        'contract_number' => $contractNumber,
                        'moved_in_at' => $movedInAt->toDateString(),
                    ],
                    ipAddress: $ipAddress,
                );

                return $residency;
            });
        } catch (QueryException $exception) {
            throw $this->interpretConstraintViolation($exception, $actor, $resident, $bed, $movedInAt, $ipAddress);
        }
    }

    /**
     * FR-05: end a residency.
     *
     * The date may lie in the future, and everything awkward about this method
     * follows from that. «The bed becomes free automatically after eviction»
     * is the second criterion, and the word that decides when is *eviction*,
     * not *the paperwork*: the bed falls free when the person leaves, which is
     * the stated date. A notice given today for the 31st of December leaves
     * the record `active`, the bed `occupied` and the person resident, and all
     * three turn over on the 31st — by `housing:settle-residencies` if the
     * application is idle, or by this very method if the date has already
     * arrived when it is called.
     *
     * Writing them over at once, as this method used to, is what let a second
     * resident be moved into an occupied bed and what made a living person's
     * record read `ended`. The exclusion constraint of FR-03 would now refuse
     * the double booking regardless; the projections are corrected here so
     * that what the register *says* and what it *enforces* are the same thing.
     */
    public function terminate(
        User $actor,
        Residency $residency,
        string $ground,
        CarbonInterface $movedOutAt,
        ?string $ipAddress = null,
    ): Residency {
        try {
            return $this->write($actor, $residency, $ground, $movedOutAt, $ipAddress);
        } catch (QueryException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'residencies_bed_no_overlap')) {
                throw $exception;
            }

            /*
             * Moving a departure date **forward** over a period the place has
             * since been given to somebody else. The row shrinks in every
             * other case and a shrinking range conflicts with nothing, so this
             * is the one shape of termination the constraint can refuse — and
             * it is a real one: a resident asks to stay another month, and the
             * month is already promised.
             */
            $successor = Residency::query()
                ->where('bed_id', $residency->bed_id)
                ->whereKeyNot($residency->getKey())
                ->overlapping($residency->moved_in_at ?? now(), $movedOutAt)
                ->orderBy('moved_in_at')
                ->with(['user', 'bed.room'])
                ->first();

            throw new BedAlreadyOccupiedException((int) $residency->bed_id, $successor);
        }
    }

    /**
     * @throws QueryException when the new period overlaps another residency.
     */
    private function write(
        User $actor,
        Residency $residency,
        string $ground,
        CarbonInterface $movedOutAt,
        ?string $ipAddress,
    ): Residency {
        return DB::transaction(function () use ($actor, $residency, $ground, $movedOutAt, $ipAddress): Residency {
            $residency->moved_out_at = $movedOutAt->toDateString();
            $residency->moved_out_ground = $ground;
            $residency->status = $residency->isCurrentOn(now())
                ? ResidencyStatus::Active
                : ResidencyStatus::Ended;
            $residency->save();

            if (! $residency->isCurrentOn(now())) {
                $this->releaseBed($residency);
            }

            $this->audit->record(
                action: AuditAction::ResidencyTerminated,
                actor: $actor,
                subject: $residency,
                payload: [
                    'resident_id' => $residency->user_id,
                    'bed_id' => $residency->bed_id,
                    'ground' => $ground,
                    'moved_out_at' => $movedOutAt->toDateString(),
                    'takes_effect' => $residency->isCurrentOn(now()) ? 'on the stated date' : 'immediately',
                ],
                ipAddress: $ipAddress,
            );

            return $residency;
        });
    }

    /**
     * Brings the two projections of a residency into line with the calendar:
     * a record whose stated date has arrived becomes `ended`, and its bed
     * becomes free.
     *
     * This is the same work `terminate()` does when the date is today, and it
     * is separate because somebody has to do it on the day a future-dated
     * termination comes due, when no request is being served. The command
     * `housing:settle-residencies` calls it nightly.
     *
     * @return bool whether anything was changed.
     */
    public function settle(Residency $residency): bool
    {
        if ($residency->moved_out_at === null || $residency->isCurrentOn(now())) {
            return false;
        }

        return DB::transaction(function () use ($residency): bool {
            $changed = false;

            if ($residency->status !== ResidencyStatus::Ended) {
                $residency->status = ResidencyStatus::Ended;
                $residency->save();
                $changed = true;
            }

            return $this->releaseBed($residency) || $changed;
        });
    }

    /**
     * The projection of the vacated place onto `BED.status`.
     *
     * A blocked bed stays blocked: eviction frees a bed that was occupied, and
     * says nothing about one withdrawn from use. A bed still held by another
     * current residency stays occupied too — a room transfer recorded as two
     * rows on one bed cannot be, but a mistaken history repaired by hand can,
     * and the status must describe the bed rather than the last row read.
     */
    private function releaseBed(Residency $residency): bool
    {
        $bed = $residency->bed()->first();

        if ($bed === null || $bed->status !== BedStatus::Occupied) {
            return false;
        }

        $stillHeld = Residency::query()
            ->where('bed_id', $bed->getKey())
            ->whereKeyNot($residency->getKey())
            ->currentOn(now())
            ->exists();

        if ($stillHeld) {
            return false;
        }

        $bed->status = BedStatus::Free;
        $bed->save();

        return true;
    }

    /**
     * Turns the database's refusal into the answer the criterion asks for.
     *
     * Which constraint was violated decides which of the two rules was broken,
     * so the name of the constraint is read rather than guessed from the
     * payload. If the violation is neither of them, the original exception
     * travels on untouched: swallowing an unexplained database error would
     * hide a defect behind a domain message.
     *
     * The conflicting record is then looked up **by overlap with the period
     * being asked for**, not by «is it open». The two used to be the same
     * query, which is why a refusal could once come back with no conflicting
     * record attached at all: the row in the way had a termination date in the
     * future, so it was not open, so the lookup found nothing and the warden
     * was told only that something had gone wrong.
     */
    private function interpretConstraintViolation(
        QueryException $exception,
        User $actor,
        User $resident,
        Bed $bed,
        CarbonInterface $movedInAt,
        ?string $ipAddress,
    ): QueryException|BedAlreadyOccupiedException|ResidentAlreadyAccommodatedException {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'residencies_bed_no_overlap')) {
            $conflicting = Residency::query()
                ->where('bed_id', $bed->getKey())
                ->overlapping($movedInAt)
                ->orderBy('moved_in_at')
                ->with(['user', 'bed.room'])
                ->first();

            $refusal = new BedAlreadyOccupiedException((int) $bed->getKey(), $conflicting);
            $this->recordRefusal($actor, $resident, $bed, $refusal->getMessage(), $conflicting, $ipAddress);

            return $refusal;
        }

        if (str_contains($message, 'residencies_user_no_overlap')) {
            $conflicting = Residency::query()
                ->where('user_id', $resident->getKey())
                ->overlapping($movedInAt)
                ->orderBy('moved_in_at')
                ->with(['user', 'bed.room'])
                ->first();

            $refusal = new ResidentAlreadyAccommodatedException((int) $resident->getKey(), $conflicting);
            $this->recordRefusal($actor, $resident, $bed, $refusal->getMessage(), $conflicting, $ipAddress);

            return $refusal;
        }

        return $exception;
    }

    private function recordRefusal(
        User $actor,
        User $resident,
        Bed $bed,
        string $reason,
        ?Residency $conflicting,
        ?string $ipAddress,
    ): void {
        $this->audit->record(
            action: AuditAction::ResidencyAssignmentRefused,
            actor: $actor,
            subject: $bed,
            payload: [
                'resident_id' => $resident->getKey(),
                'bed_id' => $bed->getKey(),
                'reason' => $reason,
                'conflicting_residency_id' => $conflicting?->getKey(),
            ],
            result: AuditResult::Denied,
            ipAddress: $ipAddress,
        );
    }
}
