<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * §3.3.4: `approve()` «asserts the per-resident and per-building daily quota».
 *
 * The quota is a house rule rather than a norm — the HSE rules of internal
 * order set no number — so both ceilings are settings and the exception
 * carries the one that was hit along with the count that hit it. A duty
 * officer told «quota exceeded» and nothing else cannot tell whether the
 * resident has had three guests today or the building has had fifty.
 *
 * 422 and not 409: the decision the officer asked for is one the rules do not
 * allow at all today, and there is no state they can wait for that would make
 * the same call succeed before midnight.
 */
final class GuestQuotaExceededException extends RuntimeException
{
    private function __construct(
        public readonly string $scope,
        public readonly int $limit,
        public readonly int $approved,
        public readonly string $onDate,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forResident(int $limit, int $approved, string $onDate): self
    {
        return new self(
            scope: 'resident',
            limit: $limit,
            approved: $approved,
            onDate: $onDate,
            message: sprintf(
                'This resident already has %d approved guest(s) for %s, and the dormitory allows %d a day.',
                $approved,
                $onDate,
                $limit,
            ),
        );
    }

    public static function forBuilding(int $limit, int $approved, string $onDate): self
    {
        return new self(
            scope: 'building',
            limit: $limit,
            approved: $approved,
            onDate: $onDate,
            message: sprintf(
                'The dormitory already has %d approved guest(s) for %s, and its daily ceiling is %d.',
                $approved,
                $onDate,
                $limit,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'quota_scope' => $this->scope,
            'quota_limit' => $this->limit,
            'approved_today' => $this->approved,
            'visit_date' => $this->onDate,
        ];
    }
}
