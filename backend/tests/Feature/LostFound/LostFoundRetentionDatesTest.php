<?php

declare(strict_types=1);

namespace Tests\Feature\LostFound;

use App\Enums\LostFoundCustody;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\LostFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsALostFoundScenario;
use Tests\TestCase;

/**
 * §2.4.4's third scenario, and the boundary it runs into.
 *
 * ```gherkin
 * Scenario: expiry of the retention period
 *   Given a find deposited with the administration whose declaration date is
 *         six months in the past
 *   When the control date is reached
 *   Then the system marks the record "retention period expired" and notifies
 *        the warden
 * ```
 *
 * **That scenario is FR-27, which is Could priority and outside the MVP as an
 * automated control** (§2.5.4). Nothing in this increment counts the six
 * months of Civil Code art. 228 cl. 1, there is no scheduled pass, there is no
 * flag on the row and there is no report; a behaviour test of the three lines
 * above would be a test of code that does not exist, and writing one that
 * passed would mean writing the code and breaking the rule that the technical
 * specification describes only what was built.
 *
 * **What the MVP does carry is the pair of dates the period will be counted
 * from**, and the reason it carries them now is the one §2.5.4 gives:
 * «retrofitting a declaration date onto records created without one is not
 * possible after the fact». The clock runs from `declared_on` and never from
 * `happened_on` and never from the registration — so a record entered today
 * without the declaration date can never be made to answer the question later,
 * whatever the control is written to do.
 *
 * This file therefore asserts the part of the scenario the MVP is responsible
 * for: that both dates exist, that they are two different facts, that the
 * deposited path populates the second, and that nothing infers it. The three
 * lines above are recorded here so that whoever implements FR-27 finds the
 * scenario beside the columns it needs.
 */
final class LostFoundRetentionDatesTest extends TestCase
{
    use BuildsALostFoundScenario, RefreshDatabase;

    private Building $building;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->officer = $this->staff(RoleCode::SecurityOfficer, $this->building, 'post@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * §3.4.2: «LOST_FOUND_ITEM carries two dates that must not be confused».
     */
    public function test_the_record_carries_the_day_of_the_finding_and_the_day_of_the_declaration_as_two_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('lost_found_items', 'happened_on'));
        $this->assertTrue(Schema::hasColumn('lost_found_items', 'declared_on'));
    }

    /**
     * «Given a find deposited with the administration whose declaration date
     * is six months in the past» — the given of the scenario, which the MVP
     * can produce even though it does nothing with it.
     */
    public function test_a_find_deposited_with_the_administration_records_the_date_of_the_declaration(): void
    {
        $declaredOn = CarbonImmutable::now()->subMonths(6);

        Sanctum::actingAs($this->officer);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'title' => 'A student card in a blue sleeve',
            'place' => 'Handed in at the security post',
            'happened_on' => $declaredOn->subDay()->toDateString(),
            'custody' => LostFoundCustody::Administration->value,
            'declared_on' => $declaredOn->toDateString(),
        ]))->assertStatus(201);

        $deposited = LostFoundItem::query()->findOrFail($response->json('data.id'));

        $this->assertSame(LostFoundCustody::Administration, $deposited->custody);
        $this->assertSame($declaredOn->toDateString(), $deposited->declared_on?->toDateString());
        $this->assertNotSame(
            $deposited->happened_on?->toDateString(),
            $deposited->declared_on?->toDateString(),
            'The two dates are one fact, which §3.4.2 says they must not be.',
        );
    }

    /**
     * The clock runs from the declaration and never from the registration, so
     * a record nobody declared has no clock at all: `declared_on` stays null
     * and the six-month period of art. 228 cl. 1 simply does not start.
     *
     * Nothing here infers a date from `created_at`, which is the single
     * mistake §3.4.2 warns against.
     */
    public function test_a_find_nobody_declared_starts_no_clock(): void
    {
        Sanctum::actingAs($this->officer);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'custody' => LostFoundCustody::Administration->value,
        ]))->assertStatus(201)->assertJsonPath('data.declared_on', null);

        $deposited = LostFoundItem::query()->findOrFail($response->json('data.id'));

        $this->assertNull($deposited->declared_on);
        $this->assertNotNull($deposited->created_at);
        $this->assertNotNull($deposited->happened_on);
    }

    /**
     * «When the control date is reached» — and nothing happens, which is the
     * MVP boundary stated as an assertion rather than as a comment.
     *
     * FR-27 is outside this increment: there is no column the control would
     * write its flag into, and a stand that grew one without the requirement
     * being brought inside the MVP would be a stand whose technical
     * specification had stopped describing it.
     */
    public function test_the_mvp_runs_no_retention_control_over_those_dates(): void
    {
        foreach (['retention_expired', 'retention_expired_at', 'retention_flagged_at'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('lost_found_items', $column),
                sprintf(
                    'FR-27 is outside the MVP and «%s» has appeared: bring the requirement inside '
                    .'the specification before the column.',
                    $column,
                ),
            );
        }

        $this->assertNull(
            config('dormitory.lost_found.retention_months'),
            'A retention period has been configured for a control the MVP does not run.',
        );
    }
}
