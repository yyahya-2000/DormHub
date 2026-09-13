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
use Illuminate\Validation\ValidationException;

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
 *
 * Two foreign keys point at `buildings`, not one — `rooms.building_id` and
 * `role_user.building_id` — and the reason has to say which of them refused.
 * `explainRefusal()` reads the constraint out of the error instead of assuming
 * the first.
 */
final readonly class BuildingRegistry
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Every building the viewer may see. The administrator's grant names no
     * building and therefore covers all of them; every other role is confined
     * to the buildings its grants name (FR-07).
     *
     * The grant is where the list starts and not where it ends. A resident
     * whose residency the register has closed keeps the grant — nothing
     * revokes it on eviction — so a listing filtered on grants alone handed
     * them a row whose card answers 403. The second pass asks the policy the
     * card route asks, which is the only way the two can be made to agree by
     * construction rather than by both being written correctly twice. It costs
     * a couple of queries per building and the list is of dormitories, not of
     * rooms.
     *
     * @return Collection<int, Building>
     */
    public function visibleTo(User $viewer): Collection
    {
        $query = Building::query()->orderBy('name');

        if ($viewer->isAdministrator()) {
            /** @var Collection<int, Building> $all */
            $all = $query->get();

            return $all;
        }

        /** @var Collection<int, Building> $buildings */
        $buildings = $query
            ->whereIn('id', $viewer->scopedBuildingIds())
            ->get()
            ->filter(fn (Building $building): bool => $viewer->can('view', $building))
            ->values();

        return $buildings;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes, ?string $ipAddress = null): Building
    {
        try {
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
        } catch (QueryException $exception) {
            throw $this->interpretDuplicateName($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Building $building, array $attributes, ?string $ipAddress = null): Building
    {
        try {
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
        } catch (QueryException $exception) {
            throw $this->interpretDuplicateName($exception);
        }
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
     * @throws RegistryDeletionBlockedException when anything is attached.
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

            $blocked = $this->explainRefusal($building, $id, $exception);

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

    /**
     * Names the real reason the delete was refused.
     *
     * Two foreign keys point at `buildings`, and the refusal used to be
     * reported as the first of them whichever had actually fired. A dormitory
     * with no rooms but with a staff appointment on it was turned away with
     * «0 room(s) are attached to it» — a sentence that contradicts itself and
     * sends the reader to look at an empty register.
     *
     * PostgreSQL names the constraint in the error text, so the constraint is
     * read rather than guessed. Both counts travel in `blocked_by` regardless,
     * because a body that shows one number and a message about another is how
     * the confusion started.
     *
     * The advice is kept to what the API can actually carry out. Revoking a
     * staff grant has a route (`DELETE /buildings/{id}/staff/{user}/{role}`)
     * and is offered; deleting or moving a room has none, so the reader is
     * pointed at archiving, which does. Telling somebody to do a thing the
     * system does not let them do is a worse answer than telling them less.
     */
    private function explainRefusal(
        Building $building,
        int $id,
        QueryException $exception,
    ): RegistryDeletionBlockedException {
        $dependants = [
            'rooms' => $building->rooms()->count(),
            'role_grants' => $building->roleGrants()->count(),
        ];

        $message = strtolower($exception->getMessage());

        $byRoleGrants = str_contains($message, 'role_user')
            || ($dependants['rooms'] === 0 && $dependants['role_grants'] > 0);

        $reason = $byRoleGrants
            ? sprintf(
                'This dormitory cannot be deleted: %d staff role grant(s) name it. '
                .'Revoke them first, or archive the dormitory instead.',
                $dependants['role_grants'],
            )
            : sprintf(
                'This dormitory cannot be deleted: %d room(s) are attached to it. '
                .'Archive the dormitory instead — its rooms and their residency history stay readable.',
                $dependants['rooms'],
            );

        return new RegistryDeletionBlockedException(
            entity: 'building',
            entityId: $id,
            dependants: $dependants,
            reason: $reason,
        );
    }

    /**
     * The unique index on `buildings.name` refusing a second dormitory of the
     * same name.
     *
     * `StoreBuildingRequest` checks the same thing and normally gets there
     * first; this is the window between that check and the insert, which is
     * exactly the window the index exists to close. The refusal is shaped as a
     * field error rather than as a new kind of failure, so a client that
     * already handles the validation error handles this one too and never
     * learns that two mechanisms were involved.
     */
    private function interpretDuplicateName(QueryException $exception): QueryException|ValidationException
    {
        $duplicate = ($exception->errorInfo[0] ?? null) === '23505'
            || str_contains(strtolower($exception->getMessage()), 'unique');

        if (! $duplicate || ! str_contains(strtolower($exception->getMessage()), 'buildings_name_unique')) {
            return $exception;
        }

        return ValidationException::withMessages([
            'name' => 'A dormitory of this name is already in the register.',
        ]);
    }
}
