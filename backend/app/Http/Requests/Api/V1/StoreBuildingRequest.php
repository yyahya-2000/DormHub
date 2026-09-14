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
 * The visiting window is validated for shape, not for content: NFR-09 makes
 * the hours a per-building setting, so the rules of clause 2.2 are the default
 * and not a constraint the form may impose.
 *
 * **And not for order either.** `visiting_to` used to carry `after:visiting_from`,
 * which refused exactly the dormitory `TimeWindow` was written for: a window
 * from 08:00 to 02:00 is fifteen hours ending after midnight, and the rule read
 * it as an empty one. The edit route never had the rule, so such a building
 * could be reached only by creating a lawful one and amending it afterwards —
 * a regime the register would admit but not issue. A closing time at or before
 * the opening time means the following day, here as everywhere else.
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
            'visiting_to' => ['sometimes', 'date_format:H:i:s'],
            'curfew_at' => ['sometimes', 'date_format:H:i:s'],
            // FR-16: the notice this dormitory wants before a guest arrives.
            // Zero — the column's default — means none, which is what the HSE
            // rules of internal order actually say; the cap is a month, past
            // which the setting stops being a notice period and becomes a
            // refusal to admit guests at all.
            'guest_lead_time_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
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
            'name', 'address', 'floors_count', 'visiting_from', 'visiting_to', 'curfew_at',
            'guest_lead_time_hours', 'is_active',
        ]);
    }
}
