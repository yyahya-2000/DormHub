<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FR-41, «Staff appointment inside a building»: the write side.
 *
 * **What this class does not decide.** Whether the actor may hand out this
 * role in this building is settled before the request arrives here, by
 * `BuildingPolicy::appointStaff` over `RoleCode::grantableRoles()`. Repeating
 * the rule in the service would give the system two copies of it, and a second
 * copy of an authorisation rule is a second chance to get it wrong. What the
 * service guarantees instead is that the grant it writes names the building it
 * was told, and never a building the payload could have nominated.
 *
 * **Where the last defence sits.** The grant of a role other than the
 * administrator's must name a building, and that is a CHECK constraint on
 * `role_user` (the migration of §4.4.2 that constrains the scope of a grant),
 * not a line in this method. A route that forgot to pass one would be refused
 * by the database rather than quietly create an account with power over every
 * dormitory.
 *
 * Both methods write the audit record inside the transaction of the change
 * (§3.9.6), so an appointment and its trace commit together or not at all.
 */
final readonly class StaffRegistry
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Grant the role inside the building.
     *
     * Repeating the call is not an error and is not a second event: the
     * partial unique index on `(user_id, role_id, building_id)` says a grant
     * exists once, and the log holds one entry per grant, as FR-41's fourth
     * criterion asks. Which of the two happened is read off the returned
     * record's `wasRecentlyCreated`, so the protocol layer answers 201 or 200
     * without a second query and without an output parameter.
     */
    public function appoint(
        User $actor,
        User $subject,
        RoleCode $code,
        Building $building,
        ?string $ipAddress = null,
    ): RoleUser {
        $role = Role::query()->where('code', $code->value)->sole();

        $existing = RoleUser::query()
            ->where('user_id', $subject->getKey())
            ->where('role_id', $role->getKey())
            ->where('building_id', $building->getKey())
            ->with('role')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($actor, $subject, $code, $role, $building, $ipAddress): RoleUser {
            $grant = RoleUser::query()->create([
                'user_id' => $subject->getKey(),
                'role_id' => $role->getKey(),
                'building_id' => $building->getKey(),
                'granted_by' => $actor->getKey(),
                'granted_at' => now(),
            ]);

            $this->audit->record(
                action: AuditAction::StaffAppointed,
                actor: $actor,
                subject: $subject,
                payload: [
                    'role' => $code->value,
                    'building_id' => $building->getKey(),
                    'grant_id' => $grant->getKey(),
                ],
                ipAddress: $ipAddress,
            );

            return $grant->load('role');
        });
    }

    /**
     * Take the role back. Returns false when there was nothing to take, which
     * the protocol layer answers with 404: a revocation that revoked nothing
     * must not read as a success.
     */
    public function revoke(
        User $actor,
        User $subject,
        RoleCode $code,
        Building $building,
        ?string $ipAddress = null,
    ): bool {
        $role = Role::query()->where('code', $code->value)->sole();

        $grant = RoleUser::query()
            ->where('user_id', $subject->getKey())
            ->where('role_id', $role->getKey())
            ->where('building_id', $building->getKey())
            ->first();

        if ($grant === null) {
            return false;
        }

        DB::transaction(function () use ($actor, $subject, $code, $building, $grant, $ipAddress): void {
            // The record is written before the delete because it names the
            // row; inside the same transaction, so a refusal takes both.
            $this->audit->record(
                action: AuditAction::StaffRevoked,
                actor: $actor,
                subject: $subject,
                payload: [
                    'role' => $code->value,
                    'building_id' => $building->getKey(),
                    'grant_id' => $grant->getKey(),
                ],
                ipAddress: $ipAddress,
            );

            $grant->delete();
        });

        return true;
    }
}
