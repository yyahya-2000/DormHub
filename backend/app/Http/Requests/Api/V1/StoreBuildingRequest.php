<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-01, first criterion: creating a dormitory is the administrator's, and
 * the refusal happens here — before the controller, before the service, and
 * with no dependence on what the interface chose to display (§3.3.2).
 *
 * The visiting window is validated for order, not for content: NFR-09 makes
 * the hours a per-building setting, so the rules of clause 2.2 are the default
 * and not a constraint the form may impose.
 */
final class StoreBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Building::class) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('buildings', 'name')],
            'address' => ['required', 'string', 'max:255'],
            'floors_count' => ['required', 'integer', 'min:1', 'max:100'],
            'visiting_from' => ['sometimes', 'date_format:H:i:s'],
            'visiting_to' => ['sometimes', 'date_format:H:i:s', 'after:visiting_from'],
            'curfew_at' => ['sometimes', 'date_format:H:i:s'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated payload, named so as not to collide with FormRequest's own
     * `attributes()`, which supplies the human names used in messages.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'name', 'address', 'floors_count', 'visiting_from', 'visiting_to', 'curfew_at', 'is_active',
        ]);
    }
}
