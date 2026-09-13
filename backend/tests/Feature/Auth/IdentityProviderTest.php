<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contracts\IdentityProvider;
use App\Enums\RoleCode;
use App\Identity\DatabaseIdentityProvider;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StubSsoIdentityProvider;
use Tests\TestCase;

/**
 * FR-08, third criterion: «The external identity provider is pluggable
 * (university SSO)».
 *
 * §4.7.3 states what that claim is worth without a real integration, and
 * these tests are exactly that: a second implementation of the interface is
 * instantiated, named in configuration, and the sign-in route works through it
 * with no change to the service, the controller or the route.
 */
final class IdentityProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_the_external_identity_provider_is_pluggable(): void
    {
        $user = $this->residentWithExternalId('hse-482913');

        $this->app->bind(
            IdentityProvider::class,
            fn (): IdentityProvider => new StubSsoIdentityProvider(['ticket-ok' => 'hse-482913'])
        );

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ticket-ok',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.identity_provider', 'stub-sso')
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_a_second_identity_provider_refuses_a_credential_the_first_one_would_accept(): void
    {
        $user = $this->residentWithExternalId('hse-000001');

        // The password stored in `users` is good enough for the default
        // provider and means nothing to the external one.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->app->bind(
            IdentityProvider::class,
            fn (): IdentityProvider => new StubSsoIdentityProvider(['ticket-ok' => 'hse-000001'])
        );

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(401);
    }

    public function test_the_provider_used_is_the_one_named_in_configuration(): void
    {
        $this->assertSame(
            DatabaseIdentityProvider::class,
            config('dormitory.auth.identity_provider'),
        );

        $this->assertInstanceOf(
            DatabaseIdentityProvider::class,
            $this->app->make(IdentityProvider::class),
        );
    }

    private function residentWithExternalId(string $externalId): User
    {
        $building = Building::factory()->create();

        return User::factory()
            ->withPassword('password')
            ->withRole(RoleCode::Resident, $building)
            ->create(['external_id' => $externalId]);
    }
}
