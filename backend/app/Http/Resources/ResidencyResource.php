<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Residency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One residency, past or present (FR-03, FR-05).
 *
 * `is_open` and `is_current` are both sent, and they are not the same thing.
 * The first says the bed is still held; the second says the person may still
 * use the dormitory. A termination dated in the future makes them differ, and
 * that difference is FR-05's third criterion.
 *
 * The placement fields are flattened onto the record rather than nested behind
 * three relations, because every consumer of a residency wants «block A, room
 * 305, bed 2» and none of them wants to walk BED → ROOM → BUILDING to get it.
 *
 * @mixin Residency
 */
final class ResidencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bed = $this->bed;
        $room = $bed?->room;
        $building = $room?->building;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'resident_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->full_name),
            'bed_id' => $this->bed_id,
            'bed_label' => $bed?->label,
            'room_id' => $room?->id,
            'room_number' => $room?->number,
            'floor' => $room?->floor,
            'building_id' => $building?->id,
            'building_name' => $building?->name,
            'contract_number' => $this->contract_number,
            'moved_in_at' => $this->moved_in_at?->toDateString(),
            'moved_in_ground' => $this->moved_in_ground,
            'moved_out_at' => $this->moved_out_at?->toDateString(),
            'moved_out_ground' => $this->moved_out_ground,
            'status' => $this->status->value,
            'is_open' => $this->isOpen(),
            'is_current' => $this->isCurrentOn(now()),
        ];
    }
}
