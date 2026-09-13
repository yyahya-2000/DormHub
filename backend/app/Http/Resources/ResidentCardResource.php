<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Residency;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * FR-06, the card itself: name, study status, citizenship, contact, current
 * bed, residency history and open obligations.
 *
 * `open_obligations` deserves its note. The MVP model of §3.4.3 knows one kind
 * of outstanding obligation a resident can carry — an accommodation contract
 * that has not been terminated, whose term art. 105 cl. 2 of the Housing Code
 * ties to the term of study. Those are what the field lists. Property signed
 * for and not returned would come from the inventory-handover entities §3.4.2
 * lists as deferred, and none of it is invented here: a card that showed an
 * empty list where the data does not exist would read as «owes nothing», which
 * is a stronger claim than the register can make.
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
         * The open residencies are read off it rather than queried again: the
         * current bed and the open obligations are two views of the same rows,
         * and a second query could see a third state.
         */
        /** @var Collection<int, Residency> $open */
        $open = $this->residencies->filter(fn (Residency $residency): bool => $residency->isOpen())->values();

        $current = $open->first();

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'study_status' => $this->study_status?->value,
            'study_status_label' => $this->study_status?->label(),
            'citizenship' => $this->citizenship,
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
            'open_obligations' => $open
                ->map(fn (Residency $residency): array => [
                    'kind' => 'accommodation_contract',
                    'residency_id' => $residency->getKey(),
                    'contract_number' => $residency->contract_number,
                    'since' => $residency->moved_in_at?->toDateString(),
                    'placement' => $this->placement($residency),
                ])
                ->values(),
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
