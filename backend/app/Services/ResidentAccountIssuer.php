<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Enums\UserStatus;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ResidentAccountIssued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * FR-42, «Issuing a resident account».
 *
 * Three decisions are worth reading before the method.
 *
 * **The account is born without a usable password.** The secret written into
 * `password_hash` is generated, hashed and discarded inside one expression: no
 * variable outside this method holds it, no response carries it, and no log
 * line records it. It exists solely so the column is not null. The account is
 * unusable until the resident sets a password through the one-time token, and
 * that is a property of the data rather than of a check somebody could forget.
 *
 * **The role is fixed, not chosen.** The grant this method writes is always
 * the resident role, always scoped to the building the route named. FR-42's
 * second criterion says no staff role may be issued this way, and the reason
 * it cannot is that there is no parameter through which one could be asked
 * for. Appointing staff is a different route with a different gate (FR-41).
 *
 * **The token is created and sent outside the transaction.** A queued
 * notification dispatched inside one can reach a worker before the commit and
 * find no such user; the account is committed first, and the credential is
 * raised afterwards on a row that certainly exists.
 */
final readonly class ResidentAccountIssuer
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  name, contact, study status, citizenship
     */
    public function issue(
        User $actor,
        Building $building,
        array $attributes,
        ?string $ipAddress = null,
    ): User {
        $resident = DB::transaction(function () use ($actor, $building, $attributes, $ipAddress): User {
            $resident = User::query()->create($attributes + [
                'password_hash' => Str::password(48),
                'password_change_required' => true,
                'status' => UserStatus::Active,
            ]);

            $role = Role::query()->where('code', RoleCode::Resident->value)->sole();

            $resident->roleGrants()->create([
                'role_id' => $role->getKey(),
                'building_id' => $building->getKey(),
                'granted_by' => $actor->getKey(),
                'granted_at' => now(),
            ]);

            $this->audit->record(
                action: AuditAction::ResidentAccountIssued,
                actor: $actor,
                subject: $resident,
                payload: [
                    'building_id' => $building->getKey(),
                    'role' => RoleCode::Resident->value,
                    // What the credential was sent to, never the credential.
                    'delivered_to' => 'email',
                ],
                ipAddress: $ipAddress,
            );

            return $resident;
        });

        $resident->notify(new ResidentAccountIssued(
            token: Password::createToken($resident),
            buildingName: (string) $building->name,
            expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
        ));

        return $resident->load('roleGrants.role');
    }
}
