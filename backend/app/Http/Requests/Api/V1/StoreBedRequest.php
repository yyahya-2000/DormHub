<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-02: registering a place in a room.
 *
 * Uniqueness of the label inside the room is stated twice on purpose. Here it
 * produces the readable 422 a person can act on; in the schema,
 * `UNIQUE (room_id, label)` is what actually holds under concurrency, and it
 * is the one FR-02's verification clause names. The form is the message, the
 * index is the guarantee.
 *
 * The capacity check is deliberately **not** here. It belongs to `RoomRegistry`,
 * where it runs under a row lock and inside the same transaction as the insert.
 */
final class StoreBedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room
            && $this->user()?->can('addBed', $room) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $room = $this->route('room');

        return [
            'label' => [
                'required', 'string', 'max:16',
                Rule::unique('beds', 'label')
                    ->where('room_id', $room instanceof Room ? $room->getKey() : null),
            ],
        ];
    }

    public function label(): string
    {
        return (string) $this->validated('label');
    }
}
