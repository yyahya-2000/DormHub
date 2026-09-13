<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Exceptions\InvalidPasswordTokenException;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * FR-42, the second half: the resident spends the one-time credential and puts
 * a password of their own in its place.
 *
 * The token store is the framework's `password_reset_tokens`, and using it
 * rather than a table of this project's own is a deliberate choice: the
 * hashing of the token, its single use and its expiry are three details that
 * are easy to write and easier to write wrongly, and they are already written
 * and already tested upstream. What this class adds is the part that is this
 * system's own — the column the password lives in is `password_hash` and not
 * the framework default, the flag of FR-42 is cleared, and the event reaches
 * the audit log of FR-33.
 *
 * Every token the account still holds is revoked with the change. A password
 * set is the moment the account changes hands — from nobody to the resident —
 * and a session issued before it has no claim to survive it.
 */
final readonly class PasswordSetup
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * @throws InvalidPasswordTokenException when the pair does not resolve
     */
    public function complete(
        string $email,
        string $token,
        string $password,
        ?string $ipAddress = null,
    ): void {
        $status = Password::reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $resident) use ($password, $ipAddress): void {
                // `forceFill` bypasses the fillable list and not the casts, so
                // the `hashed` cast on `password_hash` still hashes the value:
                // the plain text never reaches the table.
                $resident->forceFill([
                    'password_hash' => $password,
                    'password_change_required' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                $resident->tokens()->delete();

                $this->audit->record(
                    action: AuditAction::PasswordSet,
                    actor: $resident,
                    subject: $resident,
                    ipAddress: $ipAddress,
                );
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new InvalidPasswordTokenException;
        }
    }
}
