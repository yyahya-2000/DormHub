<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LostFoundItemStatus;
use App\Exceptions\IllegalTransitionException;
use App\LostFound\LostFoundItemStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * §3.5.5's graph at the level §4.7.1 puts a transition table: «the transition
 * tables admit exactly the listed transitions and refuse the rest».
 *
 * A unit test with no database and no HTTP, for the reason the maintenance
 * one gives: the interesting property of a table is not that the listed moves
 * work but that **nothing else does**, and «nothing else» is six ordered pairs
 * here.
 */
final class LostFoundItemStateMachineTest extends TestCase
{
    private LostFoundItemStateMachine $states;

    protected function setUp(): void
    {
        parent::setUp();

        $this->states = new LostFoundItemStateMachine;
    }

    /**
     * The three edges §3.5.5 draws between the three states the MVP reaches.
     *
     * @return list<array{LostFoundItemStatus, LostFoundItemStatus}>
     */
    public static function theGraph(): array
    {
        return [
            // §3.5.5: «a claim arrives».
            [LostFoundItemStatus::Published, LostFoundItemStatus::Claimed],
            // FR-26, second criterion: «a declined claim returns the find to
            // the published list».
            [LostFoundItemStatus::Claimed, LostFoundItemStatus::Published],
            // FR-26, first criterion: the object went back to its owner.
            [LostFoundItemStatus::Claimed, LostFoundItemStatus::Resolved],
        ];
    }

    public function test_only_the_transitions_of_the_defined_graph_are_permitted(): void
    {
        foreach (self::theGraph() as [$from, $to]) {
            $this->assertTrue(
                $this->states->allows($from, $to),
                sprintf('§3.5.5 draws %s → %s and the table refuses it.', $from->value, $to->value),
            );
        }
    }

    /**
     * The negative space, exhaustively: every ordered pair of the three states
     * §3.5.5 does not draw is refused.
     */
    public function test_an_attempt_at_any_other_transition_is_rejected(): void
    {
        $permitted = array_map(
            static fn (array $pair): string => $pair[0]->value.'→'.$pair[1]->value,
            self::theGraph(),
        );

        foreach (LostFoundItemStatus::cases() as $from) {
            foreach (LostFoundItemStatus::cases() as $to) {
                if ($from === $to || in_array($from->value.'→'.$to->value, $permitted, true)) {
                    continue;
                }

                $this->assertFalse(
                    $this->states->allows($from, $to),
                    sprintf('%s → %s is not in §3.5.5 and the table admits it.', $from->value, $to->value),
                );
            }
        }
    }

    /**
     * FR-26, third criterion: «after closure the record disappears from the
     * public list». A closed entry is out of the graph, and nothing — no
     * second claimant with the page still open, no client replaying a request
     * — brings it back into the feed.
     */
    public function test_a_find_that_has_gone_home_leads_nowhere(): void
    {
        $this->assertSame([], $this->states->reachableFrom(LostFoundItemStatus::Resolved));
    }

    /**
     * The «only» of FR-26's first criterion, at the level the table can hold
     * it: an entry nobody has claimed cannot be closed as returned. The other
     * half of the rule — that the claim must actually have been accepted —
     * is a fact about another table and is asserted over the API.
     */
    public function test_a_find_nobody_has_claimed_cannot_be_closed_as_returned(): void
    {
        $this->assertFalse($this->states->allows(
            LostFoundItemStatus::Published,
            LostFoundItemStatus::Resolved,
        ));
    }

    public function test_the_refused_transition_names_both_of_its_ends(): void
    {
        $this->expectException(IllegalTransitionException::class);

        try {
            $this->states->assert(
                LostFoundItemStatus::Resolved,
                LostFoundItemStatus::Claimed,
            );
        } catch (IllegalTransitionException $exception) {
            $this->assertSame(
                [
                    'status' => 'resolved',
                    'attempted_status' => 'claimed',
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
            [LostFoundItemStatus::Published, LostFoundItemStatus::Resolved],
            $this->states->reachableFrom(LostFoundItemStatus::Claimed),
        );
    }
}
