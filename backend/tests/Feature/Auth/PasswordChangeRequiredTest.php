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
 * FR-42, fourth criterion: «changed at first login».
 *
 * The acceptance run of 22.09.2026 signed in with the printed password and
 * reached nine routes with that token. The flag was read by the client, which
 * redirected, and by nothing else — so the criterion held for a browser and
 * failed for anything else holding the same token.
 *
 * The tests below are written against the token rather than against the
 * client: sign in, and try the API.
 */
final class PasswordChangeRequiredTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUED = 'handed-over-on-paper';

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->building = Building::factory()->create();
    }

    public function test_a_token_of_an_account_owing_the_change_reaches_nothing_but_the_three_open_routes(): void
    {
        $resident = $this->residentOwingAChange();
        $token = $this->signIn($resident, self::ISSUED);

        foreach ([
            '/api/v1/announcements',
            '/api/v1/notifications',
            '/api/v1/lost-found',
            '/api/v1/guest-requests',
            '/api/v1/maintenance-requests',
            '/api/v1/buildings',
            "/api/v1/buildings/{$this->building->id}",
            "/api/v1/residents/{$resident->id}",
        ] as $path) {
            $this->withToken($token)->getJson($path)->assertStatus(403);
        }
    }

    /**
     * The three exceptions, and why each is one: without the form the change
     * could not be made, without `me` the client could not learn that it is
     * owed, and without the sign-out the account would be shut inside a
     * session it cannot leave.
     */
    public function test_the_account_may_read_itself_change_the_password_and_sign_out(): void
    {
        $resident = $this->residentOwingAChange();
        $token = $this->signIn($resident, self::ISSUED);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.password_change_required', true);

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => self::ISSUED,
            'password' => 'a-password-of-my-own',
            'password_confirmation' => 'a-password-of-my-own',
        ])->assertStatus(204);

        // Sign-out is tested on a second session, because the change above
        // revoked the first one along with every other token of the account.
        $second = $this->signIn($this->residentOwingAChange('second@example.test'), self::ISSUED);
        $this->withToken($second)->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_the_rest_of_the_api_opens_once_the_password_is_replaced(): void
    {
        $resident = $this->residentOwingAChange();
        $token = $this->signIn($resident, self::ISSUED);

        $this->withToken($token)->getJson('/api/v1/announcements')->assertStatus(403);

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => self::ISSUED,
            'password' => 'a-password-of-my-own',
            'password_confirmation' => 'a-password-of-my-own',
        ])->assertStatus(204);

        $replaced = $this->signIn($resident, 'a-password-of-my-own');

        $this->withToken($replaced)->getJson('/api/v1/announcements')->assertOk();
    }

    /**
     * The refusal goes through the same handler every other 403 does, so
     * §3.9.6's record of it is written without this middleware knowing about
     * the log at all.
     */
    public function test_the_refusal_is_recorded_as_a_denial(): void
    {
        $resident = $this->residentOwingAChange();
        $token = $this->signIn($resident, self::ISSUED);

        $this->withToken($token)->getJson('/api/v1/announcements')->assertStatus(403);

        $entry = AuditLog::query()
            ->where('action', AuditAction::AccessDenied->value)
            ->where('user_id', $resident->id)
            ->sole();

        $this->assertSame('announcements.index', $entry->payload['route']);
    }

    private function residentOwingAChange(string $email = 'incoming@example.test'): User
    {
        return User::factory()
            ->withPassword(self::ISSUED)
            ->withRole(RoleCode::Resident, $this->building)
            ->create(['email' => $email, 'password_change_required' => true]);
    }

    private function signIn(User $user, string $password): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }
}
