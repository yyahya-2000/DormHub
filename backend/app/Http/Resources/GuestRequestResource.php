<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GuestRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One guest request as the resident and the duty officer read it (FR-16,
 * FR-17, FR-23).
 *
 * **The document number is never here in full.** The model hides the attribute
 * and this resource asks for the mask, so there is no path by which the number
 * reaches a response except the one route that exists for it and records the
 * act. That is NFR-06 stated as a property of the code rather than as a habit.
 *
 * **`foreign_guest_warning` is FR-23's first criterion**, and it is attached by
 * the server rather than drawn by the client. A warning the interface composes
 * is a warning each interface composes differently, and this one is about a
 * procedure — art. 20 of Federal Law No. 109-FZ and the university's own act —
 * where the wording is the substance. The text is configuration (§2.7.4 leaves
 * open which of two deadlines applies, and that is the legal service's
 * question), so a change to the procedure is a change to a setting.
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
        $window = $this->plannedWindow();

        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'building_name' => $this->whenLoaded('building', fn () => $this->building?->name),
            'student_id' => $this->student_id,
            'student_name' => $this->whenLoaded('student', fn () => $this->student?->full_name),

            'guest_full_name' => $this->guest_full_name,
            'guest_doc_type' => $this->guest_doc_type?->value,
            'guest_doc_type_label' => $this->guest_doc_type?->label(),
            'guest_doc_number_masked' => $this->maskedDocumentNumber(),
            'is_foreign_document' => (bool) $this->is_foreign_document,
            'foreign_guest_warning' => $this->is_foreign_document
                ? trim((string) config('dormitory.guests.foreign_document_warning'))
                : null,
            'purpose' => $this->purpose,

            'visit_date' => $this->visit_date?->toDateString(),
            'planned_from' => substr((string) $this->planned_from, 0, 5),
            'planned_to' => substr((string) $this->planned_to, 0, 5),
            // FR-23's second criterion turns on this, so it travels rather
            // than being recomputed by whoever draws the form.
            'spans_more_than_one_day' => $window->crossesMidnight(),
            'due_at' => $this->dueAt()->toIso8601String(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // Null until the decision. §3.5.1: before the duty officer decides
            // there is nothing to present at the post.
            'access_code' => $this->access_code,

            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,

            'responsible_officer_mark' => $this->responsible_officer_mark,
            'responsible_officer_mark_at' => $this->responsible_officer_mark_at?->toIso8601String(),

            'visit' => GuestVisitResource::make($this->whenLoaded('visit')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
