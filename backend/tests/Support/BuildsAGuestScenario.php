<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\RoleCode;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Residency;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The cast every guest scenario needs: a dormitory, a resident with a bed in
 * it, and the three members of staff whose capabilities the module is drawn
 * around.
 *
 * It is a trait rather than a base test case because two of the five test
 * classes need only half of it, and a setUp that built the other half would
 * make every one of those tests depend on rows they never look at — which is
 * how a suite comes to fail for reasons that have nothing to do with the
 * behaviour under test.
 *
 * Everyone it creates is invented (C-05).
 */
trait BuildsAGuestScenario
{
    /**
     * A dormitory keeping clause 2.2's regime unless the test says otherwise.
     * Every override is a column, which is the whole of NFR-09.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function dormitory(string $name, array $overrides = []): Building
    {
        return Building::factory()->create(['name' => $name] + $overrides);
    }

    /**
     * A resident with a role grant in the building **and a bed in it**.
     *
     * Both halves are needed and for different reasons. The grant is what the
     * account is; the residency is what `GuestRequestPolicy::create` asks
     * about, since FR-16 is open to a person who lives there and not to a
     * person whose account is scoped there. The room number is what the card
     * at the post shows as «premises».
     */
    protected function residentOf(Building $building, string $email, string $room = '305'): User
    {
        $resident = User::factory()
            ->withRole(RoleCode::Resident, $building)
            ->create(['email' => $email]);

        $roomRow = Room::query()->firstOrCreate(
            ['building_id' => $building->getKey(), 'number' => $room],
            ['floor' => (int) substr($room, 0, 1), 'capacity' => 4],
        );

        $bed = Bed::factory()->create(['room_id' => $roomRow->getKey()]);

        Residency::factory()->create([
            'user_id' => $resident->getKey(),
            'bed_id' => $bed->getKey(),
            'moved_in_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
        ]);

        return $resident->fresh() ?? $resident;
    }

    /**
     * A member of staff holding one role in one building — or over the system,
     * for the administrator, whose grant names none.
     */
    protected function staff(RoleCode $code, ?Building $building, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        $role = Role::query()->where('code', $code->value)->sole();

        $user->roleGrants()->create([
            'role_id' => $role->getKey(),
            'building_id' => $code->isSystemWide() ? null : $building?->getKey(),
            'granted_at' => now(),
        ]);

        $user->unsetRelation('roleGrants');

        return $user;
    }
}
