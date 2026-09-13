<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-02: the card of one room with its places.
 */
final class ShowRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room
            && $this->user()?->can('view', $room) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
