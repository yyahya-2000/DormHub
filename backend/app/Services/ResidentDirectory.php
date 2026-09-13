<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Residency;
use App\Models\User;

/**
 * FR-06, «Resident card»: the read.
 *
 * The card is assembled here rather than in the resource, because deciding
 * what belongs on it is a domain question and shaping it into JSON is not
 * (§3.3.3). Reading it is recorded: it carries personal data, and §3.9.6
 * counts that among the audited events.
 *
 * **The buildings a card belongs to.** A card is not loose personal data; it
 * is attached to the dormitories the person is or was registered in, and that
 * attachment is what the policy decides on. Two sources feed it — the
 * residency register, which knows every room the person has held a bed in, and
 * the role grants, which know where the account is scoped even before a bed
 * has been assigned. A warden outside that set is refused, which is FR-07's
 * horizontal boundary applied to FR-06.
 *
 * The history is loaded down to the building in one pass, because the card
 * shows the current bed, the whole history and the open obligations, and all
 * three are views of the same rows. `ResidentCardResource` reads them off the
 * loaded relation rather than querying again, which is also why a second query
 * cannot show a third state.
 */
final readonly class ResidentDirectory
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * The identifiers of the buildings this person's card is attached to.
     *
     * @return list<int>
     */
    public function buildingIdsOf(User $resident): array
    {
        $fromResidencies = Residency::query()
            ->where('user_id', $resident->getKey())
            ->join('beds', 'beds.id', '=', 'residencies.bed_id')
            ->join('rooms', 'rooms.id', '=', 'beds.room_id')
            ->pluck('rooms.building_id');

        $fromGrants = $resident->roleGrants()->pluck('building_id');

        /** @var list<int> $ids */
        $ids = $fromResidencies
            ->merge($fromGrants)
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * The card itself, with the history loaded down to the building.
     */
    public function card(User $viewer, User $resident, ?string $ipAddress = null): User
    {
        $resident->load([
            'roleGrants.role',
            'residencies.bed.room.building',
        ]);

        $this->audit->record(
            action: AuditAction::ResidentCardViewed,
            actor: $viewer,
            subject: $resident,
            payload: ['self' => $viewer->is($resident)],
            ipAddress: $ipAddress,
        );

        return $resident;
    }
}
