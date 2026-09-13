<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\BedStatus;
use App\Enums\RoleCode;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-02, «Register of rooms and beds», one test per acceptance criterion plus
 * the database-level check the verification clause names.
 */
final class RoomRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Building $first;

    private Building $second;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->first = Building::factory()->create(['name' => 'Block 1']);
        $this->second = Building::factory()->create(['name' => 'Block 2']);
        $this->warden = $this->userWith(RoleCode::Warden, $this->first);
    }

    public function test_the_number_of_occupied_beds_never_exceeds_the_capacity_of_the_room(): void
    {
        $room = Room::factory()->for($this->first)->create(['number' => '305', 'capacity' => 3]);

        Sanctum::actingAs($this->warden);

        foreach (['1', '2', '3'] as $label) {
            $this->postJson("/api/v1/rooms/{$room->id}/beds", ['label' => $label])->assertCreated();
        }

        $this->postJson("/api/v1/rooms/{$room->id}/beds", ['label' => '4'])->assertStatus(422);

        // Three places registered, three occupied at most, and the capacity is
        // three. The count cannot pass the ceiling because the place that
        // would carry it past cannot be created.
        $this->assertSame(3, $room->beds()->count());
        $this->assertLessThanOrEqual($room->capacity, $room->beds()->count());
    }

    public function test_an_attempt_to_exceed_capacity_is_rejected_and_the_free_remainder_is_shown(): void
    {
        $room = Room::factory()->for($this->first)->create(['number' => '306', 'capacity' => 2]);

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/rooms/{$room->id}/beds", ['label' => '1'])->assertCreated();

        // One place left, and the answer says so.
        $this->getJson("/api/v1/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('data.free_places', 1);

        $this->postJson("/api/v1/rooms/{$room->id}/beds", ['label' => '2'])->assertCreated();

        $rejection = $this->postJson("/api/v1/rooms/{$room->id}/beds", ['label' => '3'])
            ->assertStatus(422)
            ->assertJsonPath('capacity', 2)
            ->assertJsonPath('beds', 2)
            ->assertJsonPath('free_places', 0);

        $this->assertStringContainsString('free places remaining: 0', (string) $rejection->json('message'));
    }

    public function test_a_bed_number_is_unique_inside_its_room(): void
    {
        $room = Room::factory()->for($this->first)->create(['number' => '401', 'capacity' => 4]);
        $other = Room::factory()->for($this->first)->create(['number' => '402', 'capacity' => 4]);

        Bed::query()->create(['room_id' => $room->getKey(), 'label' => '1', 'status' => BedStatus::Free]);

        // The same label in a different room is a different place and is
        // allowed; inside one room it is refused by the database itself.
        Bed::query()->create(['room_id' => $other->getKey(), 'label' => '1', 'status' => BedStatus::Free]);

        $this->expectException(QueryException::class);

        Bed::query()->create(['room_id' => $room->getKey(), 'label' => '1', 'status' => BedStatus::Free]);
    }

    public function test_lowering_the_capacity_below_the_places_already_registered_is_refused(): void
    {
        $room = Room::factory()->for($this->first)->withBeds(3)->create(['number' => '501', 'capacity' => 3]);

        Sanctum::actingAs($this->warden);

        $this->patchJson("/api/v1/rooms/{$room->id}", ['capacity' => 2])
            ->assertStatus(422)
            ->assertJsonPath('beds', 3);

        $this->assertSame(3, $room->fresh()?->capacity);
    }

    public function test_the_room_register_of_a_building_reports_capacity_occupancy_and_free_places(): void
    {
        $room = Room::factory()->for($this->first)->withBeds(3)->create(['number' => '305', 'capacity' => 3]);

        $resident = $this->userWith(RoleCode::Resident, $this->first, 'resident@example.test');
        $bed = $room->beds()->orderBy('label')->first();
        $bed->update(['status' => BedStatus::Occupied]);
        $resident->residencies()->create([
            'bed_id' => $bed->getKey(),
            'contract_number' => 'DOG-1',
            'moved_in_at' => CarbonImmutable::now()->subMonth()->toDateString(),
        ]);

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms")
            ->assertOk()
            ->assertJsonPath('data.0.number', '305')
            ->assertJsonPath('data.0.capacity', 3)
            ->assertJsonPath('data.0.beds_count', 3)
            ->assertJsonPath('data.0.occupied_beds_count', 1)
            ->assertJsonPath('data.0.free_places', 0)
            ->assertJsonCount(3, 'data.0.beds');
    }

    public function test_the_warden_of_building_1_reads_and_keeps_no_room_register_of_building_2(): void
    {
        $roomOfSecond = Room::factory()->for($this->second)->create(['number' => '101', 'capacity' => 2]);

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms")->assertOk();

        $this->getJson("/api/v1/buildings/{$this->second->id}/rooms")->assertStatus(403);
        $this->getJson("/api/v1/rooms/{$roomOfSecond->id}")->assertStatus(403);
        $this->postJson("/api/v1/buildings/{$this->second->id}/rooms", [
            'number' => '102',
            'floor' => 1,
            'capacity' => 2,
        ])->assertStatus(403);
        $this->postJson("/api/v1/rooms/{$roomOfSecond->id}/beds", ['label' => '1'])->assertStatus(403);
    }

    public function test_a_resident_reads_no_room_register_even_of_their_own_building(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Resident, $this->first, 'own@example.test'));

        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms")->assertStatus(403);
    }

    public function test_a_room_number_is_unique_inside_its_building_and_free_across_buildings(): void
    {
        Room::factory()->for($this->first)->create(['number' => '305']);

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/buildings/{$this->first->id}/rooms", [
            'number' => '305',
            'floor' => 3,
            'capacity' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('number');

        // The same number in the neighbouring building is a different room.
        $wardenOfSecond = $this->userWith(RoleCode::Warden, $this->second, 'warden2@example.test');
        Sanctum::actingAs($wardenOfSecond);

        $this->postJson("/api/v1/buildings/{$this->second->id}/rooms", [
            'number' => '305',
            'floor' => 3,
            'capacity' => 2,
        ])->assertCreated();
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null ? $factory->create() : $factory->create(['email' => $email]);
    }
}
