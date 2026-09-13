<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Models\Announcement;
use App\Models\AnnouncementAck;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAGuestScenario;
use Tests\TestCase;

/**
 * FR-12, «Acknowledgement of reading», one test per acceptance criterion, and
 * the dedicated authorisation test §4.6.1 asks for by name.
 *
 * `GET /api/v1/announcements/{id}/readers` returns a named list of residents
 * who have not complied with an instruction. §4.6.1 calls it «the natural place
 * for a horizontal access leak», and the two tests at the foot of this file are
 * the reason it is not one: a warden of one dormitory is refused another's
 * announcement outright, and on an announcement addressed to **every**
 * dormitory — where he legitimately may look — the names he is shown are his
 * own residents. The second case is the one a policy alone does not cover, and
 * it is the one that would have leaked.
 */
final class AnnouncementAcknowledgementTest extends TestCase
{
    use BuildsAGuestScenario, RefreshDatabase;

    private Building $blockA;

    private Building $blockB;

    private User $wardenOfA;

    private User $wardenOfB;

    private User $administrator;

    private User $anna;

    private User $boris;

    private User $residentOfB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->blockA = $this->dormitory('Block A');
        $this->blockB = $this->dormitory('Block B');

        $this->wardenOfA = $this->staff(RoleCode::Warden, $this->blockA, 'warden.a@example.test');
        $this->wardenOfB = $this->staff(RoleCode::Warden, $this->blockB, 'warden.b@example.test');
        $this->administrator = $this->staff(RoleCode::Administrator, null, 'admin@example.test');

        // Two residents of block A with names that sort predictably, because
        // the report is ordered by name and the assertions read positions.
        $this->anna = $this->residentOf($this->blockA, 'anna@example.test', '305');
        $this->anna->update(['full_name' => 'Anna Zheltova']);

        $this->boris = $this->residentOf($this->blockA, 'boris@example.test', '306');
        $this->boris->update(['full_name' => 'Boris Kurbatov']);

        $this->residentOfB = $this->residentOf($this->blockB, 'vera@example.test', '412');
        $this->residentOfB->update(['full_name' => 'Vera Shilova']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * First criterion: «for announcements of category mandatory the fact and
     * time of acknowledgement are recorded».
     *
     * The row carries the person and the moment, which is the whole of what
     * the criterion asks to be recorded — and the reason §3.4.1's fifth
     * decision makes it a row rather than a flag.
     */
    public function test_the_acknowledgement_records_the_reader_and_the_moment(): void
    {
        $announcement = $this->mandatoryNoticeFor($this->blockA);

        Sanctum::actingAs($this->anna);

        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $announcement->getKey())
            ->assertJsonPath('data.is_unread', false);

        $ack = AnnouncementAck::query()->sole();

        $this->assertSame($announcement->getKey(), $ack->announcement_id);
        $this->assertSame($this->anna->getKey(), $ack->user_id);
        $this->assertSame(
            CarbonImmutable::now()->toIso8601String(),
            $ack->acknowledged_at->toIso8601String(),
        );
    }

    /**
     * The same criterion under a retry: the acknowledgement is idempotent,
     * because `UNIQUE (announcement_id, user_id)` refuses a second row.
     *
     * A duplicate would show up as an acknowledged share above one, which
     * makes FR-12's report unusable rather than merely wrong.
     */
    public function test_a_repeated_acknowledgement_does_not_write_a_second_row(): void
    {
        $announcement = $this->mandatoryNoticeFor($this->blockA);

        Sanctum::actingAs($this->anna);

        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")->assertStatus(200);

        $firstMoment = AnnouncementAck::query()->sole()->acknowledged_at->toIso8601String();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")->assertStatus(200);

        $this->assertSame(1, AnnouncementAck::query()->count());

        // And the moment on record is the first reading, not the last tap: the
        // question FR-12 answers is when the person was told, and a repeat
        // that moved the date would quietly rewrite the evidence.
        $this->assertSame($firstMoment, AnnouncementAck::query()->sole()->acknowledged_at->toIso8601String());
    }

