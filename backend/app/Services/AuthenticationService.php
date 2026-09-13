<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\IdentityProvider;
use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\LoginLockedException;
use App\Identity\Credentials;
use App\Identity\IssuedToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-08. Owns the session lifecycle: sign-in, the attempt limit and the block
 * that follows it, sign-out.
 *
 * Layering (§3.3.1 and §3.3.4). The service knows nothing about HTTP: it
 * receives a value object and an address, and it signals refusal by raising a
 * domain exception that the interface layer maps to a status code. Who
 * verifies the credential is behind the IdentityProvider interface, so the
 * university SSO enters as a second implementation rather than as a branch
 * inside this class.
 *
 * Every audit record is written in the same transaction as the change it
 * describes (§3.9.6): the record of a successful sign-in commits together
 * with the token row it refers to, and the record of a sign-out with the
 * deletion of that token.
 */
final readonly class AuthenticationService
{
    public function __construct(
        private IdentityProvider $identityProvider,
        private LoginThrottle $throttle,
        private AuditRecorder $audit,
        private string $tokenName,
        private ?int $tokenTtlMinutes,
    ) {}

    /**
     * @throws LoginLockedException when the login is inside its block window
     * @throws InvalidCredentialsException when the pair does not resolve to an account that may sign in
     */
    public function signIn(Credentials $credentials, ?string $ipAddress = null): IssuedToken
    {
        $login = $credentials->login;

        if ($this->throttle->isLocked($login, $ipAddress)) {
            $remaining = $this->throttle->secondsUntilUnlocked($login, $ipAddress);

            $this->audit->record(
                action: AuditAction::LoginBlocked,
                payload: ['login' => $login, 'seconds_remaining' => $remaining],
                result: AuditResult::Denied,
                ipAddress: $ipAddress,
            );

            throw new LoginLockedException($remaining);
        }

        $user = $this->identityProvider->authenticate($credentials);

        if ($user === null || ! $user->isActive()) {
            throw $this->refuse($login, $user, $ipAddress);
        }

        $this->throttle->clear($login, $ipAddress);

        return DB::transaction(function () use ($user, $ipAddress): IssuedToken {
            $expiresAt = $this->tokenTtlMinutes !== null
                ? Carbon::now()->addMinutes($this->tokenTtlMinutes)
                : null;

            $token = $user->createToken($this->tokenName, ['*'], $expiresAt);

            $this->audit->record(
                action: AuditAction::LoginSucceeded,
                actor: $user,
                subject: $user,
                payload: ['provider' => $this->identityProvider->name()],
                ipAddress: $ipAddress,
            );

            return new IssuedToken(
                user: $user,
                plainTextToken: $token->plainTextToken,
                expiresAt: $expiresAt?->toDateTimeImmutable(),
                provider: $this->identityProvider->name(),
            );
        });
    }

    public function signOut(User $user, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($user, $ipAddress): void {
            $token = $user->currentAccessToken();

            if ($token !== null) {
                $token->delete();
            }

            $this->audit->record(
                action: AuditAction::LogoutSucceeded,
                actor: $user,
                subject: $user,
                ipAddress: $ipAddress,
            );
        });
    }

    /**
     * One failed attempt: count it, log it, and log the block separately when
     * this attempt is the one that reached the limit. The two records are
     * distinct events, because the administrator reading the log needs to see
     * the moment an account stopped accepting sign-ins, not only that several
     * attempts failed.
     */
    private function refuse(string $login, ?User $user, ?string $ipAddress): InvalidCredentialsException
    {
        $attemptsLeft = $this->throttle->registerFailure($login, $ipAddress);

        $this->audit->record(
            action: AuditAction::LoginFailed,
            actor: $user,
            payload: ['login' => $login, 'attempts_left' => $attemptsLeft],
            result: AuditResult::Failure,
            ipAddress: $ipAddress,
        );

        if ($attemptsLeft === 0) {
            $this->audit->record(
                action: AuditAction::LoginLocked,
                actor: $user,
                payload: [
                    'login' => $login,
                    'failed_attempts' => $this->throttle->maxAttempts(),
                    'lockout_seconds' => $this->throttle->lockoutSeconds(),
                ],
                result: AuditResult::Denied,
                ipAddress: $ipAddress,
            );
        }

        return new InvalidCredentialsException($attemptsLeft);
    }
}
