<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One in-app notification as the personal account reads it (FR-34).
 *
 * `category` is lifted out of the stored payload into a field of its own,
 * because it is the one thing a client groups and filters by and it should not
 * have to know that the payload happens to carry it. The rest of the payload is
 * passed through: each notification decides its own shape in `toDatabase()`,
 * and a resource that enumerated the fields would have to be edited for every
 * category added — which is the thing FR-34's mechanism exists to avoid.
 *
 * **The field is `payload` and not `data`**, which is the column's name. A
 * resource whose top-level array carries a key called `data` is taken by the
 * framework to have wrapped itself already, and the whole answer then arrives
 * without its `data` envelope — every other route in this API has one, and a
 * generated client would break on this route alone. The audit log resource
 * calls the same thing `payload` for the same reason, so the two agree.
 *
 * @mixin DatabaseNotification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'category' => $data['category'] ?? null,
            'type' => class_basename((string) $this->type),
            'payload' => $data,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
