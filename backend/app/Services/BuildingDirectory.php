<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Building;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads of the building card and of the people attached to it (FR-07).
 *
 * Two things are worth stating about the query below. The people are selected
 * **by the grant that names this building**, so the scope of the answer comes
 * from the same column the policy decides on; a warden asking about another
 * building is refused before reaching here, and a query written differently
 * could not quietly widen that answer.
 *
 * The second is that both reads are audited. §3.9.6 counts the viewing of a
 * card carrying personal data among the recorded events, and FR-33 repeats it.
 * A read changes nothing, so no transaction is opened around the record: the
 * rule about a shared transaction applies where there is a change to share
 * one with.
 */
final readonly class BuildingDirectory
{
    public function __construct(private AuditRecorder $audit) {}

    public function card(User $viewer, Building $building, ?string $ipAddress = null): Building
    {
        $this->audit->record(
            action: AuditAction::BuildingViewed,
            actor: $viewer,
            subject: $building,
            ipAddress: $ipAddress,
        );

        return $building;
    }

    /**
     * @return Collection<int, User>
     */
    public function people(User $viewer, Building $building, ?string $ipAddress = null): Collection
    {
        /** @var Collection<int, User> $people */
        $people = User::query()
            ->whereHas(
                'roleGrants',
                fn ($query) => $query->where('building_id', $building->getKey())
            )
            ->with(['roleGrants' => fn ($query) => $query->where('building_id', $building->getKey())->with('role')])
            ->orderBy('full_name')
            ->get();

        $this->audit->record(
            action: AuditAction::BuildingUsersViewed,
            actor: $viewer,
            subject: $building,
            payload: ['returned' => $people->count()],
            ipAddress: $ipAddress,
        );

        return $people;
    }
}
