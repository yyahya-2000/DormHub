<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `ROOM.type` of the ER model (§3.4.3), in the three layouts FR-01 names:
 * corridor, block and apartment. The layout belongs to the room rather than
 * to the dormitory as a whole, because one building routinely mixes them —
 * a corridor floor above a block floor — and the register has to be able to
 * say which one a given room sits on.
 *
 * A backed enumeration and not a lookup table (§4.4.2): a fourth layout means
 * new code, so a row an administrator could insert would do nothing.
 */
enum RoomType: string
{
    case Corridor = 'corridor';
    case Block = 'block';
    case Apartment = 'apartment';

    public function label(): string
    {
        return match ($this) {
            self::Corridor => 'Corridor type',
            self::Block => 'Block type',
            self::Apartment => 'Apartment type',
        };
    }
}
