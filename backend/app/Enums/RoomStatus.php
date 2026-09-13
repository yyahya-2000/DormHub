<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `ROOM.status` of the ER model (§3.4.3). A room that is not in service holds
 * no new residency: repairs and withdrawal from the housing stock are the two
 * reasons a room with free beds must still refuse them (FR-02, FR-03).
 */
enum RoomStatus: string
{
    case InService = 'in_service';
    case UnderRepair = 'under_repair';
    case Withdrawn = 'withdrawn';

    /**
     * Whether a bed in this room may be assigned to anybody.
     */
    public function acceptsResidents(): bool
    {
        return $this === self::InService;
    }

    public function label(): string
    {
        return match ($this) {
            self::InService => 'In service',
            self::UnderRepair => 'Under repair',
            self::Withdrawn => 'Withdrawn from the housing stock',
        };
    }
}
