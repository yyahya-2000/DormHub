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
use App\Models\Role;
use App\Models\RoleUser;
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

    public function test_a_resident_evicted_for_a_future_date_still_has_a_current_bed_on_the_card(): void
    {
        // The card used to read «record open» where FR-05 says «resident
        // today». A person leaving on the 31st of December lost their bed and
        // their obligations from the moment the notice was written, while the
        // same response marked the same residency `is_current: true` three
        // fields further down.
        $leaving = $this->resident($this->first, 'leaving@example.test');
        $residency = $this->accommodate($leaving, $this->first, '402', '1');

        $warden = $this->userWith(RoleCode::Warden, $this->first, 'warden-1@example.test');
        $departure = CarbonImmutable::now()->addMonths(3);

        Sanctum::actingAs($warden);
        $this->postJson("/api/v1/residencies/{$residency->id}/termination", [
            'ground' => 'End of the accommodation contract',
            'moved_out_at' => $departure->toDateString(),
        ])->assertOk();

        Sanctum::actingAs($leaving);
        $card = $this->getJson("/api/v1/residents/{$leaving->id}")->assertOk()->json('data');

        $this->assertSame('402', $card['current_bed']['room_number']);
        $this->assertCount(1, $card['open_obligations']);
        $this->assertTrue($card['residency_history'][0]['is_current']);

        // On the stated date the card agrees with the register: no bed, no
        // obligation, and the history still there.
        $this->travelTo($departure);
        $card = $this->getJson("/api/v1/residents/{$leaving->id}")->assertOk()->json('data');
        $this->travelBack();

        $this->assertNull($card['current_bed']);
        $this->assertSame([], $card['open_obligations']);
        $this->assertCount(1, $card['residency_history']);
    }

    public function test_the_card_is_visible_to_the_manager_of_that_building(): void
    {
        // Revision 2 of the role model puts the register work with the manager
        // and leaves it with the warden. The card is part of that work, and
        // the manager reaches it in his own building and nowhere else.
        $residentOfSecond = $this->resident($this->second, 'resident-2@example.test');
        $this->accommodate($residentOfSecond, $this->second, '101', '1');

        Sanctum::actingAs($this->userWith(RoleCode::Manager, $this->first, 'manager-1@example.test'));

        $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")->assertOk();
        $this->getJson("/api/v1/residents/{$residentOfSecond->id}")->assertStatus(403);
    }

    public function test_neither_the_duty_officer_nor_the_security_officer_reads_the_card(): void
    {
        // FR-06 names the warden of that building and the administrator, and
        // the contract for this route says the same. The duty officer decides
        // guest requests; that work needs the room register and the roll of
        // the building, neither of which carries citizenship or a telephone
        // number. The security officer's work is the entrance.
        foreach ([RoleCode::DutyOfficer, RoleCode::SecurityOfficer] as $index => $role) {
            Sanctum::actingAs($this->userWith($role, $this->first, sprintf('staff-%d@example.test', $index)));

            $this->getJson("/api/v1/residents/{$this->residentOfFirst->id}")->assertStatus(403);
        }

        // The roll of the building stays open to the duty officer: the
        // narrowing is of the card, not of their work.
        Sanctum::actingAs($this->userWith(RoleCode::DutyOfficer, $this->first, 'duty-roll@example.test'));
        $this->getJson("/api/v1/buildings/{$this->first->id}/users")->assertOk();
    }

    /**
     * FR-06's own statement of the first finding of 14.09.2026: what attaches a
     * card to a dormitory.
     *
     * Residence does — the residency register, or the resident grant that stands
     * in the window between an account being issued and a bed being assigned. A
     * staff grant does not. It used to, and that made FR-41 a way round FR-07:
     * the warden of block 1 wrote a security grant into block 1 for a resident of
     * block 2 and read the card he had just attached to himself.
     */
    public function test_a_staff_grant_attaches_no_card_to_a_building(): void
    {
        // A resident of block 2 who is also a security officer of block 1 — the
        // shape the escalation produced, and a shape the world can produce
        // honestly too.
        $residentOfSecond = $this->resident($this->second, 'resident-2@example.test');
        $this->accommodate($residentOfSecond, $this->second, '101', '1');

        $security = Role::query()->where('code', RoleCode::SecurityOfficer->value)->sole();

        RoleUser::query()->create([
            'user_id' => $residentOfSecond->getKey(),
            'role_id' => $security->getKey(),
            'building_id' => $this->first->getKey(),
            'granted_at' => now(),
        ]);

        Sanctum::actingAs($this->userWith(RoleCode::Warden, $this->first, 'warden-1@example.test'));

        $this->getJson("/api/v1/residents/{$residentOfSecond->id}")->assertStatus(403);
    }

    /**
     * And the other side of the same rule: an account issued under FR-42 has a
     * resident grant and no bed yet, and the manager who issued it reads the
     * card at once. Narrowing the attachment to residence must not close that.
     */
    public function test_a_resident_grant_attaches_the_card_before_a_bed_is_assigned(): void
    {
        $incoming = User::factory()
            ->withRole(RoleCode::Resident, $this->first)
            ->create(['email' => 'no-bed-yet@example.test']);

        Sanctum::actingAs($this->userWith(RoleCode::Manager, $this->first, 'manager-1@example.test'));

        $this->getJson("/api/v1/residents/{$incoming->id}")
            ->assertOk()
            ->assertJsonPath('data.current_bed', null);
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
