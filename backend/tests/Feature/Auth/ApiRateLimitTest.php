<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ceiling on everything behind a token.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Counted by account rather than by address: a dormitory behind one router
     * is one address, and a ceiling shared by its residents is a ceiling one of
     * them can spend for everybody.
     */
    public function test_one_account_spends_its_own_ceiling_and_nobody_else_s(): void
    {
        config(['dormitory.auth.requests_per_minute' => 3]);

        $one = User::factory()->create();
        $two = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($one, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        }

        $this->actingAs($one, 'sanctum')->getJson('/api/v1/auth/me')->assertStatus(429);
        $this->actingAs($two, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    }
}
