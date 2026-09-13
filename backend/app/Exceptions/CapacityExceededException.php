<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Room;
use RuntimeException;

/**
 * FR-02, second criterion: an attempt to exceed the capacity of a room is
 * rejected **and the free remainder is shown**. The remainder travels in the
 * exception rather than being recomputed by whatever catches it, because the
 * number the caller is told and the number the register refused on have to be
 * the same number.
 *
 * The application layer knows no status codes (§3.3.1); the translation to
 * 422 happens in bootstrap/app.php.
 */
final class CapacityExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $roomId,
        public readonly string $roomNumber,
        public readonly int $capacity,
        public readonly int $beds,
        public readonly int $freePlaces,
    ) {
        parent::__construct(sprintf(
            'Room %s is registered for %d place(s) and already holds %d; free places remaining: %d.',
            $roomNumber,
            $capacity,
            $beds,
            $freePlaces,
        ));
    }

    public static function forRoom(Room $room): self
    {
        return new self(
            roomId: (int) $room->getKey(),
            roomNumber: (string) $room->number,
            capacity: $room->capacity,
            beds: $room->bedsCount(),
            freePlaces: $room->freePlaces(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'room_id' => $this->roomId,
            'room_number' => $this->roomNumber,
            'capacity' => $this->capacity,
            'beds' => $this->beds,
            'free_places' => $this->freePlaces,
        ];
    }
}
