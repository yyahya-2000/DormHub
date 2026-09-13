<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ThrottleReason;
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
            maxAttemptsPerAddress: 12,
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
        $this->assertSame(ThrottleReason::LoginLocked, $this->throttle->lockedBy('a@example.test', '198.51.100.7'));
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

    /**
     * The defect the acceptance found: with one combined key the block fell
     * away as soon as the next attempt came from somewhere else.
     */
    public function test_the_block_belongs_to_the_account_and_survives_a_change_of_address(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->registerFailure('c@example.test', '198.51.100.7');
        }

        $this->assertTrue($this->throttle->isLocked('c@example.test', '198.51.100.7'));
        $this->assertTrue($this->throttle->isLocked('c@example.test', '203.0.113.9'));
        $this->assertTrue($this->throttle->isLocked('c@example.test', null));

        // The address is nowhere near its own ceiling of twelve, so another
        // account from that address is untouched: one careless person cannot
        // lock out everybody who shares their network.
        $this->assertFalse($this->throttle->isLocked('d@example.test', '198.51.100.7'));
    }

    /**
     * The other half of the defect: the combined key gave every login its own
     * full allowance, so a dictionary run from one address met no ceiling at
     * all.
     */
    public function test_one_address_is_capped_across_all_the_logins_tried_from_it(): void
    {
        // Twelve logins, one failure each: no account counter comes close to
        // five, and the address counter fills exactly.
        for ($i = 1; $i <= 12; $i++) {
            $this->throttle->registerFailure("victim{$i}@example.test", '198.51.100.7');
        }

        $this->assertTrue($this->throttle->isLocked('victim13@example.test', '198.51.100.7'));
        $this->assertSame(
            ThrottleReason::AddressLocked,
            $this->throttle->lockedBy('victim13@example.test', '198.51.100.7'),
        );
        $this->assertSame(15 * 60, $this->throttle->secondsUntilUnlocked('victim13@example.test', '198.51.100.7'));

        // The ceiling is on the address and on nothing else: the same logins
        // tried from elsewhere are still free.
        $this->assertFalse($this->throttle->isLocked('victim13@example.test', '203.0.113.9'));
    }

    public function test_the_count_returned_is_the_nearer_of_the_two_limits(): void
    {
        // Eleven logins, one failure each, leave the address one short of its
        // ceiling. The twelfth account still has four of its own attempts
        // left, and is told it has none.
        for ($i = 1; $i <= 11; $i++) {
            $this->throttle->registerFailure("walked{$i}@example.test", '198.51.100.7');
        }

        $this->assertSame(0, $this->throttle->registerFailure('walked12@example.test', '198.51.100.7'));
    }

    public function test_a_successful_sign_in_wipes_the_history_of_failures(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->registerFailure('e@example.test', '198.51.100.7');
        }

        $this->throttle->clear('e@example.test');

        $this->assertSame(4, $this->throttle->registerFailure('e@example.test', '198.51.100.7'));
    }

    /**
     * Otherwise the ceiling would have a reset button: an attacker signs in to
     * an account of their own between runs and starts the dictionary again.
     */
    public function test_a_successful_sign_in_leaves_the_address_counter_standing(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->throttle->registerFailure("noisy{$i}@example.test", '198.51.100.7');
        }

        $this->throttle->clear('noisy1@example.test');

        $this->assertSame(0, $this->throttle->registerFailure('noisy12@example.test', '198.51.100.7'));
        $this->assertTrue($this->throttle->isLocked('anyone@example.test', '198.51.100.7'));
    }

    public function test_the_login_is_compared_without_regard_to_case_or_surrounding_space(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->registerFailure('f@example.test', '198.51.100.7');
        }

        $this->assertTrue($this->throttle->isLocked('  F@Example.TEST ', '198.51.100.7'));
    }
}
