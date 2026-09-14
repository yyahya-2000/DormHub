<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\MaintenanceRequestStatus;
use App\Exceptions\IllegalTransitionException;
use App\Maintenance\MaintenanceRequestStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * FR-38, first and second criteria, at the level §4.7.1 puts them:
 * «the transition tables admit exactly the listed transitions and refuse the
 * rest».
 *
 * It is a unit test with no database and no HTTP, and the exhaustive pass
 * below is the reason. The interesting property of a transition table is not
 * that the listed moves work — a feature test proves that for the ones a route
 * exercises — but that **nothing else does**, and «nothing else» is thirty
 * pairs. Asserting thirty pairs over HTTP would be thirty round trips through
 * a policy, a form request and a service to find out something about one
 * array.
 */
final class MaintenanceRequestStateMachineTest extends TestCase
{
    private MaintenanceRequestStateMachine $states;

    protected function setUp(): void
    {
        parent::setUp();

        $this->states = new MaintenanceRequestStateMachine;
    }

    /**
     * The graph of FR-38, written out as the requirement words it:
     * «submitted → accepted → in progress → completed → closed; and
     * submitted → rejected», plus FR-39's one edge back.
     *
     * @return list<array{MaintenanceRequestStatus, MaintenanceRequestStatus}>
     */
    public static function theGraph(): array
    {
        return [
            [MaintenanceRequestStatus::Submitted, MaintenanceRequestStatus::Accepted],
            [MaintenanceRequestStatus::Submitted, MaintenanceRequestStatus::Rejected],
            [MaintenanceRequestStatus::Accepted, MaintenanceRequestStatus::InProgress],
            [MaintenanceRequestStatus::InProgress, MaintenanceRequestStatus::Completed],
            [MaintenanceRequestStatus::Completed, MaintenanceRequestStatus::Closed],
            // FR-39: reopening returns the request to «accepted».
            [MaintenanceRequestStatus::Completed, MaintenanceRequestStatus::Accepted],
        ];
    }

    public function test_only_the_transitions_of_the_defined_graph_are_permitted(): void
    {
        foreach (self::theGraph() as [$from, $to]) {
            $this->assertTrue(
                $this->states->allows($from, $to),
                sprintf('FR-38 lists %s → %s and the table refuses it.', $from->value, $to->value),
            );
        }
    }

    /**
     * The negative space, exhaustively: every ordered pair of the six states
     * that FR-38 does not draw is refused. Thirty pairs, of which the six
     * above are the exceptions.
     */
    public function test_an_attempt_at_any_other_transition_is_rejected(): void
    {
        $permitted = array_map(
            static fn (array $pair): string => $pair[0]->value.'→'.$pair[1]->value,
            self::theGraph(),
        );

        foreach (MaintenanceRequestStatus::cases() as $from) {
            foreach (MaintenanceRequestStatus::cases() as $to) {
                if ($from === $to || in_array($from->value.'→'.$to->value, $permitted, true)) {
                    continue;
                }

                $this->assertFalse(
                    $this->states->allows($from, $to),
                    sprintf('%s → %s is not in FR-38 and the table admits it.', $from->value, $to->value),
                );
            }
        }
    }

    /**
     * The two endings are endings. A request that has been closed or refused
     * is out of the graph, and nothing — no second warden with the screen
     * still open, no client replaying a request — brings it back.
     */
    public function test_a_closed_or_rejected_request_leads_nowhere(): void
    {
        $this->assertSame([], $this->states->reachableFrom(MaintenanceRequestStatus::Closed));
        $this->assertSame([], $this->states->reachableFrom(MaintenanceRequestStatus::Rejected));
    }

    /**
     * FR-38's chain is kept strict: a warden cannot report work complete on a
     * request nobody ever started. The convenience is real and is refused,
     * because the graph is the requirement's and not the convenient one — and
     * the work log would otherwise have no answer to «when was it begun».
     */
    public function test_work_cannot_be_reported_complete_on_a_request_that_was_never_started(): void
    {
        $this->assertFalse($this->states->allows(
            MaintenanceRequestStatus::Accepted,
            MaintenanceRequestStatus::Completed,
        ));
    }

    /**
     * A request taken into work is not refused afterwards: FR-37 puts the
     * reason on the refusal, which happens before the acceptance, not on a
     * withdrawal after it.
     */
    public function test_a_request_already_accepted_cannot_be_rejected(): void
    {
        $this->assertFalse($this->states->allows(
            MaintenanceRequestStatus::Accepted,
            MaintenanceRequestStatus::Rejected,
        ));
    }

    public function test_the_refused_transition_names_both_of_its_ends(): void
    {
        $this->expectException(IllegalTransitionException::class);

        try {
            $this->states->assert(
                MaintenanceRequestStatus::Closed,
                MaintenanceRequestStatus::InProgress,
            );
        } catch (IllegalTransitionException $exception) {
            $this->assertSame(
                [
                    'status' => 'closed',
                    'attempted_status' => 'in_progress',
                ],
                $exception->context(),
            );

            throw $exception;
        }
    }

    /**
     * The table is what a client draws its buttons from, so that the interface
     * and the machine cannot drift apart.
     */
    public function test_the_table_says_what_a_screen_may_offer(): void
    {
        $this->assertSame(
            [MaintenanceRequestStatus::Closed, MaintenanceRequestStatus::Accepted],
            $this->states->reachableFrom(MaintenanceRequestStatus::Completed),
        );
    }
}
