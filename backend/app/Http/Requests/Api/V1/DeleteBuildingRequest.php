<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-01, second criterion. The authorisation is settled here; whether the
 * deletion is *possible* is settled by the foreign key on `rooms.building_id`
 * and reported by `BuildingRegistry`. The two refusals are different and the
 * client is told which it got: 403 for «not yours to delete», 409 for «not
 * deletable, and here is why».
 */
final class DeleteBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('delete', $building) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
