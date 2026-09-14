<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-02: the floors of one dormitory with the occupancy of each. The same
 * circle as the room register itself — the summary is that register added up,
 * so it cannot be readable by anybody the register is not.
 */
final class ShowBuildingFloorsRequest extends FormRequest
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
