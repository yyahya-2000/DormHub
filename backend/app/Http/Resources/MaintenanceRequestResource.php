<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MaintenanceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One maintenance request as the resident and the warden read it
 * (FR-36 … FR-40).
 *
 * **Three of these fields are computed and none of them is stored**, and that
 * is deliberate. `age_days`, `overdue` and `confirmation_window_open` are
 * questions about the clock, and a column holding the answer would be a column
 * that is wrong between two runs of whatever updates it. FR-40's threshold and
 * FR-39's window arrive from configuration through the model's arguments, so
 * changing either changes what the client reads on the next request and
 * nothing has to be recomputed anywhere.
 *
 * **The photographs travel as paths and not as URLs.** A signed URL expires,
 * so one embedded in a list is a link that is stale by the time somebody
 * scrolls to it; the client asks for a link when it is about to show the
 * image. The paths are meaningless without the object store's credentials,
 * which is what makes them safe to serialise.
 *
 * **The history is the record and the request is the summary.** §3.4.1's sixth
 * decision means the reason a request was refused, the comment the work
 * carried and the moment of every move all live in `maintenance_work_logs` —
 * so `work_log` is where a client reads them, and there is no `rejection_reason`
 * on the request to disagree with the log.
 *
 * @mixin MaintenanceRequest
 */
final class MaintenanceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overdueAfter = (int) config('dormitory.maintenance.overdue_after_days');
        $window = (int) config('dormitory.maintenance.confirmation_window_days');

        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'building_name' => $this->whenLoaded('building', fn () => $this->building?->name),
            'room_id' => $this->room_id,
            'room_number' => $this->whenLoaded('room', fn () => $this->room?->number),
            'reporter_id' => $this->reporter_id,
            'reporter_name' => $this->whenLoaded('reporter', fn () => $this->reporter?->full_name),

            'category' => $this->category?->value,
            'category_label' => $this->category?->label(),
            'location' => $this->location?->value,
            'location_note' => $this->location_note,
            'place' => $this->placeDescription(),

            'title' => $this->title,
            'description' => $this->description,
            'urgency' => $this->urgency?->value,
            'urgency_label' => $this->urgency?->label(),
            'photo_paths' => $this->photoPaths(),
            'photo_count' => count($this->photoPaths()),

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),

            'assigned_to' => $this->assigned_to,
            'assignee_name' => $this->whenLoaded('assignee', fn () => $this->assignee?->full_name),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'target_date' => $this->target_date?->toDateString(),

            'completed_at' => $this->completed_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            // FR-39: the two endings are told apart here and not by the client
            // guessing from a null.
            'auto_closed' => (bool) $this->auto_closed,
            'reopen_count' => (int) $this->reopen_count,

            // FR-40's first criterion, and the Gherkin's «age counted from
            // submission».
            'age_days' => $this->ageInDaysAt(),
            'overdue' => $this->isOverdueAt($overdueAfter),
            'overdue_after_days' => $overdueAfter,

            // FR-39: whether the reporter may still say «not fixed», and for
            // how long, so the screen can say so rather than offering a button
            // that answers 409.
            'confirmation_window_days' => $window,
            'confirmation_window_open' => $this->confirmationWindowIsOpenAt($window),

            'work_log' => MaintenanceWorkLogResource::collection($this->whenLoaded('workLog')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
