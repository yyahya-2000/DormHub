<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LoginThrottle;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The counter behind FR-08, isolated from HTTP and from the database.
 */
final class LoginThrottleTest extends TestCase
{
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-13 10:00:00'));

        $this->throttle = new LoginThrottle(
            cache: new Repository(new ArrayStore),
            maxAttempts: 5,
            lockoutSeconds: 15 * 60,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_fifth_failure_is_the_one_that_blocks_the_login(): void
    {
        $this->assertSame(4, $this->throttle->registerFailure('a@example.test', '198.51.100.7'));
        $this->assertSame(3, $this->throttle->registerFailure('a@example.test', '198.51.100.7'));
        $this->assertSame(2, $this->throttle->registerFailure('a@example.test', '198.51.100.7'));
        $this->assertSame(1, $this->throttle->registerFailure('a@example.test', '198.51.100.7'));

        $this->assertFalse($this->throttle->isLocked('a@example.test', '198.51.100.7'));

        $this->assertSame(0, $this->throttle->registerFailure('a@example.test', '198.51.100.7'));
        $this->assertTrue($this->throttle->isLocked('a@example.test', '198.51.100.7'));
    }

    public function test_the_block_runs_for_fifteen_minutes_from_the_failure_that_caused_it(): void
    {
        // Four failures, then a long pause, then the fifth. The block must
        // last a quarter of an hour from the fifth and not from the first.
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->registerFailure('b@example.test', '198.51.100.7');
        }

        Carbon::setTestNow(Carbon::parse('2026-09-13 10:10:00'));
        $this->throttle->registerFailure('b@example.test', '198.51.100.7');

        $this->assertSame(15 * 60, $this->throttle->secondsUntilUnlocked('b@example.test', '198.51.100.7'));

        Carbon::setTestNow(Carbon::parse('2026-09-13 10:24:59'));
        $this->assertTrue($this->throttle->isLocked('b@example.test', '198.51.100.7'));

        Carbon::setTestNow(Carbon::parse('2026-09-13 10:25:01'));
        $this->assertFalse($this->throttle->isLocked('b@example.test', '198.51.100.7'));
    }

    public function test_failures_are_counted_per_login_and_address_together(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->registerFailure('c@example.test', '198.51.100.7');
        }

        $this->assertTrue($this->throttle->isLocked('c@example.test', '198.51.100.7'));

        // The same account from elsewhere, and another account from the same
        // address, are both untouched.
        $this->assertFalse($this->throttle->isLocked('c@example.test', '203.0.113.9'));
        $this->assertFalse($this->throttle->isLocked('d@example.test', '198.51.100.7'));
    }

    public function test_a_successful_sign_in_wipes_the_history_of_failures(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->registerFailure('e@example.test', '198.51.100.7');
        }

        $this->throttle->clear('e@example.test', '198.51.100.7');

        $this->assertSame(4, $this->throttle->registerFailure('e@example.test', '198.51.100.7'));
    }

    public function test_the_login_is_compared_without_regard_to_case_or_surrounding_space(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->registerFailure('f@example.test', '198.51.100.7');
        }

        $this->assertTrue($this->throttle->isLocked('  F@Example.TEST ', '198.51.100.7'));
    }
}
