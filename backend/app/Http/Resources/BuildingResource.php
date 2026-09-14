<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Building
 */
final class BuildingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'floors_count' => $this->floors_count,
            'visiting_from' => $this->visiting_from,
            'visiting_to' => $this->visiting_to,
            'curfew_at' => $this->curfew_at,
            // FR-16, first criterion. The fourth regime setting beside the
            // three above, and readable for the same reason they are: a
            // screen that cannot show the notice period cannot let anybody
            // check it, and a client that never receives it cannot send it
            // back unchanged.
            'guest_lead_time_hours' => $this->guest_lead_time_hours,
            'is_active' => $this->is_active,
        ];
    }
}
