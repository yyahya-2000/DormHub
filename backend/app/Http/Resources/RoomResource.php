<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The register entry of one room (FR-02).
 *
 * `capacity`, `beds_count`, `occupied_beds_count` and `free_places` are sent
 * together and not left to the client to subtract. The free remainder is the
 * number FR-02's rejection has to name, and a client that computed it itself
 * could arrive at a different figure from the one the register refused on.
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
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'beds_count' => $this->bedsCount(),
            'occupied_beds_count' => $this->occupiedBedsCount(),
            'free_places' => $this->freePlaces(),
            'beds' => BedResource::collection($this->whenLoaded('beds')),
        ];
    }
}
