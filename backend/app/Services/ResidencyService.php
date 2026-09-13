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
 * does, through `residencies_active_bed_uniq` (§3.4.1, decision 3). What the
 * method does is insert, let the index refuse, and turn the refusal into the
 * answer FR-03 asks for — the conflicting record, shown to the warden.
 *
 * The reason for that order is worth stating, because reading first and
 * inserting second looks tidier. Between the read and the insert another
 * request can commit, and the tidier version then writes a second occupant
 * into a bed it has just verified as empty. No amount of care inside the
 * application closes that window; a unique index closes it in the one place
 * where both requests meet.
 *
 * **Eviction keeps the row.** §3.4.1 makes residency historical: nothing is
 * deleted, `moved_out_at` and its ground are written, the bed's status goes
 * back to free in the same transaction, and the person's whole occupancy
 * history stays readable on the card of FR-06.
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
            throw $this->interpretUniqueViolation($exception, $actor, $resident, $bed, $ipAddress);
        }
    }

    /**
     * FR-05: end a residency.
     *
     * The date may lie in the future. The bed is freed at once — «the bed
     * becomes free automatically after eviction» — while the person's access
     * to building-bound functions runs until the stated date and not past it,
     * which is the third criterion. The two facts are read from the same
     * column by two different questions (see `Residency`).
     */
    public function terminate(
        User $actor,
        Residency $residency,
        string $ground,
        CarbonInterface $movedOutAt,
        ?string $ipAddress = null,
    ): Residency {
        return DB::transaction(function () use ($actor, $residency, $ground, $movedOutAt, $ipAddress): Residency {
            $residency->moved_out_at = $movedOutAt->toDateString();
            $residency->moved_out_ground = $ground;
            $residency->status = ResidencyStatus::Ended;
            $residency->save();

            $bed = $residency->bed()->first();

            // A blocked bed stays blocked: eviction frees a bed that was
            // occupied, and says nothing about one withdrawn from use.
            if ($bed !== null && $bed->status === BedStatus::Occupied) {
                $bed->status = BedStatus::Free;
                $bed->save();
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
                ],
                ipAddress: $ipAddress,
            );

            return $residency;
        });
    }

    /**
     * Turns the database's refusal into the answer the criterion asks for.
     *
     * Which index was violated decides which of the two rules was broken, so
     * the name of the index is read rather than guessed from the payload. If
     * the violation is neither of them, the original exception travels on
     * untouched: swallowing an unexplained database error would hide a defect
     * behind a domain message.
     */
    private function interpretUniqueViolation(
        QueryException $exception,
        User $actor,
        User $resident,
        Bed $bed,
        ?string $ipAddress,
    ): QueryException|BedAlreadyOccupiedException|ResidentAlreadyAccommodatedException {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'residencies_active_bed_uniq')) {
            $conflicting = Residency::query()
                ->where('bed_id', $bed->getKey())
                ->open()
                ->with(['user', 'bed.room'])
                ->first();

            $refusal = new BedAlreadyOccupiedException((int) $bed->getKey(), $conflicting);
            $this->recordRefusal($actor, $resident, $bed, $refusal->getMessage(), $conflicting, $ipAddress);

            return $refusal;
        }

        if (str_contains($message, 'residencies_active_user_uniq')) {
            $conflicting = Residency::query()
                ->where('user_id', $resident->getKey())
                ->open()
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
