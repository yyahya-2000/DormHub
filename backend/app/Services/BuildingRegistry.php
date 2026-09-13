<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Exceptions\RegistryDeletionBlockedException;
use App\Models\Building;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * FR-01, «Register of dormitories and buildings»: the write side.
 *
 * Every method opens one transaction and writes the audit record inside it
 * (§3.9.6), so a change and its trace commit together or not at all.
 *
 * The delete deserves a paragraph. §4.4.1 places the rule in the database —
 * `ON DELETE RESTRICT` on `rooms.building_id` — rather than in a controller,
 * and this method honours that: it attempts the delete and reads the refusal,
 * instead of counting rooms first and deciding on its own. Counting first
 * would be a second, weaker copy of the rule that could drift from the real
 * one; the count is done only afterwards, to phrase the reason FR-01 asks to
 * be stated.
 */
final readonly class BuildingRegistry
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Every building the viewer may see. The administrator's grant names no
     * building and therefore covers all of them; every other role is confined
     * to the buildings its grants name (FR-07).
     *
     * @return Collection<int, Building>
     */
    public function visibleTo(User $viewer): Collection
    {
        $query = Building::query()->orderBy('name');

        if (! $viewer->isAdministrator()) {
            $query->whereIn('id', $viewer->scopedBuildingIds());
        }

        /** @var Collection<int, Building> $buildings */
        $buildings = $query->get();

        return $buildings;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes, ?string $ipAddress = null): Building
    {
        return DB::transaction(function () use ($actor, $attributes, $ipAddress): Building {
            $building = Building::query()->create($attributes);

            $this->audit->record(
                action: AuditAction::BuildingCreated,
                actor: $actor,
                subject: $building,
                payload: $attributes,
                ipAddress: $ipAddress,
            );

            return $building;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Building $building, array $attributes, ?string $ipAddress = null): Building
    {
        return DB::transaction(function () use ($actor, $building, $attributes, $ipAddress): Building {
            $building->fill($attributes);
            $changed = $building->getDirty();
            $building->save();

            $this->audit->record(
                action: AuditAction::BuildingUpdated,
                actor: $actor,
                subject: $building,
                payload: ['changed' => array_keys($changed)] + $changed,
                ipAddress: $ipAddress,
            );

            return $building;
        });
    }

    /**
     * Archiving, the third of FR-01's restricted operations. It is the answer
     * to a dormitory that has closed but whose residency history must stay
     * readable: the row survives, and `is_active` says it is out of use.
     */
    public function archive(User $actor, Building $building, ?string $ipAddress = null): Building
    {
        return DB::transaction(function () use ($actor, $building, $ipAddress): Building {
            $building->is_active = false;
            $building->save();

            $this->audit->record(
                action: AuditAction::BuildingArchived,
                actor: $actor,
                subject: $building,
                ipAddress: $ipAddress,
            );

            return $building;
        });
    }

    /**
     * @throws RegistryDeletionBlockedException when rooms are attached.
     */
    public function delete(User $actor, Building $building, ?string $ipAddress = null): void
    {
        $id = (int) $building->getKey();

        try {
            DB::transaction(function () use ($actor, $building, $ipAddress): void {
                // The audit record is written before the delete because it
                // references the row: once the row is gone the reference is
                // to nothing. Both are inside the same transaction, so a
                // refusal by the foreign key takes the record with it.
                $this->audit->record(
                    action: AuditAction::BuildingDeleted,
                    actor: $actor,
                    subject: $building,
                    payload: ['name' => $building->name],
                    ipAddress: $ipAddress,
                );

                $building->delete();
            });
        } catch (QueryException $exception) {
            if (! $this->isForeignKeyViolation($exception)) {
                throw $exception;
            }

            $rooms = $building->rooms()->count();

            $blocked = new RegistryDeletionBlockedException(
                entity: 'building',
                entityId: $id,
                dependants: ['rooms' => $rooms],
                reason: sprintf(
                    'This dormitory cannot be deleted: %d room(s) are attached to it. '
                    .'Move or delete the rooms first, or archive the dormitory instead.',
                    $rooms,
                ),
            );

            $this->audit->record(
                action: AuditAction::BuildingDeletionBlocked,
                actor: $actor,
                subject: $building,
                payload: $blocked->context(),
                result: AuditResult::Denied,
                ipAddress: $ipAddress,
            );

            throw $blocked;
        }
    }

    /**
     * SQLSTATE 23503 is «foreign key violation» and is spelled the same way by
     * PostgreSQL and by SQLite's driver, so the check does not branch on which
     * database is underneath.
     */
    private function isForeignKeyViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23503'
            || str_contains(strtolower($exception->getMessage()), 'foreign key');
    }
}
