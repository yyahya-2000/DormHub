<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
     * The roll of one building, a page at a time.
     *
     * `$search` matches part of a name and `$role` keeps one kind of grant —
     * the two the accommodation form of FR-03 asks with, because a warden
     * placing an arrival wants the residents whose name he is half way through
     * typing and not the whole dormitory. Both narrow the same query, which
     * starts from the grants naming this building and can therefore never
     * reach a person attached to another one.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function people(
        User $viewer,
        Building $building,
        ?string $search = null,
        ?RoleCode $role = null,
        ?int $perPage = null,
        ?string $ipAddress = null,
    ): LengthAwarePaginator {
        // Typed loosely on purpose: `whereHas` hands this closure a query
        // builder and `with` hands it the relation, and both understand the
        // one clause it adds.
        $inThisBuilding = fn ($grants) => $grants->where('building_id', $building->getKey());

        $query = User::query()
            ->whereHas('roleGrants', $inThisBuilding)
            ->with(['roleGrants' => fn ($grants) => $inThisBuilding($grants)->with('role')]);

        if ($role !== null) {
            $query->whereHas(
                'roleGrants',
                fn (Builder $grants) => $inThisBuilding($grants)->whereHas(
                    'role',
                    fn (Builder $named) => $named->where('code', $role->value)
                )
            );
        }

        if ($search !== null && trim($search) !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

            $query->where('full_name', 'ilike', $needle);
        }

        /** @var LengthAwarePaginator<int, User> $people */
        $people = $query
            ->orderBy('full_name')
            ->paginate($perPage ?? (int) config('dormitory.housing.page_size'));

        $this->audit->record(
            action: AuditAction::BuildingUsersViewed,
            actor: $viewer,
            subject: $building,
            payload: [
                'returned' => $people->count(),
                'total' => $people->total(),
                'role' => $role?->value,
                'search' => $search,
            ],
            ipAddress: $ipAddress,
        );

        return $people;
    }
}
