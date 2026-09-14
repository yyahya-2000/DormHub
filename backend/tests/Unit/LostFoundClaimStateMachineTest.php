<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LostFoundClaimStatus;
use App\Exceptions\IllegalTransitionException;
use App\LostFound\LostFoundClaimStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * FR-26's own graph, beside the entry's, at the level §4.7.1 puts a transition
 * table.
 */
final class LostFoundClaimStateMachineTest extends TestCase
{
    private LostFoundClaimStateMachine $states;

    protected function setUp(): void
    {
        parent::setUp();

        $this->states = new LostFoundClaimStateMachine;
    }

    /**
     * The five edges FR-26's sentences draw.
     *
     * @return list<array{LostFoundClaimStatus, LostFoundClaimStatus}>
     */
    public static function theGraph(): array
    {
        return [
            // FR-26: the person holding the object answers.
            [LostFoundClaimStatus::New, LostFoundClaimStatus::Accepted],
            [LostFoundClaimStatus::New, LostFoundClaimStatus::Declined],
            // FR-26, §2.4.4: «the claimant is offered the option of referring
            // the decision to the warden».
            [LostFoundClaimStatus::Declined, LostFoundClaimStatus::Referred],
            // FR-26, first criterion: «a warden's decision on a referred
            // claim».
            [LostFoundClaimStatus::Referred, LostFoundClaimStatus::Accepted],
            [LostFoundClaimStatus::Referred, LostFoundClaimStatus::Declined],
        ];
    }

    public function test_only_the_transitions_of_the_defined_graph_are_permitted(): void
    {
        foreach (self::theGraph() as [$from, $to]) {
            $this->assertTrue(
                $this->states->allows($from, $to),
                sprintf('FR-26 admits %s → %s and the table refuses it.', $from->value, $to->value),
            );
        }
    }

    /**
     * The negative space, exhaustively.
     */
    public function test_an_attempt_at_any_other_transition_is_rejected(): void
    {
        $permitted = array_map(
            static fn (array $pair): string => $pair[0]->value.'→'.$pair[1]->value,
            self::theGraph(),
        );

        foreach (LostFoundClaimStatus::cases() as $from) {
            foreach (LostFoundClaimStatus::cases() as $to) {
                if ($from === $to || in_array($from->value.'→'.$to->value, $permitted, true)) {
                    continue;
                }

                $this->assertFalse(
                    $this->states->allows($from, $to),
                    sprintf('%s → %s is not in FR-26 and the table admits it.', $from->value, $to->value),
                );
            }
        }
    }

    /**
     * An accepted claim is an ending. The claimant has been told where to
     * collect the object, and a claim that could be un-accepted would mean a
     * person sent to a staircase for nothing.
     */
    public function test_an_accepted_claim_leads_nowhere(): void
    {
        $this->assertSame([], $this->states->reachableFrom(LostFoundClaimStatus::Accepted));
    }

    /**
     * A claim nobody has answered cannot go to the warden: FR-26 gives the
     * warden «a claim the two sides cannot settle», and two sides that have
     * not spoken have not failed to settle anything.
     */
    public function test_a_claim_nobody_has_answered_cannot_be_referred_to_the_warden(): void
    {
        $this->assertFalse($this->states->allows(
            LostFoundClaimStatus::New,
            LostFoundClaimStatus::Referred,
        ));
    }

    public function test_the_refused_transition_names_both_of_its_ends(): void
    {
        $this->expectException(IllegalTransitionException::class);

        try {
            $this->states->assert(
                LostFoundClaimStatus::Accepted,
                LostFoundClaimStatus::Declined,
            );
        } catch (IllegalTransitionException $exception) {
            $this->assertSame(
                [
                    'status' => 'accepted',
                    'attempted_status' => 'declined',
                ],
                $exception->context(),
            );

            throw $exception;
        }
    }

    /**
     * The table is what a client draws its buttons from.
     */
    public function test_the_table_says_what_a_screen_may_offer(): void
    {
        $this->assertSame(
            [LostFoundClaimStatus::Referred],
            $this->states->reachableFrom(LostFoundClaimStatus::Declined),
        );
    }
}
