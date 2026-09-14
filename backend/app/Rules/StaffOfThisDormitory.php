<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\RoleCode;
use App\Models\RoleUser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * FR-37 and FR-07 at the seam between them: who a warden may make responsible
 * for a defect in his own dormitory.
 *
 * **The acceptance finding of 15.09.2026, and it is the finding
 * `NotAResidentOfAnotherDormitory` was written for, in a second place.** The
 * triage route took `assigned_to` with `exists:users,id` behind it and nothing
 * else: any identifier in the table was a valid answer. Identifiers are
 * sequential, so «any account in the system» is a range starting at one, and
 * the card the acceptance answers with carries the assignee's full name. Two
 * permitted calls and the warden of block 1 was reading the names of block 2's
 * staff; a third and a stranger was the responsible party on a repair he would
 * never hear about.
 *
 * **What the rule admits.** A grant naming *this* dormitory, held by anybody
 * whose role is not the resident's. That is the warden himself — FR-37's «a
 * dormitory whose warden does the work himself» — the manager beneath him, the
 * duty officer and the security officer. A resident is outside it because
 * being given a repair to do is staff work and a resident's grant says only
 * that they live here.
 *
 * **What it deliberately does not admit.** The administrator, whose grant
 * names no building at all. It is the same line `Permission::
 * ViewMaintenanceRequests` draws in prose: the administrator reads every
 * queue and triages none of them, because a planned completion date is a
 * promise made by the person who will keep it, and nobody is going to send the
 * campus directorate to change a washer.
 *
 * 422 rather than 403: the caller's role and scope are not in question — the
 * warden may triage in this building — and what the request got wrong is the
 * account it named. The message says nothing about the account it refused,
 * because a message that distinguished «no such person» from «somebody else's
 * dormitory» would be the enumeration this rule exists to stop.
 */
final readonly class StaffOfThisDormitory implements ValidationRule
{
    public function __construct(private int $buildingId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            // The `integer` rule on the same field reports this.
            return;
        }

        $staffOfThisBuilding = RoleUser::query()
            ->where('user_id', (int) $value)
            ->where('building_id', $this->buildingId)
            ->whereHas('role', fn (Builder $role) => $role->whereIn('code', self::staffRoles()))
            ->exists();

        if (! $staffOfThisBuilding) {
            $fail('The responsible party must be a member of staff of the dormitory the request belongs to.');
        }
    }

    /**
     * Every role but the resident's, taken from the enumeration rather than
     * listed here: a seventh role is then admitted by being added to
     * `RoleCode` and forgotten in no second place.
     *
     * @return list<string>
     */
    private static function staffRoles(): array
    {
        return array_values(array_map(
            static fn (RoleCode $code): string => $code->value,
            array_filter(
                RoleCode::cases(),
                static fn (RoleCode $code): bool => $code !== RoleCode::Resident,
            ),
        ));
    }
}
