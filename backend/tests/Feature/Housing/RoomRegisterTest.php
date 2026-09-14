<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\AuditAction;
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

    public function test_a_refused_bed_creation_reaches_the_audit_log(): void
    {
        // `bed.creation_refused` was declared in the enumeration and in the
        // contract and could not be reached by any request: the record was
        // written inside the transaction the exception then rolled back, so
        // the log held nought rows for it.
        $room = Room::factory()->for($this->first)->withBeds(1)->create(['number' => '307', 'capacity' => 1]);

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/rooms/{$room->id}/beds", ['label' => '2'])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->warden->id,
            'action' => AuditAction::BedCreationRefused->value,
            'subject_type' => Room::class,
            'subject_id' => $room->id,
            'result' => 'denied',
        ]);
    }

    public function test_a_refused_lowering_of_the_capacity_reaches_the_audit_log(): void
    {
        // The other refusal of this register recorded nothing at all: the
        // `throw` stood before the audit call rather than after it.
        $room = Room::factory()->for($this->first)->withBeds(3)->create(['number' => '502', 'capacity' => 3]);

        Sanctum::actingAs($this->warden);

        $this->patchJson("/api/v1/rooms/{$room->id}", ['capacity' => 1])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->warden->id,
            'action' => AuditAction::RoomUpdateRefused->value,
            'subject_type' => Room::class,
            'subject_id' => $room->id,
            'result' => 'denied',
        ]);
    }

    public function test_the_register_tells_places_that_may_be_added_from_places_somebody_could_move_into(): void
    {
        // FR-02's rejection names the first number; a warden looking for
        // somewhere to put an arrival is asking the second. A room of four
        // places with one occupant reported nought free places and looked
        // full.
        $room = Room::factory()->for($this->first)->withBeds(4)->create(['number' => '308', 'capacity' => 4]);

        $resident = $this->userWith(RoleCode::Resident, $this->first, 'occupant@example.test');
        $bed = $room->beds()->orderBy('label')->firstOrFail();
        $bed->update(['status' => BedStatus::Occupied]);
        $resident->residencies()->create([
            'bed_id' => $bed->getKey(),
            'contract_number' => 'DOG-8',
            'moved_in_at' => CarbonImmutable::now()->subMonth()->toDateString(),
        ]);

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('data.capacity', 4)
            ->assertJsonPath('data.beds_count', 4)
            ->assertJsonPath('data.occupied_beds_count', 1)
            // No further place may be registered …
            ->assertJsonPath('data.free_places', 0)
            // … and three of the registered ones are nobody's.
            ->assertJsonPath('data.vacant_beds', 3);
    }

    public function test_the_manager_of_the_building_keeps_the_register_like_the_warden(): void
    {
        $manager = $this->userWith(RoleCode::Manager, $this->first, 'manager@example.test');

        Sanctum::actingAs($manager);

        $room = $this->postJson("/api/v1/buildings/{$this->first->id}/rooms", [
            'number' => '601',
            'floor' => 6,
            'capacity' => 2,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/rooms/{$room['id']}/beds", ['label' => '1'])->assertCreated();
        $this->patchJson("/api/v1/rooms/{$room['id']}", ['capacity' => 3])->assertOk();

        // And nothing of the sort in the building next door.
        $this->postJson("/api/v1/buildings/{$this->second->id}/rooms", [
            'number' => '602',
            'floor' => 6,
            'capacity' => 2,
        ])->assertStatus(403);
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

    public function test_the_room_register_is_read_a_page_at_a_time(): void
    {
        Room::factory()->for($this->first)->count(25)->create(['capacity' => 2]);

        Sanctum::actingAs($this->warden);

        $first = $this->getJson("/api/v1/buildings/{$this->first->id}/rooms")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms?page=2")
            ->assertOk()
            ->assertJsonCount(5, 'data');

        // And the whole register is never one query: a size above the ceiling
        // is refused rather than quietly lowered.
        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms?per_page=500")
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');

        $this->assertSame(25, $first->json('meta.total'));
    }

    public function test_the_room_register_is_searched_by_number_and_narrowed_to_the_free_rooms(): void
    {
        $free = Room::factory()->for($this->first)->withBeds(2)->create([
            'number' => '305',
            'capacity' => 2,
        ]);

        $taken = Room::factory()->for($this->first)->withBeds(1)->create([
            'number' => '405',
            'capacity' => 1,
        ]);

        $taken->beds()->update(['status' => BedStatus::Occupied]);

        Sanctum::actingAs($this->warden);

        // Part of a number, and nothing else comes back.
        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms?q=05")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms?q=405")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $taken->id);

        // «Where can I put somebody» is the other filter, and it is asked of
        // the places rather than of the rooms: a room whose every place is
        // held is not free however many places it has.
        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms?free=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $free->id);
    }

    public function test_the_floor_summary_counts_the_rooms_and_the_places_of_each_floor(): void
    {
        $occupied = Room::factory()->for($this->first)->withBeds(2)->create([
            'number' => '301',
            'floor' => 3,
            'capacity' => 2,
        ]);

        $occupied->beds()->update(['status' => BedStatus::Occupied]);

        Room::factory()->for($this->first)->withBeds(3)->create([
            'number' => '302',
            'floor' => 3,
            'capacity' => 3,
        ]);

        Room::factory()->for($this->first)->withBeds(1)->create([
            'number' => '401',
            'floor' => 4,
            'capacity' => 1,
        ]);

        Sanctum::actingAs($this->warden);

        $floors = $this->getJson("/api/v1/buildings/{$this->first->id}/floors")
            ->assertOk()
            ->json('data');

        $this->assertSame([
            [
                'floor' => 3,
                'rooms_count' => 2,
                // One of the two rooms of the floor still takes somebody …
                'rooms_with_free_beds' => 1,
                'beds_count' => 5,
                // … and it is the one holding all three free places.
                'free_beds' => 3,
            ],
            [
                'floor' => 4,
                'rooms_count' => 1,
                'rooms_with_free_beds' => 1,
                'beds_count' => 1,
                'free_beds' => 1,
            ],
        ], $floors);
    }

    public function test_the_warden_of_building_1_reads_no_floor_summary_of_building_2(): void
    {
        Room::factory()->for($this->second)->withBeds(1)->create(['number' => '101', 'capacity' => 1]);

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->first->id}/floors")->assertOk();
        $this->getJson("/api/v1/buildings/{$this->second->id}/floors")->assertStatus(403);
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null ? $factory->create() : $factory->create(['email' => $email]);
    }
}
