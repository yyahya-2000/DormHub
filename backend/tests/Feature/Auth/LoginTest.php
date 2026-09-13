<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-08, «Authentication and sessions». One test per acceptance criterion, and
 * the name of each test is the criterion (§4.7.2).
 */
final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config()->set('dormitory.auth.max_attempts', 5);
        config()->set('dormitory.auth.lockout_minutes', 15);
    }

    public function test_a_valid_login_and_password_yield_a_token(): void
    {
        $user = $this->resident();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'identity_provider', 'user']]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_a_successful_sign_in_is_written_to_the_audit_log(): void
    {
        $user = $this->resident();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => AuditAction::LoginSucceeded->value,
            'result' => 'success',
        ]);
    }

    public function test_after_5_failed_attempts_login_is_blocked_for_15_minutes(): void
    {
        $user = $this->resident();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-'.$attempt,
            ])->assertStatus(401);
        }

        // The sixth attempt carries the correct password and is still refused:
        // what is blocked is the login, not the guess.
        $blocked = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $blocked->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertLessThanOrEqual(15 * 60, (int) $blocked->json('retry_after'));
        $this->assertGreaterThan(14 * 60, (int) $blocked->json('retry_after'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_lockout_event_is_written_to_the_audit_log(): void
    {
        $user = $this->resident();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-'.$attempt,
            ])->assertStatus(401);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::LoginLocked->value,
            'result' => 'denied',
        ]);

        $this->assertSame(
            5,
            AuditLog::query()->where('action', AuditAction::LoginFailed->value)->count(),
        );
    }

    public function test_login_is_restored_once_the_15_minute_block_has_elapsed(): void
    {
        $user = $this->resident();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-'.$attempt,
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(429);

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    public function test_the_number_of_attempts_and_the_length_of_the_block_come_from_configuration(): void
    {
        config()->set('dormitory.auth.max_attempts', 2);
        config()->set('dormitory.auth.lockout_minutes', 1);

        $user = $this->resident();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'no'])
            ->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'no'])
            ->assertStatus(401);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(429);

        $this->travel(2)->minutes();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_a_blocked_account_cannot_sign_in_even_with_the_right_password(): void
    {
        $user = $this->resident();
        $user->forceFill(['status' => 'blocked'])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_unknown_login_is_refused_without_revealing_that_it_is_unknown(): void
    {
        $user = $this->resident();

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => self::PASSWORD,
        ]);
        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $unknown->assertStatus(401);
        $wrongPassword->assertStatus(401);
        $this->assertSame($unknown->json('message'), $wrongPassword->json('message'));
    }

    public function test_signing_out_revokes_the_token_that_was_used(): void
    {
        $user = $this->resident();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => AuditAction::LogoutSucceeded->value,
        ]);

        // The guard resolved during the previous call keeps its user for the
        // lifetime of the test application; a real second request starts from
        // nothing, so the guard is emptied to reproduce that.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_an_unauthenticated_request_to_a_protected_route_is_refused_with_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    private function resident(): User
    {
        $building = Building::factory()->create();

        return User::factory()
            ->withPassword(self::PASSWORD)
            ->withRole(RoleCode::Resident, $building)
            ->create();
    }
}