    /**
     * The acknowledgement is open only to the audience. A row asserting that
     * somebody read a notice they could not see would be worse than no row.
     */
    public function test_a_resident_outside_the_audience_cannot_acknowledge(): void
    {
        $announcement = $this->mandatoryNoticeFor($this->blockA);

        Sanctum::actingAs($this->residentOfB);

        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")
            ->assertStatus(403);

        $this->assertSame(0, AnnouncementAck::query()->count());
    }

    /**
     * Second criterion: «the warden sees the acknowledged share and a named
     * list of those who have not read».
     */
    public function test_the_warden_sees_the_share_and_the_names_of_those_who_have_not_read(): void
    {
        $announcement = $this->mandatoryNoticeFor($this->blockA);

        Sanctum::actingAs($this->anna);
        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")->assertStatus(200);

        Sanctum::actingAs($this->wardenOfA);

        $this->getJson("/api/v1/announcements/{$announcement->getKey()}/readers")
            ->assertStatus(200)
            ->assertJsonPath('data.audience_size', 2)
            ->assertJsonPath('data.acknowledged_count', 1)
            ->assertJsonPath('data.acknowledged_share', 0.5)
            ->assertJsonPath('data.acknowledged.0.full_name', 'Anna Zheltova')
            ->assertJsonPath('data.acknowledged.0.acknowledged_at', CarbonImmutable::now()->toIso8601String())
            ->assertJsonCount(1, 'data.not_acknowledged')
            // The name is the substance of the criterion: «I was not told» is
            // only a checkable statement if the list says who.
            ->assertJsonPath('data.not_acknowledged.0.full_name', 'Boris Kurbatov')
            ->assertJsonPath('data.not_acknowledged.0.user_id', $this->boris->getKey());
    }

    /**
     * A resident does not read the list of who has read. It is personal data
     * about their neighbours, assembled for a purpose that is the warden's.
     */
    public function test_a_resident_cannot_read_the_list_of_readers(): void
    {
        $announcement = $this->mandatoryNoticeFor($this->blockA);

        Sanctum::actingAs($this->anna);

        $this->getJson("/api/v1/announcements/{$announcement->getKey()}/readers")
            ->assertStatus(403);
    }

    /**
     * §4.6.1's dedicated authorisation test, first half: **a warden of block A
     * gets no reader of block B.**
     *
     * The announcement names block B, the caller's grant names block A, and
     * the refusal comes from the policy before any name is assembled. It is
     * written to the audit log as `access.denied` by the handler.
     */
    public function test_a_warden_of_one_dormitory_gets_no_reader_of_another(): void
    {
        $announcement = $this->mandatoryNoticeFor($this->blockB);

        Sanctum::actingAs($this->wardenOfA);

        $this->getJson("/api/v1/announcements/{$announcement->getKey()}/readers")
            ->assertStatus(403);

        $this->assertTrue(
            AuditLog::query()->where('action', AuditAction::AccessDenied->value)->exists()
        );
    }

