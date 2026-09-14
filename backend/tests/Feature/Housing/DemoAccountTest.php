<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\RoleCode;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The accounts a demonstration signs in as, asserted rather than assumed.
 *
 * **The acceptance finding of 15.09.2026.** `student@example.test` held a
 * resident grant and lived nowhere: the housing seeder accommodates the
 * residents it invents itself and had never heard of the demo account, and
 * nothing else gave it a bed. A role grant says what an account is; a
 * residency says where the person lives, and half the module routes ask the
 * second question — FR-16's guest request and FR-36's maintenance request are
 * open to a person who lives in the building and not to a person whose account
 * is scoped to it. So the one account the resident's screens are shown from
 * was answered 403 by both of them, and the stand could demonstrate neither.
 *
 * The test asserts the route and not the row, because the row is not the
 * point: what has to be true is that the demonstration can be given.
 */
final class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_resident_lives_somewhere_and_can_use_the_routes_that_ask(): void
    {
        $this->seed(DatabaseSeeder::class);

        $resident = User::query()->where('email', 'student@example.test')->sole();

        $residency = $resident->openResidency()->with('bed.room')->first();

        $this->assertNotNull($residency, 'The demo resident holds no bed, so half the stand is 403.');
        $this->assertTrue($resident->hasRole(RoleCode::Resident));

        $building = $residency->bed?->room?->building_id;

        $this->assertNotNull($building);

        Sanctum::actingAs($resident);

        // FR-16 and FR-36, the two routes that ask the register rather than
        // the role. Both were 403 for this account.
        $this->postJson('/api/v1/guest-requests', [
            'building_id' => $building,
            'guest_full_name' => 'Ostap Verigin',
            'visit_date' => now()->addDays(2)->toDateString(),
            'planned_from' => '14:00',
            'planned_to' => '18:00',
        ])->assertStatus(201);

        $this->post('/api/v1/maintenance-requests', [
            'building_id' => $building,
            'category' => 'plumbing',
            'location' => 'own_room',
            'description' => 'The mixer tap in the washbasin drips and the washer does not hold any more.',
            'urgency' => 'urgent',
        ])->assertStatus(201);
    }
}
