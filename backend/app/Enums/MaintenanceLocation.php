<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * FR-36's location: «own room or a named common area».
 *
 * Two values and not a free-text field, because the two behave differently at
 * the moment the request is written and the difference is the acceptance
 * criterion of FR-36. «Own room» binds the request to the room of the
 * submitter's active residency record — the system reads the register and does
 * not ask the resident which room they live in, since a client that could say
 * would be a client that could say somebody else's. A common area is named in
 * words, because the register holds rooms and beds and knows nothing about the
 * kitchen on the fourth floor.
 *
 * So `MAINTENANCE_REQUEST.room_id` is NULL for a common area, which is what
 * the ER diagram's «NULL for common areas» means, and the name of the place
 * travels in `location_note`.
 */
enum MaintenanceLocation: string
{
    /** The room of the submitter's active residency record. */
    case OwnRoom = 'own_room';

    /** A named common area: a kitchen, a shower room, a corridor, a lift. */
    case CommonArea = 'common_area';

    public function label(): string
    {
        return match ($this) {
            self::OwnRoom => 'My own room',
            self::CommonArea => 'A common area',
        };
    }

    public function bindsToARoom(): bool
    {
        return $this === self::OwnRoom;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $location): string => $location->value, self::cases());
    }
}
