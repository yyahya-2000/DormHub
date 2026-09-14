<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-42, the second half: the resident replaces the password the office gave
 * them with one of their own.
 *
 * There is no token store and no one-time code any more. The account is born
 * with a generated password that is printed and handed over, so the person
 * changing it is the person already signed in with it — the form request
 * checks the old password, and this class writes the new one.
 *
 * Every token the account still holds is revoked with the change, the current
 * session included. A password change is the moment the account stops
 * answering to whatever came before it, and a session issued earlier has no
 * claim to survive it.
 */
final readonly class PasswordSetup
{
    public function __construct(private AuditRecorder $audit) {}

    public function change(User $user, string $password, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($user, $password, $ipAddress): void {
            // `forceFill` bypasses the fillable list and not the casts, so the
            // `hashed` cast on `password_hash` still hashes the value: the
            // plain text never reaches the table.
            $user->forceFill([
                'password_hash' => $password,
                'password_change_required' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            // The event and never the value. Nothing about what was set
            // reaches the log.
            $this->audit->record(
                action: AuditAction::PasswordSet,
                actor: $user,
                subject: $user,
                ipAddress: $ipAddress,
            );
        });
    }
}
