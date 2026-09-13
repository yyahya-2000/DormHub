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
            'is_active' => $this->is_active,
        ];
    }
}
