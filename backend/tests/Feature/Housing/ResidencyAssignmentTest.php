<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\AuditAction;
use App\Enums\BedStatus;
use App\Enums\RoleCode;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Residency;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-03, «Residency assignment».
 *
 * The first test in this file runs against the database rather than the API,
 * and it is the one the verification clause singles out: the partial unique
 * index `residencies_active_bed_uniq` refuses the second open residency on one
 * bed by itself, with no application code in the path. It needs a real
 * PostgreSQL instance, which is what the documented test command provides.
 */
final class ResidencyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Building $first;

    private Building $second;

    private User $warden;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->first = Building::factory()->create(['name' => 'Block 1']);
        $this->second = Building::factory()->create(['name' => 'Block 2']);
        $this->warden = $this->userWith(RoleCode::Warden, $this->first);
        $this->room = Room::factory()->for($this->first)->withBeds(2)->create([
            'number' => '305',
            'capacity' => 2,
        ]);
    }

    public function test_the_partial_unique_index_refuses_a_second_active_residency_on_one_bed(): void
    {
        $bed = $this->bed();

        Residency::factory()->create([
            'user_id' => $this->resident('one@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
        ]);

        $this->expectException(QueryException::class);

        // No service, no policy, no controller: the row goes straight at the
        // table and the index stops it.
        Residency::factory()->create([
            'user_id' => $this->resident('two@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
        ]);
    }

    public function test_the_index_admits_a_second_residency_once_the_first_one_has_ended(): void
    {
        $bed = $this->bed();

        Residency::factory()->ended()->create([
            'user_id' => $this->resident('past@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
        ]);

        // The closed row stays where it is — the history is never deleted —
        // and the bed takes a new occupant.
        Residency::factory()->create([
            'user_id' => $this->resident('present@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
        ]);

        $this->assertSame(2, Residency::query()->where('bed_id', $bed->getKey())->count());
        $this->assertSame(1, Residency::query()->where('bed_id', $bed->getKey())->open()->count());
    }

    public function test_two_residents_cannot_hold_the_same_bed_over_overlapping_periods(): void
    {
        $bed = $this->bed();
        $first = $this->resident('first@example.test');
        $second = $this->resident('second@example.test');

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/residencies', $this->payload($first, $bed, 'DOG-1'))->assertCreated();

        $this->postJson('/api/v1/residencies', $this->payload($second, $bed, 'DOG-2'))->assertStatus(409);

        $this->assertSame(1, Residency::query()->where('bed_id', $bed->getKey())->open()->count());
    }

    public function test_on_such_an_attempt_the_system_displays_the_conflicting_record(): void
    {
        $bed = $this->bed();
        $first = $this->resident('holder@example.test');
        $second = $this->resident('applicant@example.test');

        Sanctum::actingAs($this->warden);

        $held = $this->postJson('/api/v1/residencies', $this->payload($first, $bed, 'DOG-77'))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/v1/residencies', $this->payload($second, $bed, 'DOG-78'))
            ->assertStatus(409)
            ->assertJsonPath('conflict.id', $held['id'])
            ->assertJsonPath('conflict.user_id', $first->id)
            ->assertJsonPath('conflict.contract_number', 'DOG-77')
            ->assertJsonPath('conflict.room_number', '305')
            ->assertJsonPath('conflict.building_name', 'Block 1')
            ->assertJsonPath('conflict.is_open', true);
    }

    public function test_a_successful_assignment_marks_the_bed_occupied_and_is_written_to_the_audit_log(): void
    {
        $bed = $this->bed();
        $resident = $this->resident('placed@example.test');

        Sanctum::actingAs($this->warden);

        $residency = $this->postJson('/api/v1/residencies', $this->payload($resident, $bed, 'DOG-5'))
            ->assertCreated()
            ->assertJsonPath('data.bed_label', $bed->label)
            ->assertJsonPath('data.building_name', 'Block 1')
            ->json('data');

        $this->assertSame(BedStatus::Occupied, $bed->fresh()?->status);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->warden->id,
            'action' => AuditAction::ResidencyAssigned->value,
            'subject_type' => Residency::class,
            'subject_id' => $residency['id'],
        ]);
    }

    public function test_a_refused_assignment_is_written_to_the_audit_log(): void
    {
        $bed = $this->bed();

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/residencies', $this->payload($this->resident('a@example.test'), $bed, 'DOG-1'))
            ->assertCreated();
        $this->postJson('/api/v1/residencies', $this->payload($this->resident('b@example.test'), $bed, 'DOG-2'))
            ->assertStatus(409);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->warden->id,
            'action' => AuditAction::ResidencyAssignmentRefused->value,
            'result' => 'denied',
        ]);
    }

    public function test_a_resident_holds_one_bed_at_a_time(): void
    {
        $resident = $this->resident('mobile@example.test');
        $beds = $this->room->beds()->orderBy('label')->get();

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/residencies', $this->payload($resident, $beds[0], 'DOG-1'))->assertCreated();

        $this->postJson('/api/v1/residencies', $this->payload($resident, $beds[1], 'DOG-2'))
            ->assertStatus(409)
            ->assertJsonPath('conflict.bed_id', $beds[0]->id);
    }

    public function test_a_blocked_bed_and_a_room_under_repair_accept_no_residency(): void
    {
        $resident = $this->resident('blocked@example.test');

        $blockedBed = $this->room->beds()->orderBy('label')->first();
        $blockedBed->update(['status' => BedStatus::Blocked]);

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/residencies', $this->payload($resident, $blockedBed, 'DOG-1'))
            ->assertStatus(422);

        $repaired = Room::factory()->for($this->first)->underRepair()->withBeds(1)->create([
            'number' => '502',
            'capacity' => 1,
        ]);

        $this->postJson('/api/v1/residencies', $this->payload($resident, $repaired->beds()->sole(), 'DOG-2'))
            ->assertStatus(422);

        $this->assertSame(0, Residency::query()->count());
    }

    public function test_the_warden_of_building_1_places_nobody_in_a_bed_of_building_2(): void
    {
        $roomOfSecond = Room::factory()->for($this->second)->withBeds(1)->create([
            'number' => '101',
            'capacity' => 1,
        ]);

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/residencies', $this->payload(
            $this->resident('outsider@example.test'),
            $roomOfSecond->beds()->sole(),
            'DOG-9',
        ))->assertStatus(403);

        $this->assertSame(0, Residency::query()->count());
    }

    public function test_a_resident_places_nobody_anywhere(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Resident, $this->first, 'self@example.test'));

        $this->postJson('/api/v1/residencies', $this->payload(
            $this->resident('target@example.test'),
            $this->bed(),
            'DOG-3',
        ))->assertStatus(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $resident, Bed $bed, string $contract): array
    {
        return [
            'user_id' => $resident->getKey(),
            'bed_id' => $bed->getKey(),
            'contract_number' => $contract,
            'moved_in_at' => CarbonImmutable::now()->toDateString(),
            'ground' => 'Accommodation order',
        ];
    }

    private function bed(): Bed
    {
        return $this->room->beds()->orderBy('label')->firstOrFail();
    }

    private function resident(string $email): User
    {
        return $this->userWith(RoleCode::Resident, $this->first, $email);
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null ? $factory->create() : $factory->create(['email' => $email]);
    }
}
