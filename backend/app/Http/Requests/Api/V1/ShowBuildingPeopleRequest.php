<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The protected route FR-07 is demonstrated on. The building comes from the
 * path, the policy decides on that object, and a warden asking about a
 * building other than their own is refused here — before any query runs, and
 * with no dependence on what the interface chose to display (§3.3.2).
 */
final class ShowBuildingPeopleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('viewPeople', $building) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
