<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Enums\BedStatus;
use App\Exceptions\CapacityExceededException;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FR-02, «Register of rooms and beds»: the write side.
 *
 * One rule runs through the whole class. **The capacity of a room is the
 * number of places it may hold, and a place exists once a bed is registered**,
 * so the criterion «the number of occupied beds never exceeds the capacity» is
 * met by refusing the bed that would break it, not by counting residencies
 * afterwards. A bed that cannot exist cannot be occupied.
 *
 * The counting happens under `SELECT … FOR UPDATE` on the room. Two wardens
 * adding the last bed of a room at the same instant would otherwise both read
 * «one place free» and both write, and the room would end up over capacity
 * with neither request having done anything wrong. The lock is what makes the
 * check-then-insert pair safe here, in contrast with the residency case, where
 * an exclusion constraint does the same work without a lock (§3.4.1).
 *
 * **Where the refusal is recorded.** §3.9.6 counts a refusal among the events
 * the log must hold, and a refusal raised inside `DB::transaction` is not one
 * of them: the exception rolls the transaction back and takes the record with
 * it. Both refusals below are therefore written from a `catch` **outside** the
 * transaction, as `ResidencyService::recordRefusal` has always done. Until
 * this was fixed, `bed.creation_refused` was declared in the enumeration and
 * in the contract and could not be reached by any request, and the refusal to
 * lower a capacity was recorded nowhere at all.
 */
final readonly class RoomRegistry
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRoom(User $actor, Building $building, array $attributes, ?string $ipAddress = null): Room
    {
        return DB::transaction(function () use ($actor, $building, $attributes, $ipAddress): Room {
            $room = $building->rooms()->create($attributes);

            $this->audit->record(
                action: AuditAction::RoomCreated,
                actor: $actor,
                subject: $room,
                payload: ['building_id' => $building->getKey()] + $attributes,
                ipAddress: $ipAddress,
            );

            return $room;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CapacityExceededException when the new capacity is below the
     *                                   beds already registered.
     */
    public function updateRoom(User $actor, Room $room, array $attributes, ?string $ipAddress = null): Room
    {
        try {
            return DB::transaction(function () use ($actor, $room, $attributes, $ipAddress): Room {
                $locked = Room::query()->lockForUpdate()->findOrFail($room->getKey());

                $locked->fill($attributes);

                // Lowering the capacity below the places already registered is
                // the same violation approached from the other side, and it is
                // refused with the same message.
                if ($locked->capacity < $locked->bedsCount()) {
                    throw new CapacityExceededException(
                        roomId: (int) $locked->getKey(),
                        roomNumber: (string) $locked->number,
                        capacity: $locked->capacity,
                        beds: $locked->bedsCount(),
                        freePlaces: 0,
                    );
                }

                $changed = $locked->getDirty();
                $locked->save();

                $this->audit->record(
                    action: AuditAction::RoomUpdated,
                    actor: $actor,
                    subject: $locked,
                    payload: ['changed' => array_keys($changed)] + $changed,
                    ipAddress: $ipAddress,
                );

                return $locked;
            });
        } catch (CapacityExceededException $refusal) {
            $this->recordRefusal(
                action: AuditAction::RoomUpdateRefused,
                actor: $actor,
                room: $room,
                payload: ['requested_capacity' => $attributes['capacity'] ?? null] + $refusal->context(),
                ipAddress: $ipAddress,
            );

            throw $refusal;
        }
    }

    /**
     * FR-02's second criterion in one method: the attempt that would take the
     * room past its capacity is rejected, and the rejection names the free
     * remainder.
     *
     * @throws CapacityExceededException
     */
    public function addBed(User $actor, Room $room, string $label, ?string $ipAddress = null): Bed
    {
        try {
            return DB::transaction(function () use ($actor, $room, $label, $ipAddress): Bed {
                $locked = Room::query()->lockForUpdate()->findOrFail($room->getKey());

                $freeBefore = $locked->freePlaces();

                if ($freeBefore < 1) {
                    throw CapacityExceededException::forRoom($locked);
                }

                $bed = $locked->beds()->create([
                    'label' => $label,
                    'status' => BedStatus::Free,
                ]);

                $this->audit->record(
                    action: AuditAction::BedCreated,
                    actor: $actor,
                    subject: $bed,
                    payload: [
                        'room_id' => $locked->getKey(),
                        'label' => $label,
                        'free_places' => $freeBefore - 1,
                    ],
                    ipAddress: $ipAddress,
                );

                return $bed;
            });
        } catch (CapacityExceededException $refusal) {
            $this->recordRefusal(
                action: AuditAction::BedCreationRefused,
                actor: $actor,
                room: $room,
                payload: ['label' => $label] + $refusal->context(),
                ipAddress: $ipAddress,
            );

            throw $refusal;
        }
    }

    /**
     * The refusal, recorded after the transaction that carried it has rolled
     * back.
     *
     * The subject is the room rather than the bed, because the bed is the
     * thing that was not created: there is no row to point at, and the room is
     * where somebody reading the log will look.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordRefusal(
        AuditAction $action,
        User $actor,
        Room $room,
        array $payload,
        ?string $ipAddress,
    ): void {
        $this->audit->record(
            action: $action,
            actor: $actor,
            subject: $room,
            payload: $payload,
            result: AuditResult::Denied,
            ipAddress: $ipAddress,
        );
    }
}
