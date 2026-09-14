<?php

declare(strict_types=1);

namespace Tests\Feature\Authorisation;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-41, «Staff appointment inside a building». One test per acceptance
 * criterion, named in the words the criterion is written in.
 *
 * The file also covers the negative that the criteria state twice over and
 * that the design turns on: the manager, who does every other thing the warden
 * does, appoints nobody at all.
 */
final class StaffAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Building $first;

    private Building $second;

    private User $wardenOfFirst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->first = Building::factory()->create(['name' => 'Block 1']);
        $this->second = Building::factory()->create(['name' => 'Block 2']);

        $this->wardenOfFirst = User::factory()
            ->withRole(RoleCode::Warden, $this->first)
            ->create(['email' => 'warden-of-first@example.test']);
    }

    /**
     * First criterion, and the first half of the verification: «the warden of
     * building 1 grants a manager in building 1 and receives 201».
     */
    public function test_the_warden_grants_the_manager_role_inside_their_own_building(): void
    {
        $subject = User::factory()->create(['email' => 'incoming-manager@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
            'user_id' => $subject->id,
            'role' => RoleCode::Manager->value,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.building_id', $this->first->id);

        $this->assertTrue($subject->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->first));
        $this->assertFalse($subject->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->second));
    }

    /**
     * Second criterion, and the second half of the verification: «the same call
     * against building 2 returns 403».
     */
    public function test_the_same_call_against_another_building_is_refused(): void
    {
        $subject = User::factory()->create(['email' => 'incoming-manager@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->postJson("/api/v1/buildings/{$this->second->id}/staff", [
            'user_id' => $subject->id,
            'role' => RoleCode::Manager->value,
        ])->assertStatus(403);

        $this->assertFalse($subject->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->second));
        $this->assertSame(0, RoleUser::query()->where('user_id', $subject->id)->count());
    }

    /**
     * First criterion in full: the duty officer and the security officer are on
     * the same list as the manager, and nothing else is.
     */
    public function test_the_warden_grants_the_duty_officer_and_security_roles_as_well(): void
    {
        Sanctum::actingAs($this->wardenOfFirst);

        foreach ([RoleCode::DutyOfficer, RoleCode::SecurityOfficer] as $index => $role) {
            $subject = User::factory()->create(['email' => sprintf('staff-%d@example.test', $index)]);

            $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
                'user_id' => $subject->id,
                'role' => $role->value,
            ])->assertStatus(201);

            $this->assertTrue($subject->fresh()->hasRoleInBuilding($role, $this->first));
        }
    }

    /**
     * Third criterion, first half: «the warden cannot grant the administrator
     * role nor the warden role».
     */
    public function test_the_warden_grants_neither_the_administrator_role_nor_the_warden_role(): void
    {
        $subject = User::factory()->create(['email' => 'ambitious@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        foreach ([RoleCode::Administrator, RoleCode::Warden] as $role) {
            $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
                'user_id' => $subject->id,
                'role' => $role->value,
            ])->assertStatus(403);
        }

        $this->assertSame(0, RoleUser::query()->where('user_id', $subject->id)->count());
    }

    /**
     * Third criterion, second half: «a manager cannot grant any staff role at
     * all». Every one of the four, including the manager's own.
     */
    public function test_a_manager_grants_no_staff_role_at_all(): void
    {
        $manager = User::factory()
            ->withRole(RoleCode::Manager, $this->first)
            ->create(['email' => 'manager-of-first@example.test']);

        $subject = User::factory()->create(['email' => 'candidate@example.test']);

        Sanctum::actingAs($manager);

        $roles = [
            RoleCode::Administrator,
            RoleCode::Warden,
            RoleCode::Manager,
            RoleCode::DutyOfficer,
            RoleCode::SecurityOfficer,
        ];

        foreach ($roles as $role) {
            $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
                'user_id' => $subject->id,
                'role' => $role->value,
            ])->assertStatus(403);
        }

        $this->assertSame(0, RoleUser::query()->where('user_id', $subject->id)->count());
    }

    /**
     * The chain of appointment above the warden: the system administrator
     * appoints the warden and, through this route, nobody of his own kind.
     */
    public function test_the_administrator_appoints_the_warden_and_no_second_administrator(): void
    {
        $administrator = User::factory()
            ->withRole(RoleCode::Administrator, null)
            ->create(['email' => 'admin@example.test']);

        $subject = User::factory()->create(['email' => 'incoming-warden@example.test']);

        Sanctum::actingAs($administrator);

        $this->postJson("/api/v1/buildings/{$this->second->id}/staff", [
            'user_id' => $subject->id,
            'role' => RoleCode::Warden->value,
        ])->assertStatus(201);

        $this->assertTrue($subject->fresh()->hasRoleInBuilding(RoleCode::Warden, $this->second));

        $this->postJson("/api/v1/buildings/{$this->second->id}/staff", [
            'user_id' => $subject->id,
            'role' => RoleCode::Administrator->value,
        ])->assertStatus(403);
    }

    /**
     * First criterion, the revocation half. The warden takes back what he may
     * hand out, in his own building and nowhere else.
     */
    public function test_the_warden_revokes_the_manager_role_inside_their_own_building(): void
    {
        $manager = User::factory()
            ->withRole(RoleCode::Manager, $this->first)
            ->create(['email' => 'outgoing-manager@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->deleteJson("/api/v1/buildings/{$this->first->id}/staff/{$manager->id}/manager")
            ->assertStatus(204);

        $this->assertFalse($manager->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->first));
    }

    public function test_the_warden_revokes_no_role_in_another_building(): void
    {
        $manager = User::factory()
            ->withRole(RoleCode::Manager, $this->second)
            ->create(['email' => 'manager-of-second@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->deleteJson("/api/v1/buildings/{$this->second->id}/staff/{$manager->id}/manager")
            ->assertStatus(403);

        $this->assertTrue($manager->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->second));
    }

    /**
     * A warden who could revoke another warden could remove the one account
     * above him in his own building, so the revocation list is the same list
     * as the appointment list.
     */
    public function test_the_warden_revokes_no_warden(): void
    {
        $colleague = User::factory()
            ->withRole(RoleCode::Warden, $this->first)
            ->create(['email' => 'second-warden@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->deleteJson("/api/v1/buildings/{$this->first->id}/staff/{$colleague->id}/warden")
            ->assertStatus(403);

        $this->assertTrue($colleague->fresh()->hasRoleInBuilding(RoleCode::Warden, $this->first));
    }

    public function test_revoking_a_role_the_account_does_not_hold_answers_404(): void
    {
        $subject = User::factory()->create(['email' => 'unrelated@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->deleteJson("/api/v1/buildings/{$this->first->id}/staff/{$subject->id}/manager")
            ->assertStatus(404);
    }

    /**
     * Fourth criterion: «every grant and revocation is written to the audit log
     * with the acting user, the subject and the time», and the verification
     * adds «one entry per grant naming the subject».
     */
    public function test_every_grant_and_revocation_is_written_to_the_audit_log(): void
    {
        $subject = User::factory()->create(['email' => 'audited@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
            'user_id' => $subject->id,
            'role' => RoleCode::Manager->value,
        ])->assertStatus(201);

        $appointed = AuditLog::query()
            ->where('action', AuditAction::StaffAppointed->value)
            ->get();

        $this->assertCount(1, $appointed);
        $this->assertSame($this->wardenOfFirst->id, $appointed->first()->user_id);
        $this->assertSame($subject->id, $appointed->first()->subject_id);
        $this->assertSame(User::class, $appointed->first()->subject_type);
        $this->assertSame('manager', $appointed->first()->payload['role']);
        $this->assertSame($this->first->id, $appointed->first()->payload['building_id']);
        $this->assertNotNull($appointed->first()->created_at);

        $this->deleteJson("/api/v1/buildings/{$this->first->id}/staff/{$subject->id}/manager")
            ->assertStatus(204);

        $revoked = AuditLog::query()
            ->where('action', AuditAction::StaffRevoked->value)
            ->get();

        $this->assertCount(1, $revoked);
        $this->assertSame($this->wardenOfFirst->id, $revoked->first()->user_id);
        $this->assertSame($subject->id, $revoked->first()->subject_id);
    }

    /**
     * The refusal is an event too (§3.9.6): an attempt to appoint in another
     * building is what the administrator reading the log needs to see.
     */
    public function test_a_refused_appointment_is_written_to_the_audit_log(): void
    {
        $subject = User::factory()->create(['email' => 'elsewhere@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->postJson("/api/v1/buildings/{$this->second->id}/staff", [
            'user_id' => $subject->id,
            'role' => RoleCode::Manager->value,
        ])->assertStatus(403);

        $this->assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::AccessDenied->value)->count(),
        );
    }

    /**
     * Appointing twice is one appointment. The partial unique index says a
     * grant exists once, and the log is to hold one entry per grant.
     */
    public function test_a_repeated_appointment_writes_neither_a_second_grant_nor_a_second_entry(): void
    {
        $subject = User::factory()->create(['email' => 'twice@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $payload = ['user_id' => $subject->id, 'role' => RoleCode::Manager->value];

        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", $payload)->assertStatus(201);
        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", $payload)->assertStatus(200);

        $managerRole = Role::query()->where('code', RoleCode::Manager->value)->sole();

        $this->assertSame(
            1,
            RoleUser::query()
                ->where('user_id', $subject->id)
                ->where('role_id', $managerRole->getKey())
                ->count(),
        );

        $this->assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::StaffAppointed->value)->count(),
        );
    }

    public function test_a_role_this_system_does_not_know_is_a_malformed_request(): void
    {
        $subject = User::factory()->create(['email' => 'nonsense@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
            'user_id' => $subject->id,
            'role' => 'caretaker',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    /**
     * The first finding of the acceptance of 14.09.2026, reproduced in the
     * words it was reported in.
     *
     * The warden of block 1, with no other grant, read the full card of any
     * resident of block 2 in two permitted calls:
     *
     *     GET  /residents/9                                        → 403
     *     POST /buildings/1/staff {"user_id":9,"role":"security"}   → 201
     *     GET  /residents/9                                        → 200
     *     DELETE /buildings/1/staff/9/security                      → 204
     *
     * Two decisions, each right on its own, met in the middle:
     * `AppointStaffRequest` took any identifier in the system, and the card was
     * attached to a building by *any* role grant. The appointment manufactured
     * the attachment the reader was then judged against. Identifiers are
     * sequential, so this was the register of every dormitory, one integer at a
     * time.
     *
     * Both halves are closed, so the sequence now stops at the second line.
     */
    public function test_a_resident_of_another_dormitory_cannot_be_appointed_here(): void
    {
        $residentOfSecond = User::factory()
            ->withRole(RoleCode::Resident, $this->second)
            ->create(['email' => 'resident-of-second@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->getJson("/api/v1/residents/{$residentOfSecond->id}")->assertStatus(403);

        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
            'user_id' => $residentOfSecond->id,
            'role' => RoleCode::SecurityOfficer->value,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->assertSame(
            1,
            RoleUser::query()->where('user_id', $residentOfSecond->id)->count(),
            'A grant was written into block 1 for a resident of block 2.',
        );

        $this->getJson("/api/v1/residents/{$residentOfSecond->id}")->assertStatus(403);
    }

    /**
     * The second lock, tested on its own. The grant is written straight into
     * the table, past the route and past the rule that now refuses it, and the
     * card stays shut: a staff grant says where somebody works and never whose
     * register answers for their personal data.
     *
     * The two locks are independent on purpose. Either one of them alone stops
     * the escalation, and this test is what says so.
     */
    public function test_a_staff_grant_does_not_open_the_card_of_the_account_that_holds_it(): void
    {
        $residentOfSecond = User::factory()
            ->withRole(RoleCode::Resident, $this->second)
            ->create(['email' => 'resident-of-second@example.test']);

        $security = Role::query()->where('code', RoleCode::SecurityOfficer->value)->sole();

        RoleUser::query()->create([
            'user_id' => $residentOfSecond->getKey(),
            'role_id' => $security->getKey(),
            'building_id' => $this->first->getKey(),
            'granted_by' => $this->wardenOfFirst->getKey(),
            'granted_at' => now(),
        ]);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->getJson("/api/v1/residents/{$residentOfSecond->id}")->assertStatus(403);
    }

    /**
     * What the rule deliberately does not refuse. A warden hiring a security
     * officer from outside is the ordinary case, and a route unable to do it
     * would be a route with no purpose.
     */
    public function test_an_account_no_dormitory_answers_for_is_appointed_as_before(): void
    {
        $outsider = User::factory()->create(['email' => 'hired-from-outside@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
            'user_id' => $outsider->id,
            'role' => RoleCode::SecurityOfficer->value,
        ])->assertStatus(201);

        // And his own card is nobody's business but his and the
        // administrator's: a staff grant attaches him to no register.
        $this->getJson("/api/v1/residents/{$outsider->id}")->assertStatus(403);
    }

    /**
     * The other case the rule leaves alone: a resident of **this** dormitory
     * taking the duty officer's shift. The person is already inside the scope,
     * so the appointment hands the warden nothing he did not have.
     */
    public function test_a_resident_of_this_dormitory_may_be_given_a_staff_role_here(): void
    {
        $residentOfFirst = User::factory()
            ->withRole(RoleCode::Resident, $this->first)
            ->create(['email' => 'resident-of-first@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
            'user_id' => $residentOfFirst->id,
            'role' => RoleCode::DutyOfficer->value,
        ])->assertStatus(201);

        $this->assertTrue($residentOfFirst->fresh()->hasRoleInBuilding(RoleCode::DutyOfficer, $this->first));
    }

    /**
     * The MVP chain of 14.09.2026: the administrator hands out the warden's
     * role and stops there. Manager, duty officer and security officer are the
     * warden's appointments, made inside the building he answers for, and the
     * administrator asking for one of them directly is refused — which is the
     * short way past the warden that must not exist.
     */
    public function test_the_administrator_appoints_no_staff_role_beneath_the_warden(): void
    {
        $administrator = User::factory()
            ->withRole(RoleCode::Administrator, null)
            ->create(['email' => 'admin@example.test']);

        Sanctum::actingAs($administrator);

        $roles = [RoleCode::Manager, RoleCode::DutyOfficer, RoleCode::SecurityOfficer];

        foreach ($roles as $index => $role) {
            $subject = User::factory()->create(['email' => sprintf('staff-of-second-%d@example.test', $index)]);

            $this->postJson("/api/v1/buildings/{$this->second->id}/staff", [
                'user_id' => $subject->id,
                'role' => $role->value,
            ])->assertStatus(403);

            $this->assertSame(0, RoleUser::query()->where('user_id', $subject->id)->count());
        }
    }

    /**
     * Revocation is asked of the same list as appointment, so the line above
     * the warden holds in both directions: a grant the administrator may not
     * write is a grant he may not take back either.
     */
    public function test_the_administrator_revokes_no_staff_role_beneath_the_warden(): void
    {
        $manager = User::factory()
            ->withRole(RoleCode::Manager, $this->first)
            ->create(['email' => 'manager-of-first@example.test']);

        $administrator = User::factory()
            ->withRole(RoleCode::Administrator, null)
            ->create(['email' => 'admin@example.test']);

        Sanctum::actingAs($administrator);

        $this->deleteJson("/api/v1/buildings/{$this->first->id}/staff/{$manager->id}/manager")
            ->assertStatus(403);

        $this->assertTrue($manager->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->first));
    }

    /**
     * The grant the route writes names the building from the path, and the
     * payload has no say in it — the mistake `StoreResidencyRequest` avoids by
     * reading the scope off the bed.
     */
    public function test_the_payload_cannot_nominate_the_building_the_grant_names(): void
    {
        $subject = User::factory()->create(['email' => 'smuggler@example.test']);

        Sanctum::actingAs($this->wardenOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/staff", [
            'user_id' => $subject->id,
            'role' => RoleCode::Manager->value,
            'building_id' => $this->second->id,
        ])->assertStatus(201);

        $this->assertTrue($subject->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->first));
        $this->assertFalse($subject->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->second));
    }
}
