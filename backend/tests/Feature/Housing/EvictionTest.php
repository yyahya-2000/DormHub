<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\AuditAction;
use App\Enums\BedStatus;
use App\Enums\ResidencyStatus;
use App\Enums\RoleCode;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Residency;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-05, «Eviction and release of a bed». Three criteria, three tests, plus
 * the guarantee the whole design of §3.4.1 rests on: the row is not deleted.
 *
 * The third criterion is time-dependent and is checked with the framework's
 * clock-travel helper rather than by waiting (§4.7.1).
 */
final class EvictionTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;

    private User $warden;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->building = Building::factory()->create(['name' => 'Block 1']);
        $this->warden = $this->userWith(RoleCode::Warden, $this->building);
        $this->room = Room::factory()->for($this->building)->withBeds(2)->create([
            'number' => '305',
            'capacity' => 2,
        ]);
    }

    public function test_termination_of_residency_is_recorded_with_a_ground_and_a_date(): void
    {
        $residency = $this->accommodate($this->resident('leaving@example.test'), $this->bed());

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/residencies/{$residency->id}/termination", [
            'ground' => 'Graduation, art. 105 cl. 2 of the Housing Code',
            'moved_out_at' => CarbonImmutable::now()->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.moved_out_ground', 'Graduation, art. 105 cl. 2 of the Housing Code')
            ->assertJsonPath('data.moved_out_at', CarbonImmutable::now()->toDateString())
            ->assertJsonPath('data.status', ResidencyStatus::Ended->value);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->warden->id,
            'action' => AuditAction::ResidencyTerminated->value,
            'subject_type' => Residency::class,
            'subject_id' => $residency->id,
        ]);
    }

    public function test_a_termination_without_a_ground_is_refused(): void
    {
        $residency = $this->accommodate($this->resident('nameless@example.test'), $this->bed());

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/residencies/{$residency->id}/termination", ['ground' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ground');

        $this->assertTrue($residency->fresh()?->isOpen());
    }

    public function test_the_bed_becomes_free_automatically_after_eviction(): void
    {
        $bed = $this->bed();
        $residency = $this->accommodate($this->resident('first@example.test'), $bed);

        $this->assertSame(BedStatus::Occupied, $bed->fresh()?->status);

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/residencies/{$residency->id}/termination", [
            'ground' => 'Own request',
        ])->assertOk();

        $this->assertSame(BedStatus::Free, $bed->fresh()?->status);

        // «Free» means it takes somebody: the criterion is checked by using
        // the bed, not by reading a flag.
        $this->postJson('/api/v1/residencies', [
            'user_id' => $this->resident('next@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
            'contract_number' => 'DOG-NEXT',
            'moved_in_at' => CarbonImmutable::now()->toDateString(),
        ])->assertCreated();
    }

    public function test_the_history_is_not_deleted_on_eviction(): void
    {
        $resident = $this->resident('history@example.test');
        $residency = $this->accommodate($resident, $this->bed());

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/residencies/{$residency->id}/termination", [
            'ground' => 'Expulsion',
        ])->assertOk();

        $this->assertDatabaseHas('residencies', [
            'id' => $residency->id,
            'user_id' => $resident->id,
            'moved_out_ground' => 'Expulsion',
        ]);

        $this->assertSame(1, Residency::query()->where('user_id', $resident->id)->count());
    }

    public function test_the_residents_access_to_building_bound_functions_ends_no_later_than_the_stated_date(): void
    {
        $resident = $this->resident('scoped@example.test');
        $residency = $this->accommodate($resident, $this->bed());

        $stated = CarbonImmutable::now()->addDays(10);

        Sanctum::actingAs($this->warden);
        $this->postJson("/api/v1/residencies/{$residency->id}/termination", [
            'ground' => 'End of the accommodation contract',
            'moved_out_at' => $stated->toDateString(),
        ])->assertOk();

        // Notice given, not yet gone: the building-scoped route still answers.
        Sanctum::actingAs($resident);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertOk();

        // The day before the stated date: still resident.
        $this->travelTo($stated->subDay());
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertOk();

        // On the stated date the access is already gone — «no later than».
        $this->travelTo($stated);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertStatus(403);

        $this->travelTo($stated->addMonth());
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertStatus(403);

        $this->travelBack();
    }

    public function test_an_evicted_resident_still_reads_their_own_card(): void
    {
        $resident = $this->resident('evicted@example.test');
        $residency = $this->accommodate($resident, $this->bed());

        Sanctum::actingAs($this->warden);
        $this->postJson("/api/v1/residencies/{$residency->id}/termination", ['ground' => 'Graduation'])
            ->assertOk();

        // The dormitory is closed to them; their own record is not. The card
        // is the person's data, and FR-06's second criterion says they see it.
        Sanctum::actingAs($resident);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertStatus(403);
        $this->getJson("/api/v1/residents/{$resident->id}")
            ->assertOk()
            ->assertJsonPath('data.current_bed', null)
            ->assertJsonCount(1, 'data.residency_history');
    }

    public function test_a_resident_with_no_residency_record_keeps_the_access_their_grant_gives(): void
    {
        // Silence in the register is not eviction. This is the case the
        // existing role-model tests stand on, and it must not shift.
        $resident = $this->resident('unregistered@example.test');

        Sanctum::actingAs($resident);

        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertOk();
    }

    public function test_the_warden_of_another_building_evicts_nobody_here(): void
    {
        $other = Building::factory()->create(['name' => 'Block 2']);
        $wardenOfOther = $this->userWith(RoleCode::Warden, $other, 'warden2@example.test');

        $residency = $this->accommodate($this->resident('protected@example.test'), $this->bed());

        Sanctum::actingAs($wardenOfOther);

        $this->postJson("/api/v1/residencies/{$residency->id}/termination", ['ground' => 'Whatever'])
            ->assertStatus(403);

        $this->assertTrue($residency->fresh()?->isOpen());
    }

    private function accommodate(User $resident, Bed $bed): Residency
    {
        $residency = Residency::query()->create([
            'user_id' => $resident->getKey(),
            'bed_id' => $bed->getKey(),
            'contract_number' => 'DOG-'.$resident->getKey(),
            'moved_in_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
            'moved_in_ground' => 'Accommodation order',
            'status' => ResidencyStatus::Active,
        ]);

        $bed->update(['status' => BedStatus::Occupied]);

        return $residency;
    }

    private function bed(): Bed
    {
        return $this->room->beds()->orderBy('label')->firstOrFail();
    }

    private function resident(string $email): User
    {
        return $this->userWith(RoleCode::Resident, $this->building, $email);
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null ? $factory->create() : $factory->create(['email' => $email]);
    }
}
