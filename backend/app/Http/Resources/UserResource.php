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
            /*
             * FR-42. An account created for an incoming resident is waiting
             * for its first password, and the client has to know which screen
             * to draw. The flag says that much and no more: the secret itself
             * never appears in this resource, and there is no field here it
             * could appear in.
             */
            'password_change_required' => (bool) $this->password_change_required,
            'roles' => RoleGrantResource::collection($this->whenLoaded('roleGrants')),
        ];
    }
}
