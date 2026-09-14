<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GuestVisit;
use App\Models\Residency;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * FR-06, the card itself: name, citizenship, contact, current bed and the
 * residency history.
 *
 * **`open_obligations` is gone from the card.** The MVP register knew one kind
 * of outstanding obligation — an accommodation contract still running — and
 * that is the same fact the residency in force already states, one field
 * further up the same response. The section listing it was removed from the
 * screen, and a field no screen reads and no other field adds to is not worth
 * the wire. Property signed for and not returned, which is the obligation that
 * would have justified a list of its own, comes from the inventory-handover
 * entities §3.4.2 defers; when they arrive the field comes back with something
 * to say.
 *
 * @mixin User
 */
final class ResidentCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * The history arrives loaded from `ResidentDirectory`, newest first.
         * The residency in force is read off it rather than queried again: the
         * current bed and the history are two views of the same rows, and a
         * second query could see a third state.
         *
         * «In force today», not «no end recorded». The distinction is the one
         * FR-05 turns on and it used to be got wrong here: a person evicted
         * with effect from the 31st of December had a termination date, so
         * their record was not open, so the card showed them today with no bed
         * — while the same record, one field further down the same response,
         * was marked `is_current: true`. They live there until the 31st, and
         * until the 31st the card says so.
         */
        $today = now();

        /** @var Collection<int, Residency> $inForce */
        $inForce = $this->residencies
            ->filter(fn (Residency $residency): bool => $residency->isCurrentOn($today))
            ->values();

        $current = $inForce->first();

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'citizenship' => $this->citizenship?->value,
            'citizenship_label' => $this->citizenship?->label(),
            'contact' => [
                'email' => $this->email,
                'phone' => $this->phone,
            ],
            'account_status' => $this->status->value,
            'roles' => RoleGrantResource::collection($this->whenLoaded('roleGrants')),
            'current_bed' => $current === null ? null : $this->placement($current),
            'residency_history' => ResidencyResource::collection(
                $this->whenLoaded('residencies', fn () => $this->residencies)
            ),
            /*
             * FR-20, third criterion. A key of its own rather than a mark on
             * the residency rows: a residency is what the register says this
             * person holds, and an overdue visit is something that happened —
             * a breach of clause 2.2 recorded against them. Folding the two
             * together would have made «holds a place» and «has never been
             * late» one statement.
             *
             * The condition is `overdue_notified_at` and not the status, so a
             * visit that has since been closed as `closed_late` still shows:
             * the card records that the deadline was passed, not whether the
             * guest is at this moment still inside.
             */
            'overdue_guest_visits' => $this->whenLoaded(
                'overdueGuestVisits',
                fn () => $this->overdueGuestVisits
                    ->map(fn (GuestVisit $visit): array => [
                        'guest_visit_id' => $visit->getKey(),
                        'guest_request_id' => $visit->guest_request_id,
                        'guest_full_name' => $visit->request?->guest_full_name,
                        'due_at' => $visit->due_at?->toIso8601String(),
                        'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
                        'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
                        'reported_overdue_at' => $visit->overdue_notified_at?->toIso8601String(),
                        'status' => $visit->status->value,
                    ])
                    ->values(),
                [],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placement(Residency $residency): array
    {
        $bed = $residency->bed;
        $room = $bed?->room;
        $building = $room?->building;

        return [
            'building_id' => $building?->id,
            'building_name' => $building?->name,
            'room_id' => $room?->id,
            'room_number' => $room?->number,
            'floor' => $room?->floor,
            'bed_id' => $bed?->id,
            'bed_label' => $bed?->label,
            'moved_in_at' => $residency->moved_in_at?->toDateString(),
        ];
    }
}
