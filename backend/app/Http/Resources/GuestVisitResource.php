<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GuestVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the visitor register (FR-19, FR-21).
 *
 * `due_at` is carried beside `checked_out_at` on purpose. The pair is what
 * makes `closed_late` legible: the deadline the visit was judged by, frozen at
 * the moment of entry, and the time the guest actually left. A response that
 * gave only the status would leave a client recomputing the deadline from the
 * building's current setting — and getting a different answer once somebody
 * edited it.
 *
 * The two officers travel by name as well as by identifier: the entry and the
 * exit may be different shifts, and «who recorded the exit» is a question the
 * register is read with.
 *
 * @mixin GuestVisit
 */
final class GuestVisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guest_request_id' => $this->guest_request_id,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_in_by' => $this->checked_in_by,
            'checked_in_by_name' => $this->whenLoaded('checkedInBy', fn () => $this->checkedInBy?->full_name),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'checked_out_by' => $this->checked_out_by,
            'checked_out_by_name' => $this->whenLoaded('checkedOutBy', fn () => $this->checkedOutBy?->full_name),
            'due_at' => $this->due_at?->toIso8601String(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'admitted_on_decision' => (bool) $this->admitted_on_decision,
            'admission_note' => $this->admission_note,
        ];
    }
}