    /**
     * The same test's second half, and the case a policy alone does not cover.
     *
     * An announcement addressed to **every** dormitory has an audience of
     * every resident of every dormitory, and the warden of block A may
     * legitimately ask who of *his* residents has acknowledged it. The policy
     * therefore says yes — and the names returned are narrowed to his own
     * building by `AnnouncementQuery::readers()`. Without that second
     * narrowing the route would answer with the whole register, which is
     * exactly the leak §4.6.1 warns the module about.
     */
    public function test_the_readers_of_an_all_buildings_notice_are_narrowed_to_the_callers_own_dormitory(): void
    {
        $announcement = Announcement::factory()
            ->toEveryBuilding()
            ->by($this->administrator)
            ->mandatory()
            ->create(['title' => 'Passes for the winter holidays']);

        // Somebody in each dormitory acknowledges, so that both lists would be
        // non-empty if the scope were wrong.
        Sanctum::actingAs($this->anna);
        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")->assertStatus(200);

        Sanctum::actingAs($this->residentOfB);
        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")->assertStatus(200);

        Sanctum::actingAs($this->wardenOfA);

        $report = $this->getJson("/api/v1/announcements/{$announcement->getKey()}/readers")
            ->assertStatus(200)
            ->assertJsonPath('data.audience_size', 2)
            ->assertJsonPath('data.acknowledged_count', 1)
            ->json('data');

        $this->assertSame([$this->blockA->getKey()], $report['building_ids']);

        $named = array_merge(
            array_column($report['acknowledged'], 'full_name'),
            array_column($report['not_acknowledged'], 'full_name'),
        );

        $this->assertEqualsCanonicalizing(['Anna Zheltova', 'Boris Kurbatov'], $named);
        $this->assertNotContains('Vera Shilova', $named);

        // The administrator, whose grant names no dormitory, sees all three.
        Sanctum::actingAs($this->administrator);

        $this->getJson("/api/v1/announcements/{$announcement->getKey()}/readers")
            ->assertStatus(200)
            ->assertJsonPath('data.audience_size', 3)
            ->assertJsonPath('data.acknowledged_count', 2);
    }

    /**
     * The reading of the list is itself an event (§3.9.6). It discloses a
     * named list of residents, which is the same ground `resident.card_viewed`
     * rests on; the acknowledgements themselves are not in the log, because
     * the `announcement_acks` row already says who and when.
     */
    public function test_reading_the_list_of_readers_is_written_to_the_audit_log(): void
    {
        $announcement = $this->mandatoryNoticeFor($this->blockA);

        Sanctum::actingAs($this->anna);
        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")->assertStatus(200);

        $this->assertSame(
            0,
            AuditLog::query()->where('action', AuditAction::AnnouncementReadersViewed->value)->count(),
        );

        Sanctum::actingAs($this->wardenOfA);

        $this->getJson("/api/v1/announcements/{$announcement->getKey()}/readers")->assertStatus(200);

        $entry = AuditLog::query()
            ->where('action', AuditAction::AnnouncementReadersViewed->value)
            ->sole();

        $this->assertSame($this->wardenOfA->getKey(), $entry->user_id);
        $this->assertSame($announcement->getKey(), $entry->subject_id);
        $this->assertSame([$this->blockA->getKey()], $entry->payload['building_ids']);
        $this->assertSame(2, $entry->payload['audience_size']);
    }

    /**
     * A resident who has moved out is no longer in the audience, so the warden
     * is not handed their name as somebody who failed to read a notice they
     * could not see (FR-05).
     */
    public function test_a_resident_who_has_moved_out_is_not_counted_among_those_who_have_not_read(): void
    {
        $announcement = $this->mandatoryNoticeFor($this->blockA);

        $this->boris->openResidency?->update([
            'moved_out_at' => CarbonImmutable::now()->subDay()->toDateString(),
            'moved_out_ground' => 'End of the accommodation contract',
        ]);

        Sanctum::actingAs($this->wardenOfA);

        $this->getJson("/api/v1/announcements/{$announcement->getKey()}/readers")
            ->assertStatus(200)
            ->assertJsonPath('data.audience_size', 1)
            ->assertJsonCount(1, 'data.not_acknowledged')
            ->assertJsonPath('data.not_acknowledged.0.full_name', 'Anna Zheltova');
    }

    private function mandatoryNoticeFor(Building $building): Announcement
    {
        return Announcement::factory()
            ->forBuilding($building)
            ->by($building->is($this->blockA) ? $this->wardenOfA : $this->wardenOfB)
            ->mandatory()
            ->create(['title' => 'Reception hours of the warden have changed']);
    }
}
