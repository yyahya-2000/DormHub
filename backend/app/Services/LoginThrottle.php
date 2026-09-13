<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ThrottleReason;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The attempt counter behind FR-08's first acceptance criterion.
 *
 * The numbers arrive through the constructor from config/dormitory.php, so the
 * rule «five attempts, fifteen minutes» is a setting and not a literal in a
 * method body.
 *
 * Two counters, kept apart on purpose.
 *
 * The **account** counter is the one the criterion speaks about: «after 5
 * failed attempts login is blocked for 15 minutes». It is keyed by the login
 * alone, so the block belongs to the account and travels with it. A single key
 * combining the login with the address would read as the stricter rule and be
 * the weaker one — the attacker changes address and the block is gone, while
 * the criterion says nothing about where the attempts came from.
 *
 * The **address** counter answers the other attack. Walking a dictionary of
 * logins from one address fills no account counter at all, because every login
 * starts a counter of its own; what fills is the address counter, and reaching
 * its ceiling blocks the address for every login. The ceiling is deliberately
 * higher than the account limit: a shared address — a computer room, a
 * university NAT — has to tolerate several people mistyping their passwords
 * within the same quarter of an hour.
 *
 * The counter and the block are two separate keys, again on purpose. A single
 * expiring counter would start its clock at the *first* failed attempt, and
 * the block would then end less than the configured span after the attempt
 * that caused it. Writing the block as its own key lets the fifteen minutes
 * run from the failure that reached the limit, which is what the criterion
 * says.
 *
 * A successful sign-in clears the account's history and leaves the address
 * counter standing. Clearing both would hand the attacker a reset button: one
 * sign-in to an account they already own, and the dictionary run continues.
 *
 * **Deployment note.** The address is whatever the request layer reports, and
 * the application does not trust `X-Forwarded-For`. Behind a load balancer or
 * a reverse proxy every request therefore appears to come from the proxy, and
 * the address ceiling collapses into a single ceiling shared by everybody —
 * harmless for the account block, but useless as a limit and capable of
 * blocking the whole site once enough failures accumulate. A deployment behind
 * a proxy must set Laravel's trusted proxies (`trustProxies` in
 * bootstrap/app.php) so that the address seen here is the client's.
 */
final readonly class LoginThrottle
{
    public function __construct(
        private Cache $cache,
        private int $maxAttempts,
        private int $maxAttemptsPerAddress,
        private int $lockoutSeconds,
    ) {}

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function maxAttemptsPerAddress(): int
    {
        return $this->maxAttemptsPerAddress;
    }

    public function lockoutSeconds(): int
    {
        return $this->lockoutSeconds;
    }

    public function isLocked(string $login, ?string $ipAddress): bool
    {
        return $this->lockedBy($login, $ipAddress) !== null;
    }

    /**
     * Which of the two blocks is standing, if either is. The account is asked
     * about first: when both hold, the account block is the one the person can
     * do something about, and it is the one the log should name.
     */
    public function lockedBy(string $login, ?string $ipAddress): ?ThrottleReason
    {
        if ($this->secondsLeftOn($this->accountLockKey($login)) > 0) {
            return ThrottleReason::LoginLocked;
        }

        if ($this->secondsLeftOn($this->addressLockKey($ipAddress)) > 0) {
            return ThrottleReason::AddressLocked;
        }

        return null;
    }

    /**
     * The longer of the two remaining spans: telling the caller to come back
     * in a minute when the other block still has ten to run would be a lie.
     */
    public function secondsUntilUnlocked(string $login, ?string $ipAddress): int
    {
        return max(
            $this->secondsLeftOn($this->accountLockKey($login)),
            $this->secondsLeftOn($this->addressLockKey($ipAddress)),
        );
    }

    /**
     * Counts one failed attempt against both counters and returns how many are
     * left before the nearer of the two blocks. Zero means this very attempt
     * reached a limit and the sign-in is now blocked; `lockedBy` says which.
     */
    public function registerFailure(string $login, ?string $ipAddress): int
    {
        $accountLeft = $this->countOne(
            $this->accountAttemptsKey($login),
            $this->accountLockKey($login),
            $this->maxAttempts,
        );

        $addressLeft = $this->countOne(
            $this->addressAttemptsKey($ipAddress),
            $this->addressLockKey($ipAddress),
            $this->maxAttemptsPerAddress,
        );

        return min($accountLeft, $addressLeft);
    }

    /**
     * A successful sign-in wipes the history of failures **of that account**.
     * The address counter is not touched; see the note in the class docblock.
     */
    public function clear(string $login): void
    {
        $this->cache->forget($this->accountAttemptsKey($login));
        $this->cache->forget($this->accountLockKey($login));
    }

    /**
     * One counter, one limit, one block. Returns the attempts left on this
     * counter; zero means the block was just written.
     */
    private function countOne(string $attemptsKey, string $lockKey, int $limit): int
    {
        $attempts = ((int) $this->cache->get($attemptsKey, 0)) + 1;

        $this->cache->put($attemptsKey, $attempts, $this->lockoutSeconds);

        if ($attempts < $limit) {
            return $limit - $attempts;
        }

        $this->cache->put(
            $lockKey,
            Carbon::now()->getTimestamp() + $this->lockoutSeconds,
            $this->lockoutSeconds,
        );

        return 0;
    }

    private function secondsLeftOn(string $lockKey): int
    {
        $unlockedAt = (int) $this->cache->get($lockKey, 0);

        return max(0, $unlockedAt - Carbon::now()->getTimestamp());
    }

    private function accountAttemptsKey(string $login): string
    {
        return 'auth:attempts:account:'.$this->fingerprint(Str::lower(trim($login)));
    }

    private function accountLockKey(string $login): string
    {
        return 'auth:lock:account:'.$this->fingerprint(Str::lower(trim($login)));
    }

    private function addressAttemptsKey(?string $ipAddress): string
    {
        return 'auth:attempts:address:'.$this->fingerprint($ipAddress ?? 'unknown');
    }

    private function addressLockKey(?string $ipAddress): string
    {
        return 'auth:lock:address:'.$this->fingerprint($ipAddress ?? 'unknown');
    }

    /**
     * The login is hashed rather than stored: the cache should not become a
     * second, unguarded list of the addresses registered in the system. The
     * network address is hashed for the same reason.
     */
    private function fingerprint(string $value): string
    {
        return sha1($value);
    }
}
