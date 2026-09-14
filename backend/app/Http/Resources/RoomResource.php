<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The register entry of one room (FR-02).
 *
 * The five occupancy figures are sent together and not left to the client to
 * subtract. `free_places` is the number FR-02's rejection has to name — how
 * many more beds may be **registered** — and a client that computed it itself
 * could arrive at a different figure from the one the register refused on.
 * `vacant_beds` is the other question, the one a warden looking for somewhere
 * to put an arrival is actually asking: how many registered places nobody
 * holds. A full room and a room whose every place is taken both report
 * `free_places: 0`, and they are not the same room.
 *
 * @mixin Room
 */
final class RoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'number' => $this->number,
            'floor' => $this->floor,
            'capacity' => $this->capacity,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'beds_count' => $this->bedsCount(),
            'occupied_beds_count' => $this->occupiedBedsCount(),
            'free_places' => $this->freePlaces(),
            'vacant_beds' => $this->vacantBeds(),
            'beds' => BedResource::collection($this->whenLoaded('beds')),
        ];
    }
}
