<?php
namespace Tests\Feature\Auth;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * FR-08. A request that carries no session is refused, and the refusal is the
 * protocol's, not a crash.
 */
class UnauthenticatedRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * There is no sign-in page to redirect to, and a client that does not ask
     * for JSON must not be told about one: without an Accept header the auth
     * middleware used to build a redirect to a route that does not exist.
     */
    public function test_a_request_without_a_token_is_refused_with_401_whatever_it_accepts(): void
    {
        foreach ([[], ['Accept' => 'application/json'], ['Accept' => 'text/html']] as $headers) {
            $this->getJson('/api/v1/auth/me', $headers)->assertStatus(401);
        }

        $this->call('GET', '/api/v1/auth/me')->assertStatus(401);
    }
}
