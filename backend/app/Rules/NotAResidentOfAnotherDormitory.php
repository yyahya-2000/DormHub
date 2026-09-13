<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Building;
use App\Models\User;
use App\Services\ResidentDirectory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * FR-41 and FR-07 at the seam between them: who a warden may appoint.
 *
 * The appointment route used to accept any identifier the payload named, on
 * the argument that the scope of the grant is what matters and the scope comes
 * from the path. The acceptance of 14.09.2026 showed what that argument
 * misses. Identifiers are sequential, so «any account in the system» is a
 * range starting at one, and a grant written into the caller's own building is
 * a fact about the subject that the caller then benefits from. Two permitted
 * calls, and the register of another dormitory was open.
 *
 * **What the rule refuses, and what it deliberately does not.** A warden
 * hiring a security officer from outside is the ordinary case: the person is
 * an account with no dormitory behind it, and forbidding that would leave the
 * route unable to do the one thing it exists for. A resident of *his own*
 * building taking the duty officer's shift is as ordinary — the person is
 * already inside the scope, and the appointment adds nothing the warden did
 * not already have. What is refused is the third case, the one acceptance
 * found and the one that has no operational reading at all: giving a staff
 * role to somebody the register of a **different** dormitory is responsible
 * for.
 *
 * The set this rule compares against is the same `ResidentDirectory` set the
 * card policy decides on, and that is the point. The two locks cannot drift
 * apart, because a person who cannot be appointed here is exactly a person
 * whose card belongs somewhere else.
 *
 * 422 rather than 403: the caller's role and scope are not in question — the
 * warden may appoint in this building — and what the request got wrong is the
 * account it named.
 */
final readonly class NotAResidentOfAnotherDormitory implements ValidationRule
{
    public function __construct(
        private Building $building,
        private ResidentDirectory $residents,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $subject = User::query()->find($value);

        if (! $subject instanceof User) {
            // The `exists` rule on the same field reports this, and reporting
            // it twice would say the same thing in two sentences.
            return;
        }

        foreach ($this->residents->buildingIdsOf($subject) as $buildingId) {
            if ($buildingId !== $this->building->getKey()) {
                $fail('This account is registered as a resident of another dormitory and cannot be given a role here.');

                return;
            }
        }
    }
}
