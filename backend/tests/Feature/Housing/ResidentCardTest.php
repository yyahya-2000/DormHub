<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\AuditAction;
use App\Enums\BedStatus;
use App\Enums\ResidencyStatus;
use App\Enums\RoleCode;
use App\Enums\StudyStatus;
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
 * FR-06, «Resident card», and with it the horizontal-access check §4.7.2 asks
 * for, carried onto personal data: the warden of building 1 requesting a card
 * of building 2, through the API and not through the interface.
 */
final class ResidentCardTest extends TestCase
{
    use RefreshDatabase;

    private Building $first;

    private Building $second;

    private User $residentOfFirst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->first = Building::factory()->create(['name' => 'Block 1']);
        $this->second = Building::factory()->create(['name' => 'Block 2']);

        $this->residentOfFirst = $this->resident($this->first, 'resident-1@example.test');
        $this->accommodate($this->residentOfFirst, $this->first, '305', '1');
    }

    public function test_the_card_is_visible_to_the_warden_of_that_building(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Warden, $this->first, 'warden-1@example.test'));

        $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->residentOfFirst->id)
            ->assertJsonPath('data.full_name', $this->residentOfFirst->full_name);
    }

    public function test_the_card_is_visible_to_the_administrator(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null, 'admin@example.test'));

        $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")->assertOk();
    }

    public function test_the_warden_of_building_1_requesting_a_card_of_building_2_gets_403(): void
    {
        $residentOfSecond = $this->resident($this->second, 'resident-2@example.test');
        $this->accommodate($residentOfSecond, $this->second, '101', '1');

        Sanctum::actingAs($this->userWith(RoleCode::Warden, $this->first, 'warden-1@example.test'));

        // Own building: the card is returned. The neighbouring one: refused,
        // at the API and not by a screen that declines to draw a button.
        $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")->assertOk();
        $this->getJson("/api/v1/residents/{$residentOfSecond->id}")->assertStatus(403);
    }

    public function test_a_student_requesting_another_students_card_gets_403(): void
    {
        $neighbour = $this->resident($this->first, 'neighbour@example.test');
        $this->accommodate($neighbour, $this->first, '306', '1');

        Sanctum::actingAs($this->residentOfFirst);

        // Same building, same floor, same role: still refused. «A resident
        // sees only their own card.»
        $this->getJson("/api/v1/residents/{$neighbour->id}")->assertStatus(403);
    }

    public function test_a_resident_sees_only_their_own_card(): void
    {
        Sanctum::actingAs($this->residentOfFirst);

        $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->residentOfFirst->id);
    }

    public function test_the_own_card_returns_name_study_status_citizenship_contact_current_bed_history_and_open_obligations(): void
    {
        Sanctum::actingAs($this->residentOfFirst);

        $card = $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'full_name',
                    'study_status',
                    'citizenship',
                    'contact' => ['email', 'phone'],
                    'current_bed' => ['building_name', 'room_number', 'bed_label', 'moved_in_at'],
                    'residency_history',
                    'open_obligations',
                ],
            ])
            ->json('data');

        $this->assertSame(StudyStatus::Enrolled->value, $card['study_status']);
        $this->assertSame('RU', $card['citizenship']);
        $this->assertSame($this->residentOfFirst->email, $card['contact']['email']);
        $this->assertSame('Block 1', $card['current_bed']['building_name']);
        $this->assertSame('305', $card['current_bed']['room_number']);
        $this->assertCount(1, $card['residency_history']);

        // The one obligation the MVP register knows: an accommodation contract
        // that has not been terminated.
        $this->assertCount(1, $card['open_obligations']);
        $this->assertSame('accommodation_contract', $card['open_obligations'][0]['kind']);
        $this->assertSame('DOG-305-1', $card['open_obligations'][0]['contract_number']);
    }

    public function test_the_card_shows_the_whole_residency_history_and_no_current_bed_after_a_move_out(): void
    {
        $mover = $this->resident($this->first, 'mover@example.test');

        $first = $this->accommodate($mover, $this->first, '401', '1');
        $first->update([
            'moved_out_at' => CarbonImmutable::now()->subMonth()->toDateString(),
            'moved_out_ground' => 'Room transfer',
            'status' => ResidencyStatus::Ended,
        ]);
        $first->bed?->update(['status' => BedStatus::Free]);

        Sanctum::actingAs($mover);

        $card = $this->getJson("/api/v1/residents/{$mover->id}")->assertOk()->json('data');

        $this->assertNull($card['current_bed']);
        $this->assertCount(1, $card['residency_history']);
        $this->assertSame([], $card['open_obligations']);
    }

    public function test_the_duty_officer_of_the_building_reads_the_card_and_the_security_officer_does_not(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::DutyOfficer, $this->first, 'duty@example.test'));
        $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")->assertOk();

        Sanctum::actingAs($this->userWith(RoleCode::SecurityOfficer, $this->first, 'security@example.test'));
        $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")->assertStatus(403);
    }

    public function test_reading_a_card_is_written_to_the_audit_log(): void
    {
        $warden = $this->userWith(RoleCode::Warden, $this->first, 'warden-1@example.test');

        Sanctum::actingAs($warden);
        $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $warden->id,
            'action' => AuditAction::ResidentCardViewed->value,
            'subject_type' => User::class,
            'subject_id' => $this->residentOfFirst->id,
        ]);
    }

    private function accommodate(User $resident, Building $building, string $roomNumber, string $bedLabel): Residency
    {
        $room = Room::query()->firstOrCreate(
            ['building_id' => $building->getKey(), 'number' => $roomNumber],
            ['floor' => (int) $roomNumber[0], 'capacity' => 3],
        );

        $bed = Bed::query()->firstOrCreate(
            ['room_id' => $room->getKey(), 'label' => $bedLabel],
            ['status' => BedStatus::Occupied],
        );

        return Residency::query()->create([
            'user_id' => $resident->getKey(),
            'bed_id' => $bed->getKey(),
            'contract_number' => sprintf('DOG-%s-%s', $roomNumber, $bedLabel),
            'moved_in_at' => CarbonImmutable::now()->subMonths(4)->toDateString(),
            'moved_in_ground' => 'Accommodation order',
            'status' => ResidencyStatus::Active,
        ]);
    }

    private function resident(Building $building, string $email): User
    {
        return User::factory()
            ->withRole(RoleCode::Resident, $building)
            ->create([
                'email' => $email,
                'study_status' => StudyStatus::Enrolled,
                'citizenship' => 'RU',
            ]);
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null ? $factory->create() : $factory->create(['email' => $email]);
    }
}
