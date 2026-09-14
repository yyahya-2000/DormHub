<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\RoleCode;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The protected route FR-07 is demonstrated on. The building comes from the
 * path, the policy decides on that object, and a warden asking about a
 * building other than their own is refused here — before any query runs, and
 * with no dependence on what the interface chose to display (§3.3.2).
 *
 * `q` and `role` narrow the roll, and neither of them widens it: the query is
 * built from the grants naming **this** building whatever they say, so the one
 * boundary that matters is decided above and cannot be reached from the query
 * string. `q` is what the accommodation form of FR-03 searches candidates by —
 * part of a name, rather than the whole roll fetched and filtered in a browser.
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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'string', 'in:'.implode(',', array_column(RoleCode::cases(), 'value'))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('dormitory.housing.max_page_size')],
        ];
    }

    public function search(): ?string
    {
        $needle = $this->query('q');

        return is_string($needle) && trim($needle) !== '' ? trim($needle) : null;
    }

    public function role(): ?RoleCode
    {
        $role = $this->query('role');

        return is_string($role) ? RoleCode::tryFrom($role) : null;
    }

    public function perPage(): int
    {
        $asked = $this->query('per_page');
        $max = (int) config('dormitory.housing.max_page_size');

        return is_numeric($asked)
            ? max(1, min($max, (int) $asked))
            : (int) config('dormitory.housing.page_size');
    }
}
