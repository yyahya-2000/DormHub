<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\StudyStatus;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-42: `POST /api/v1/buildings/{building}/residents`.
 *
 * The building comes from the path, so «their own building» is decided on the
 * object the route named and a request against another dormitory is refused
 * here, before a row is written.
 *
 * There is no `role` field and no `password` field, and both absences are the
 * requirement rather than an omission. The role is fixed at resident by the
 * service; a password the caller could set would be a password the caller
 * knows, which is exactly what the third criterion forbids.
 */
final class StoreResidentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('issueResidentAccount', $building) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            // The address the one-time credential goes to, so it is the one
            // field that cannot be left out or duplicated.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'study_status' => ['sometimes', 'nullable', Rule::enum(StudyStatus::class)],
            'citizenship' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only(['full_name', 'email', 'phone', 'study_status', 'citizenship']);
    }
}
