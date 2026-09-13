<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One place (FR-02).
 *
 * The bed carries no name. Who holds it is a residency, and a residency is
 * personal data that travels on the resident card of FR-06 under its own
 * policy; a list of forty rooms must not become a roster of forty residents
 * because a relation happened to be loaded.
 *
 * @mixin Bed
 */
final class BedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'label' => $this->label,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}
