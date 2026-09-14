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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-03, «Residency assignment».
 *
 * The first tests in this file run against the database rather than the API,
 * and they are the ones the verification clause singles out: the exclusion
 * constraint `residencies_bed_no_overlap` refuses a second residency whose
 * period intersects an existing one, by itself, with no application code in
 * the path. They need a real PostgreSQL instance, which is what the documented
 * test command provides.
 *
 * The two API tests named «backdated» and «after a termination dated in the
 * future» are the two routes acceptance drove a second occupant in through on
 * 13.09.2026. Both returned 201 then. Neither touches an index that knows only
 * about open rows, which is why the rule had to be restated over the period.
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

    public function test_the_exclusion_constraint_refuses_a_second_active_residency_on_one_bed(): void
    {
        $bed = $this->bed();

        Residency::factory()->create([
            'user_id' => $this->resident('one@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
            'moved_in_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
        ]);

        $this->expectException(QueryException::class);

        // No service, no policy, no controller: the row goes straight at the
        // table and the constraint stops it.
        Residency::factory()->create([
            'user_id' => $this->resident('two@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
            'moved_in_at' => CarbonImmutable::now()->subMonth()->toDateString(),
        ]);
    }

    public function test_the_constraint_refuses_a_period_that_overlaps_a_closed_one(): void
    {
        $bed = $this->bed();

        // A residency that ran from March to August and is long since closed.
        Residency::factory()->ended(CarbonImmutable::now()->subMonth()->toDateString())->create([
            'user_id' => $this->resident('past@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
            'moved_in_at' => CarbonImmutable::now()->subMonths(6)->toDateString(),
        ]);

        $this->expectException(QueryException::class);

        // A second residency backdated into the middle of it. Two closed rows,
        // no open row anywhere, and still two people in one bed in June — the
        // case the partial unique index could not see.
        Residency::factory()->ended(CarbonImmutable::now()->subWeeks(2)->toDateString())->create([
            'user_id' => $this->resident('overlapping@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
            'moved_in_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
        ]);
    }

    public function test_the_constraint_admits_a_second_residency_once_the_first_one_has_ended(): void
    {
        $bed = $this->bed();
        $handover = CarbonImmutable::now()->subMonth();

        Residency::factory()->ended($handover->toDateString())->create([
            'user_id' => $this->resident('past@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
            'moved_in_at' => CarbonImmutable::now()->subMonths(6)->toDateString(),
        ]);

        // The closed row stays where it is — the history is never deleted —
        // and the bed takes a new occupant **on the very day the last one
        // left**. The bounds are `[)`, so the two periods touch and do not
        // overlap; were they `[]`, a bed could only change hands with a night
        // of nobody in it.
        Residency::factory()->create([
            'user_id' => $this->resident('present@example.test')->getKey(),
            'bed_id' => $bed->getKey(),
            'moved_in_at' => $handover->toDateString(),
        ]);

        $this->assertSame(2, Residency::query()->where('bed_id', $bed->getKey())->count());
        $this->assertSame(1, Residency::query()->where('bed_id', $bed->getKey())->open()->count());
    }

    public function test_a_residency_backdated_underneath_a_running_one_is_refused(): void
    {
        // Acceptance, reproduction 1: POST /residencies with a moved_in_at
        // inside the period of a residency that is already running. The bed
        // had one open row, so the partial unique index was never consulted
        // about the new row's dates, and the answer was 201.
        $bed = $this->bed();
        $holder = $this->resident('holder@example.test');
        $intruder = $this->resident('intruder@example.test');

        Residency::factory()->create([
            'user_id' => $holder->getKey(),
            'bed_id' => $bed->getKey(),
            'contract_number' => 'DOG-RUNNING',
            'moved_in_at' => CarbonImmutable::now()->subMonths(8)->toDateString(),
            'moved_out_at' => CarbonImmutable::now()->addMonths(9)->toDateString(),
            'moved_out_ground' => 'End of the accommodation contract',
        ]);

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/residencies', [
            'user_id' => $intruder->getKey(),
            'bed_id' => $bed->getKey(),
            'contract_number' => 'DOG-BACKDATED',
            'moved_in_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
            'ground' => 'Accommodation order',
        ])
            ->assertStatus(409)
            ->assertJsonPath('conflict.contract_number', 'DOG-RUNNING')
            ->assertJsonPath('conflict.user_id', $holder->id);

        $this->assertSame(
            1,
            Residency::query()->where('bed_id', $bed->getKey())->currentOn(CarbonImmutable::now())->count(),
        );
    }

    public function test_a_bed_whose_termination_is_dated_in_the_future_takes_nobody_today(): void
    {
        // Acceptance, reproduction 2: record the eviction with a date three
        // months out, which closed the row and freed the bed on the spot, then
        // move somebody else in. Two current residents on one bed, by two
        // ordinary requests.
        $bed = $this->bed();
        $leaving = $this->resident('leaving@example.test');
        $arriving = $this->resident('arriving@example.test');

        Sanctum::actingAs($this->warden);

        $residency = $this->postJson('/api/v1/residencies', $this->payload($leaving, $bed, 'DOG-LEAVING'))
            ->assertCreated()
            ->json('data');

        $departure = CarbonImmutable::now()->addMonths(3);

        $this->postJson("/api/v1/residencies/{$residency['id']}/termination", [
            'ground' => 'End of the accommodation contract',
            'moved_out_at' => $departure->toDateString(),
        ])
            ->assertOk()
            // Notice given, not yet served: the record still says so.
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.status', ResidencyStatus::Active->value);

        // The bed is not free while somebody is sleeping in it.
        $this->assertSame(BedStatus::Occupied, $bed->fresh()?->status);

        $this->postJson('/api/v1/residencies', $this->payload($arriving, $bed, 'DOG-ARRIVING'))
            ->assertStatus(409)
            ->assertJsonPath('conflict.contract_number', 'DOG-LEAVING');

        $this->assertSame(
            1,
            Residency::query()->where('bed_id', $bed->getKey())->currentOn(CarbonImmutable::now())->count(),
        );
    }

    public function test_a_resident_evicted_for_a_future_date_holds_no_second_bed_meanwhile(): void
    {
        // §3.4.4 broken by the same mechanism, read from the other side: one
        // person on two beds at once.
        $beds = $this->room->beds()->orderBy('label')->get();
        $resident = $this->resident('twice@example.test');

        Sanctum::actingAs($this->warden);

        $residency = $this->postJson('/api/v1/residencies', $this->payload($resident, $beds[0], 'DOG-FIRST'))
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/v1/residencies/{$residency['id']}/termination", [
            'ground' => 'Room transfer',
            'moved_out_at' => CarbonImmutable::now()->addMonth()->toDateString(),
        ])->assertOk();

        $this->postJson('/api/v1/residencies', $this->payload($resident, $beds[1], 'DOG-SECOND'))
            ->assertStatus(409)
            ->assertJsonPath('conflict.bed_id', $beds[0]->id);
    }

    public function test_a_room_transfer_on_the_day_of_the_move_is_admitted(): void
    {
        // The boundary the `[)` bounds are chosen for, through the API: the
        // termination takes effect today and the new residency starts today.
        // A stricter constraint would make a resident spend a night nowhere.
        $beds = $this->room->beds()->orderBy('label')->get();
        $resident = $this->resident('transfer@example.test');

        Sanctum::actingAs($this->warden);

        $residency = $this->postJson('/api/v1/residencies', [
            'user_id' => $resident->getKey(),
            'bed_id' => $beds[0]->getKey(),
            'contract_number' => 'DOG-OLD',
            'moved_in_at' => CarbonImmutable::now()->subMonths(2)->toDateString(),
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/residencies/{$residency['id']}/termination", [
            'ground' => 'Room transfer',
            'moved_out_at' => CarbonImmutable::now()->toDateString(),
        ])->assertOk();

        $this->postJson('/api/v1/residencies', $this->payload($resident, $beds[1], 'DOG-NEW'))
            ->assertCreated();

        $this->assertSame(BedStatus::Free, $beds[0]->fresh()?->status);
        $this->assertSame(BedStatus::Occupied, $beds[1]->fresh()?->status);
    }

    public function test_the_manager_of_the_building_places_residents_like_the_warden(): void
    {
        // Revision 2 of the role model: the manager carries the whole of the
        // warden's operative work, and moving people in is part of it.
        $manager = $this->userWith(RoleCode::Manager, $this->first, 'manager@example.test');

        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/residencies', $this->payload(
            $this->resident('placed-by-manager@example.test'),
            $this->bed(),
            'DOG-MGR',
        ))->assertCreated();
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

    public function test_a_blocked_bed_accepts_no_residency(): void
    {
        $resident = $this->resident('blocked@example.test');

        $blockedBed = $this->room->beds()->orderBy('label')->first();
        $blockedBed->update(['status' => BedStatus::Blocked]);

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/residencies', $this->payload($resident, $blockedBed, 'DOG-1'))
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
    public function test_the_accommodation_form_finds_a_candidate_by_part_of_the_name(): void
    {
        // FR-03 at the form: the warden types a few letters instead of being
        // handed the whole roll to filter in the browser.
        $this->userWith(RoleCode::Resident, $this->first, 'petrova@example.test')
            ->update(['full_name' => 'Petrova Anna Sergeevna']);
        $this->userWith(RoleCode::Resident, $this->first, 'petrov@example.test')
            ->update(['full_name' => 'Petrov Ivan Ivanovich']);
        $this->userWith(RoleCode::Resident, $this->first, 'orlova@example.test')
            ->update(['full_name' => 'Orlova Maria Pavlovna']);

        Sanctum::actingAs($this->warden);

        $found = $this->getJson("/api/v1/buildings/{$this->first->id}/users?role=student&q=petrov")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data.*.full_name');

        $this->assertSame(['Petrov Ivan Ivanovich', 'Petrova Anna Sergeevna'], $found);

        // The role narrows it the other way: the warden of this building holds
        // a grant here too and is not a candidate for a bed.
        $this->assertNotContains(
            $this->warden->full_name,
            $this->getJson("/api/v1/buildings/{$this->first->id}/users?role=student")
                ->assertOk()
                ->json('data.*.full_name'),
        );
    }

    public function test_the_roll_of_a_building_never_answers_with_a_resident_of_another_one(): void
    {
        // The search narrows the roll and cannot widen it. A resident of block
        // 2 whose name matches perfectly is invisible to the warden of block 1,
        // because the query starts from the grants naming block 1.
        $this->userWith(RoleCode::Resident, $this->second, 'elsewhere@example.test')
            ->update(['full_name' => 'Petrov Semyon Petrovich']);

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->first->id}/users?q=Petrov")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/buildings/{$this->second->id}/users?q=Petrov")
            ->assertStatus(403);
    }

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
