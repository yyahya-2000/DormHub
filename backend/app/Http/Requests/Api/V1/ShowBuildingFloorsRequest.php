<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-43: the floors of one dormitory with the occupancy of each. The circle is
 * the one FR-43's criterion names — the access rules of the resident card —
 * because the plan is read as a way to the people in the rooms.
 */
final class ShowBuildingFloorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('viewFloorPlan', $building) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
