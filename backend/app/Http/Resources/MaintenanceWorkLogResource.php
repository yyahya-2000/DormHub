<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MaintenanceWorkLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the work log (§3.4.1, decision 6).
 *
 * `actor_id` null is shown as a null actor and a sentence that says why: the
 * scheduled closure of FR-39 had no person behind it, and a client that filled
 * the gap with «system» would be inventing an author the record deliberately
 * does not have.
 *
 * @mixin MaintenanceWorkLog
 */
final class MaintenanceWorkLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'summary' => $this->describe(),
            'comment' => $this->comment,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->whenLoaded('actor', fn () => $this->actor?->full_name),
            'by_the_scheduler' => $this->actor_id === null,
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
