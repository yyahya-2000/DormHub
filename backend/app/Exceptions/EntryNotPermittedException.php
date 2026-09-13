<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The post asked to record an entry the rules do not admit (FR-18, FR-19).
 *
 * **What this exception is not.** It is not a barrier. The system does not
 * restrict the physical movement of people; FR-20 says so in as many words,
 * and §2.4.2 explains why the requirement is phrased that way — the ground for
 * removing somebody from a dormitory is the university's local act and the act
 * of the security service, never a program. What is refused here is the
 * *record*: the register will not assert that an entry was lawful when the
 * interval says it was not.
 *
 * **`reasonCode` is the discriminator, and the reason it exists.** The second
 * Gherkin scenario of §2.4.2 wants the post to show «outside the permitted
 * interval» **and** to offer the action that admits the guest anyway on the
 * responsible officer's decision, with a mandatory reason. Some refusals carry
 * that offer and some do not — a code that belongs to another dormitory is not
 * a thing an officer's decision can cure — so the client has to be able to
 * tell them apart without parsing a sentence.
 */
final class EntryNotPermittedException extends RuntimeException
{
    public const OUTSIDE_INTERVAL = 'outside_permitted_interval';

    public const NOT_APPROVED = 'request_not_approved';

    public const ALREADY_INSIDE = 'guest_already_inside';

    public const WRONG_DAY = 'wrong_visit_date';

    private function __construct(
        public readonly string $reasonCode,
        public readonly bool $overrideAvailable,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * §2.4.2, second scenario. The one refusal the officer on duty may set
     * aside, and only with a reason of their own in writing.
     */
    public static function outsideInterval(string $from, string $to, string $now): self
    {
        return new self(
            reasonCode: self::OUTSIDE_INTERVAL,
            overrideAvailable: true,
            message: sprintf(
                'Outside the permitted interval: the visit is approved for %s–%s and the time is %s.',
                $from,
                $to,
                $now,
            ),
        );
    }

    public static function wrongDay(string $visitDate): self
    {
        return new self(
            reasonCode: self::WRONG_DAY,
            overrideAvailable: true,
            message: sprintf('The request is for %s, which is not today.', $visitDate),
        );
    }

    public static function notApproved(string $status): self
    {
        return new self(
            reasonCode: self::NOT_APPROVED,
            overrideAvailable: false,
            message: sprintf('The request is «%s» and admits no entry.', $status),
        );
    }

    public static function alreadyInside(): self
    {
        return new self(
            reasonCode: self::ALREADY_INSIDE,
            overrideAvailable: false,
            message: 'An entry is already recorded on this request and no exit is.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'reason_code' => $this->reasonCode,
            'override_available' => $this->overrideAvailable,
            'override_requires_reason' => $this->overrideAvailable,
        ];
    }
}
