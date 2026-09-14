<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\RoomType;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-02: registering a room in a building. The warden of that building, or the
 * administrator.
 *
 * `capacity` has a floor of one and no ceiling in the form: how many places a
 * room holds is a fact about the building, not a rule the API may invent. What
 * the form does refuse is a number below one, which would make a room that can
 * never take anybody and would then have to be special-cased everywhere.
 */
final class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('manageRooms', $building) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $building = $this->route('building');

        return [
            'number' => [
                'required', 'string', 'max:32',
                Rule::unique('rooms', 'number')
                    ->where('building_id', $building instanceof Building ? $building->getKey() : null),
            ],
            'floor' => ['required', 'integer', 'min:0', 'max:100'],
            'capacity' => ['required', 'integer', 'min:1', 'max:32'],
            'type' => ['sometimes', Rule::enum(RoomType::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only(['number', 'floor', 'capacity', 'type']);
    }
}
