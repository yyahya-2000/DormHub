<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GuestRequest;
use App\Models\Residency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One guest request as the resident and the staff of the dormitory read it
 * (FR-16, FR-17).
 *
 * **The decision names a person and not an identifier.** `decided_by` alone
 * made the screen either show a number or fetch an account per row; the name
 * travels beside it, and so does the name of the officer who recorded the
 * exit, because «who let this guest out» is the question the register is read
 * with.
 *
 * The inviting resident is an object rather than two flat fields: the client
 * draws a link to their card and prints the room beside it, and a room number
 * that arrived separately from the identifier it belongs to is the shape that
 * ends up next to the wrong name.
 *
 * @mixin GuestRequest
 */
final class GuestRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'building_name' => $this->whenLoaded('building', fn () => $this->building?->name),
            'student_id' => $this->student_id,
            'student_name' => $this->whenLoaded('student', fn () => $this->student?->full_name),
            'inviting_resident' => $this->whenLoaded('student', fn (): array => [
                'id' => $this->student?->getKey(),
                'full_name' => $this->student?->full_name,
                'room' => $this->inviterRoom(),
            ]),

            'guest_full_name' => $this->guest_full_name,

            'visit_date' => $this->visit_date?->toDateString(),
            'planned_from' => substr((string) $this->planned_from, 0, 5),
            'planned_to' => substr((string) $this->planned_to, 0, 5),
            'due_at' => $this->dueAt()->toIso8601String(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // Null until the decision. §3.5.1: before the request is decided
            // there is nothing to present at the post.
            'access_code' => $this->access_code,

            'decided_by' => $this->decided_by,
            'decided_by_name' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->full_name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,

            'visit' => GuestVisitResource::make($this->whenLoaded('visit')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The room the inviting resident held on the day of the visit.
     *
     * Read off the residencies already loaded with the student, so a page of
     * twenty requests is one query and not twenty-one. A caller that did not
     * load them gets null rather than a hidden round trip per row.
     */
    private function inviterRoom(): ?string
    {
        $student = $this->student;

        if ($student === null || ! $student->relationLoaded('residencies')) {
            return null;
        }

        $on = $this->visit_date ?? CarbonImmutable::now();

        $room = $student->residencies
            ->first(fn (Residency $residency): bool => $residency->isCurrentOn($on))
            ?->bed?->room;

        return $room === null ? null : (string) $room->number;
    }
}
