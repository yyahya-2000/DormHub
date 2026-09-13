<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-01, first criterion: editing a dormitory is the administrator's.
 */
final class UpdateBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('update', $building) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $building = $this->route('building');

        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('buildings', 'name')->ignore($building instanceof Building ? $building->getKey() : null),
            ],
            'address' => ['sometimes', 'string', 'max:255'],
            'floors_count' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'visiting_from' => ['sometimes', 'date_format:H:i:s'],
            'visiting_to' => ['sometimes', 'date_format:H:i:s'],
            'curfew_at' => ['sometimes', 'date_format:H:i:s'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'name', 'address', 'floors_count', 'visiting_from', 'visiting_to', 'curfew_at', 'is_active',
        ]);
    }
}
