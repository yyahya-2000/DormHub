<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-41: `POST /api/v1/buildings/{building}/staff`.
 *
 * The scope comes from the path and never from the payload. A body that could
 * name the building would let the caller nominate the very scope their own
 * authorisation is then checked against, which is the mistake
 * `StoreResidencyRequest` avoids by reading the scope off the bed.
 *
 * The role being granted is part of the authorisation question rather than a
 * value validated and then trusted: a warden may appoint a manager and may not
 * appoint another warden, and both answers come out of the same call to the
 * gate.
 */
final class AppointStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        if (! $building instanceof Building) {
            return false;
        }

        $role = $this->grantedRole();

        if ($role === null) {
            // Nothing to decide on. An unknown role is a malformed request and
            // is reported as 422 by the rule below, which is the honest answer:
            // the caller did not ask for something they were refused.
            return true;
        }

        return $this->user()?->can('appointStaff', [$building, $role]) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::enum(RoleCode::class)],
        ];
    }

    /**
     * The role as the payload spells it, or null when it spells no role this
     * system knows. Used by `authorize()`, which runs before validation.
     */
    public function grantedRole(): ?RoleCode
    {
        $value = $this->input('role');

        return is_string($value) ? RoleCode::tryFrom($value) : null;
    }

    /**
     * The same role once the rules have passed, where it can no longer be null.
     */
    public function role(): RoleCode
    {
        return RoleCode::from((string) $this->validated('role'));
    }

    public function subject(): User
    {
        return User::query()->findOrFail((int) $this->validated('user_id'));
    }
}
