<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-02: `GET /api/v1/buildings/{id}/rooms`, the route §3.3.6 assigns to
 * `RoomQuery`. The building comes from the path and the policy decides on that
 * object, so a warden asking about a neighbouring dormitory is refused before
 * a single row is read.
 */
final class ListBuildingRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('viewRooms', $building) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
