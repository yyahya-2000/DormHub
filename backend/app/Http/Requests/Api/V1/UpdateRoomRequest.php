<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-02: editing a room. Lowering `capacity` below the places already
 * registered is refused by `RoomRegistry` and not here, because the form
 * cannot see how many beds exist without a query, and a rule enforced in two
 * places drifts.
 */
final class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room
            && $this->user()?->can('update', $room) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $room = $this->route('room');

        return [
            'number' => [
                'sometimes', 'string', 'max:32',
                Rule::unique('rooms', 'number')
                    ->where('building_id', $room instanceof Room ? $room->building_id : null)
                    ->ignore($room instanceof Room ? $room->getKey() : null),
            ],
            'floor' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:32'],
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
