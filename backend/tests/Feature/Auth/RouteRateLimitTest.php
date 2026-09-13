<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ThrottleReason;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-address request limits in front of the two unauthenticated routes,
 * and the nightly sweep of the token table behind them. Findings 6, 8 and 9 of
 * the acceptance of 14.09.2026.
 *
 * The counters were one. Both routes are keyed by network address and a
 * dormitory is one address, so a handful of guesses at a one-time code closed
 * the sign-in route for every device behind that address — a denial of service
 * anybody could trigger from a phone, aimed at the people who live there. The
 * refusal also spoke about signing in whichever route produced it.
 */
final class RouteRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Small ceilings so the test states the rule rather than counting to
        // ten twice. The limiters read the configuration on every request.
        config([
            'dormitory.auth.login_requests_per_minute' => 3,
            'dormitory.auth.password_requests_per_minute' => 3,
        ]);
    }

    public function test_guessing_at_a_one_time_code_does_not_close_the_sign_in_route(): void
    {
        User::factory()
            ->withPassword('a-password-of-my-own')
            ->create(['email' => 'resident@example.test']);

        // Well past the ceiling of the password route.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/v1/auth/password', [
                'email' => 'resident@example.test',
                'token' => 'guess-'.$attempt,
                'password' => 'whatever-it-takes',
                'password_confirmation' => 'whatever-it-takes',
            ]);
        }

        $this->postJson('/api/v1/auth/password', [
            'email' => 'resident@example.test',
            'token' => 'one-more',
            'password' => 'whatever-it-takes',
            'password_confirmation' => 'whatever-it-takes',
        ])->assertStatus(429);

        // The other road is untouched: the people behind this address still
        // sign in.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'resident@example.test',
            'password' => 'a-password-of-my-own',
        ])->assertOk();
    }

    /**
     * Finding 9. The refusal of the password route used to be a sentence about
     * signing in, because it was the sign-in route's refusal.
     */
    public function test_the_refusal_names_the_route_it_refused(): void
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = $this->postJson('/api/v1/auth/password', [
                'email' => 'someone@example.test',
                'token' => 'guess-'.$attempt,
                'password' => 'whatever-it-takes',
                'password_confirmation' => 'whatever-it-takes',
            ]);
        }

        $response
            ->assertStatus(429)
            ->assertJsonPath('reason', ThrottleReason::RateLimited->value)
            ->assertJsonPath('message', 'Too many attempts to set a password from this address. Try again shortly.');

        $this->assertIsInt($response->json('retry_after'));
    }

    /**
     * And the sign-in route keeps a ceiling of its own, with its own wording.
     */
    public function test_the_sign_in_route_keeps_its_own_ceiling(): void
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'nobody@example.test',
                'password' => 'guess-'.$attempt,
            ]);
        }

        $response
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many sign-in requests from this address. Try again shortly.');
    }

    /**
     * Finding 8. A one-time code leaves a hashed row in `password_reset_tokens`
     * whether it is spent or not, and nothing was sweeping the table: the
     * framework ships the command and the schedule never named it. A hash of a
     * dead secret is not a secret, but the table is a list of the addresses
     * that hold an account, which is what the sign-in route is careful never to
     * answer.
     */
    public function test_the_spent_and_expired_codes_are_swept_nightly(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertTrue(
            $commands->contains(fn (string $command): bool => str_contains($command, 'auth:clear-resets')),
            'Nothing in the schedule clears `password_reset_tokens`.',
        );
    }
}
