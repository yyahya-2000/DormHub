<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FR-01, second criterion: deleting a dormitory that has rooms attached is
 * blocked **with a stated reason**.
 *
 * The block itself is `ON DELETE RESTRICT` on `rooms.building_id`, which
 * §4.4.1 puts in the database rather than in a controller precisely so that it
 * holds against code that forgot to ask. What this class adds is the reason:
 * the database says «foreign key violation», and the warden needs to read how
 * many rooms stand in the way and what to do about them.
 */
final class RegistryDeletionBlockedException extends RuntimeException
{
    /**
     * @param  array<string, int>  $dependants  What is attached, by kind and count.
     */
    public function __construct(
        public readonly string $entity,
        public readonly int $entityId,
        public readonly array $dependants,
        string $reason,
    ) {
        parent::__construct($reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'entity' => $this->entity,
            'entity_id' => $this->entityId,
            'blocked_by' => $this->dependants,
        ];
    }
}
