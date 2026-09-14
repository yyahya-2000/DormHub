<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Enums\UserStatus;
use App\Identity\IssuedAccount;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-42, «Issuing a resident account».
 *
 * **The password is generated here and handed over on paper.** The MVP sends
 * nothing: there is no mail server to depend on and no one-time code to expire
 * while a resident is in transit. The server mints a password, stores its hash,
 * marks the account as owing a change at first sign-in, and returns the plain
 * text **once**, in the answer to the request that created the account. The
 * office prints it and gives it to the person it belongs to.
 *
 * That plain text must reach exactly one place — the body of that one
 * response. It is not queued, because a queue serialises what it is given and
 * keeps it after a failure; it is not notified, because a notification is
 * stored; and it is not audited, because the log is read by people who have no
 * business signing in as a resident. The audit entry says an account was
 * issued and by whom, which is the fact FR-33 wants, and nothing about what
 * was written into `password_hash`.
 *
 * **The role is fixed, not chosen.** The grant this method writes is always
 * the resident role, always scoped to the building the route named. FR-42's
 * second criterion says no staff role may be issued this way, and the reason
 * it cannot is that there is no parameter through which one could be asked
 * for. Appointing staff is a different route with a different gate (FR-41).
 */
final readonly class ResidentAccountIssuer
{
    /**
     * Long enough not to be guessed, short enough to be read off a printed
     * sheet and typed by hand. Letters and digits only, for the same reason.
     */
    private const PASSWORD_LENGTH = 12;

    public function __construct(private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  name, contact, citizenship
     */
    public function issue(
        User $actor,
        Building $building,
        array $attributes,
        ?string $ipAddress = null,
    ): IssuedAccount {
        $password = Str::password(self::PASSWORD_LENGTH, symbols: false);

        $resident = DB::transaction(function () use ($actor, $building, $attributes, $password, $ipAddress): User {
            // The `hashed` cast on `password_hash` hashes on the way in, so
            // the plain text never reaches the table.
            $resident = User::query()->create($attributes + [
                'password_hash' => $password,
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
                ],
                ipAddress: $ipAddress,
            );

            return $resident;
        });

        return new IssuedAccount($resident->load('roleGrants.role'), $building, $password);
    }
}
