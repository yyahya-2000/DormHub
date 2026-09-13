<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The outward shape of a user. The password hash and the remember token are
 * hidden on the model and absent here; the JSON contract is written out field
 * by field rather than derived from the table, which is the point of a Data
 * Transfer Object (§3.3.3).
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'roles' => RoleGrantResource::collection($this->whenLoaded('roleGrants')),
        ];
    }
}
