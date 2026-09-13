<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Enums\UserStatus;
use App\Exceptions\CredentialAlreadySpentException;
use App\Exceptions\ResidentOfAnotherDormitoryException;
use App\Jobs\DeliverResidentCredential;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-42, «Issuing a resident account».
 *
 * Four decisions are worth reading before the methods.
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
 * **The credential is handed over outside the transaction.** A queued delivery
 * dispatched inside one can reach a worker before the commit and find no such
 * user; the account is committed first, and the credential is raised afterwards
 * on a row that certainly exists. The audit entry that says a credential was
 * handed over is written **after** the dispatch returns, for the reason stated
 * on `AuditAction::ResidentCredentialIssued`: a log that records a delivery the
 * queue refused is worse than a log that records nothing.
 *
 * **A lost code is not a lost account (acceptance of 14.09.2026).** The code
 * lives an hour. Before `reissue()` existed, an account whose code expired
 * unspent could not be signed into, could not be given a second code, could not
 * be deleted, and could not be created again — `users.email` is unique, so the
 * address was held by a row nobody could use. One road in, an hour wide, and no
 * way back. `reissue()` is that way back, and the two conditions on it are the
 * whole of its security:
 *
 * - the account must still be waiting for its first password. Re-issuing to an
 *   account that has one would be a password reset performed by a member of
 *   staff, which is a far larger power than FR-42 grants and would let a
 *   manager take over any resident of his building. Password recovery for an
 *   account in use is a different requirement, and it is not in this MVP;
 * - the account must hold the resident grant of **this** dormitory, so the
 *   route obeys the same horizontal boundary as every other.
 *
 * The old code does not have to be revoked by hand: the token repository keeps
 * one token per account and replaces it, so issuing a second code is what
 * stops the first. The event reaches the audit log with `reissue: true`, which
 * is what lets an administrator see that an account was sent three codes in a
 * morning and by whom.
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
                ],
                ipAddress: $ipAddress,
            );

            return $resident;
        });

        $this->deliver($actor, $resident, $building, reissue: false, ipAddress: $ipAddress);

        return $resident->load('roleGrants.role');
    }

    /**
     * Send a second one-time code to an account that never spent its first.
     *
     * @throws ResidentOfAnotherDormitoryException when the account is not a resident here
     * @throws CredentialAlreadySpentException when the account already has a password
     */
    public function reissue(
        User $actor,
        User $resident,
        Building $building,
        ?string $ipAddress = null,
    ): void {
        if (! $resident->hasRoleInBuilding(RoleCode::Resident, $building)) {
            throw new ResidentOfAnotherDormitoryException;
        }

        if (! $resident->password_change_required) {
            throw new CredentialAlreadySpentException;
        }

        $this->deliver($actor, $resident, $building, reissue: true, ipAddress: $ipAddress);
    }

    /**
     * Hand the credential to the queue, then record that it was handed over.
     *
     * The order is the point. Nothing about a credential is written to the log
     * until the dispatch has returned, so a queue that is down leaves the log
     * silent rather than mistaken.
     */
    private function deliver(
        User $actor,
        User $resident,
        Building $building,
        bool $reissue,
        ?string $ipAddress,
    ): void {
        // `Bus::dispatch` rather than the job's own `dispatch()` helper: the
        // helper hands back a pending object that reaches the queue when it is
        // destroyed, and this method needs the dispatch to have happened — or
        // to have failed — before the next line writes that it did.
        Bus::dispatch(new DeliverResidentCredential($resident, $building));

        $this->audit->record(
            action: AuditAction::ResidentCredentialIssued,
            actor: $actor,
            subject: $resident,
            payload: [
                'building_id' => $building->getKey(),
                // What the credential was queued for, never the credential.
                'delivered_to' => 'email',
                'reissue' => $reissue,
            ],
            ipAddress: $ipAddress,
        );
    }
}
