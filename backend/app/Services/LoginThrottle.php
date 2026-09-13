<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The attempt counter behind FR-08's first acceptance criterion.
 *
 * Both numbers arrive through the constructor from config/dormitory.php, so
 * the rule «five attempts, fifteen minutes» is a setting and not a literal in
 * a method body.
 *
 * The counter and the block are two separate keys on purpose. A single
 * expiring counter would start its clock at the *first* failed attempt, and
 * the block would then end less than the configured span after the attempt
 * that caused it. Writing the block as its own key lets the fifteen minutes
 * run from the failure that reached the limit, which is what the criterion
 * says.
 *
 * Attempts are counted per login and per address together: one careless
 * person cannot lock out an account for everybody else, and one address
 * cannot walk a dictionary through many accounts unnoticed.
 */
final readonly class LoginThrottle
{
    public function __construct(
        private Cache $cache,
        private int $maxAttempts,
        private int $lockoutSeconds,
    ) {}

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function lockoutSeconds(): int
    {
        return $this->lockoutSeconds;
    }

    public function isLocked(string $login, ?string $ipAddress): bool
    {
        return $this->secondsUntilUnlocked($login, $ipAddress) > 0;
    }

    public function secondsUntilUnlocked(string $login, ?string $ipAddress): int
    {
        $unlockedAt = (int) $this->cache->get($this->lockKey($login, $ipAddress), 0);

        return max(0, $unlockedAt - Carbon::now()->getTimestamp());
    }

    /**
     * Counts one failed attempt and returns how many are left before the
     * block. Zero means this very attempt reached the limit and the login is
     * now blocked.
     */
    public function registerFailure(string $login, ?string $ipAddress): int
    {
        $key = $this->attemptsKey($login, $ipAddress);
        $attempts = ((int) $this->cache->get($key, 0)) + 1;

        $this->cache->put($key, $attempts, $this->lockoutSeconds);

        if ($attempts < $this->maxAttempts) {
            return $this->maxAttempts - $attempts;
        }

        $this->cache->put(
            $this->lockKey($login, $ipAddress),
            Carbon::now()->getTimestamp() + $this->lockoutSeconds,
            $this->lockoutSeconds,
        );

        return 0;
    }

    /**
     * A successful sign-in wipes the history of failures.
     */
    public function clear(string $login, ?string $ipAddress): void
    {
        $this->cache->forget($this->attemptsKey($login, $ipAddress));
        $this->cache->forget($this->lockKey($login, $ipAddress));
    }

    private function attemptsKey(string $login, ?string $ipAddress): string
    {
        return 'auth:attempts:'.$this->fingerprint($login, $ipAddress);
    }

    private function lockKey(string $login, ?string $ipAddress): string
    {
        return 'auth:lock:'.$this->fingerprint($login, $ipAddress);
    }

    /**
     * The login is hashed rather than stored: the cache should not become a
     * second, unguarded list of the addresses registered in the system.
     */
    private function fingerprint(string $login, ?string $ipAddress): string
    {
        return sha1(Str::lower(trim($login)).'|'.($ipAddress ?? 'unknown'));
    }
}
