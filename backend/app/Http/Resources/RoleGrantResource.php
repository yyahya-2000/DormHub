<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RoleUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One grant: the role and the building it holds in. `building_id` NULL travels
 * outwards unchanged, because the client has to be able to tell a system-wide
 * role from one confined to a dormitory.
 *
 * @mixin RoleUser
 */
final class RoleGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'role' => $this->role?->code->value,
            'role_name' => $this->role?->code->label(),
            'building_id' => $this->building_id,
            'granted_at' => $this->granted_at?->toIso8601String(),
        ];
    }
}
