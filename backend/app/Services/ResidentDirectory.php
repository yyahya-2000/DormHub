<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Models\Residency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * FR-06, «Resident card»: the read.
 *
 * The card is assembled here rather than in the resource, because deciding
 * what belongs on it is a domain question and shaping it into JSON is not
 * (§3.3.3). Reading it is recorded: it carries personal data, and §3.9.6
 * counts that among the audited events.
 *
 * **The buildings a card belongs to.** A card is not loose personal data; it
 * is attached to the dormitories the person is **registered as a resident
 * of**, and that attachment is what the policy decides on. Two sources feed
 * it — the residency register, which knows every room the person has held a
 * bed in, and the resident grant, which knows where the account is scoped in
 * the window between FR-42 issuing it and FR-03 giving it a bed. A warden
 * outside that set is refused, which is FR-07's horizontal boundary applied to
 * FR-06.
 *
 * **Why a staff grant does not count (acceptance of 14.09.2026).** The first
 * version of this method took *every* role grant as an attachment, and that
 * turned FR-41 into a way around FR-07. The warden of block 1 appointed a
 * resident of block 2 a security officer of block 1 — one call, permitted by
 * the letter of FR-41 — and the same person's card, citizenship and telephone
 * included, became readable to him on the next request, because the grant he
 * had just written was counted as an attachment. Revoking it closed the card
 * again and left nothing but two entries in the log.
 *
 * The half that was wrong is this one. A staff grant says where somebody
 * **works**; it says nothing about whose register their personal data belongs
 * to, and the card is register work. A security officer of block 1 who lives
 * nowhere in the system has a card that only he and the administrator read,
 * which is the correct answer and not a gap. The other half — that a warden
 * could reach for any account in the system at all — is closed separately, in
 * `App\Rules\NotAResidentOfAnotherDormitory`, and the two locks are
 * deliberately independent: either one of them alone stops the escalation.
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
     * The identifiers of the buildings this person is registered as a resident
     * of — the buildings their card is attached to, and the buildings a staff
     * appointment elsewhere may not drag it out of.
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

        $fromGrants = $resident->roleGrants()
            ->whereHas(
                'role',
                fn (Builder $role) => $role->where('code', RoleCode::Resident->value)
            )
            ->pluck('building_id');

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
            /*
             * FR-20, third criterion: «the fact is visible on the inviting
             * resident's card». The overdue visits of this person's guests
             * belong to the card for the same reason the open obligations do —
             * clause 3.4 of the Model Rules makes the inviting resident
             * answerable for the guest's timely departure, so an overdue visit
             * is an unsettled matter of theirs and not only of the post's.
             */
            'overdueGuestVisits.request',
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
