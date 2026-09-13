<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\RoleCode;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-41: `DELETE /api/v1/buildings/{building}/staff/{user}/{role}`.
 *
 * The same gate as the appointment, asked with the same pair. Taking a role
 * back is not a lesser act than handing it out — a warden able to revoke
 * another warden would remove the one account above him — so the set of roles
 * is the same one `RoleCode::grantableRoles()` names.
 *
 * The role travels in the path, so it is merged into the input before
 * validation and judged by the same rule the appointment uses.
 */
final class RevokeStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        if (! $building instanceof Building) {
            return false;
        }

        $role = $this->revokedRole();

        if ($role === null) {
            return true;
        }

        return $this->user()?->can('appointStaff', [$building, $role]) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['role' => $this->route('role')]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(RoleCode::class)],
        ];
    }

    /**
     * The role as the path spells it, or null when it spells no role this
     * system knows. Used by `authorize()`, which runs before validation.
     */
    public function revokedRole(): ?RoleCode
    {
        $value = $this->route('role');

        return is_string($value) ? RoleCode::tryFrom($value) : null;
    }

    /**
     * The same role once the rules have passed, where it can no longer be null.
     */
    public function role(): RoleCode
    {
        return RoleCode::from((string) $this->validated('role'));
    }
}
