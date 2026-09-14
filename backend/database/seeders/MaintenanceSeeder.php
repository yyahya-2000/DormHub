<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceLocation;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceUrgency;
use App\Enums\Permission;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkLog;
use App\Models\Residency;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * The maintenance module on the development stand: one request in each state
 * of the lifecycle FR-38 draws, plus the two that only time produces.
 *
 * **Every defect here is invented (C-05).** The descriptions are of a kind a
 * dormitory actually files and belong to nobody; the people are the ones the
 * housing seeder already made. A seeder is exactly the place where a real
 * complaint would otherwise be pasted in to see the screen work, and an
 * invented one shows it just as well.
 *
 * **Every state, and why each of them is here.** Submitted, because the queue
 * is empty without it and the queue is the warden's screen. Accepted with a
 * planned date, because FR-37's whole subject is that date. In progress, so
 * the middle of the graph is not a state nobody has seen. Completed and
 * waiting, because that is the one state the module is built around — the
 * request the resident has yet to confirm. Closed by confirmation and closed
 * automatically, side by side, because FR-39's second criterion is about
 * telling those two apart and a stand with only one of them cannot show it.
 * Rejected with a reason, because FR-37's first criterion is about the reason
 * and not about the refusal. And one long-overdue request, because waiting a
 * week is not a demonstration.
 *
 * **Every request carries its work log.** §3.4.1's sixth decision is the
 * module's central one, and a stand whose requests had statuses and no history
 * would be demonstrating the design the decision rejects.
 */
class MaintenanceSeeder extends Seeder
{
    /** Invented defects, paired with the category they belong to. */
    private const DEFECTS = [
        [MaintenanceCategory::Plumbing, 'The mixer tap in the washbasin drips and the washer does not hold any more.'],
        [MaintenanceCategory::Electrical, 'The socket beside the desk sparks when a plug is pushed in.'],
        [MaintenanceCategory::Furniture, 'The lock of the wardrobe door has come away from the frame.'],
        [MaintenanceCategory::Heating, 'The radiator stays cold while the one in the next room is hot.'],
        [MaintenanceCategory::Network, 'The wifi point in the corridor drops every few minutes on this floor.'],
        [MaintenanceCategory::Other, 'The window handle turns without catching, so the window will not lock.'],
        [MaintenanceCategory::Plumbing, 'The shower drain in the block backs up and stands full after ten minutes.'],
        [MaintenanceCategory::Electrical, 'The lamp above the mirror flickers and goes out when the door is shut.'],
    ];

    public function run(): void
    {
        $building = Building::query()->orderBy('id')->first();

        if ($building === null) {
            return;
        }

        $residents = $this->residentsOf($building);
        $warden = $this->triageStaffOf($building);

        if ($residents === [] || $warden === null) {
            // Nothing to seed against, exactly as the notification and guest
            // seeders decide in the same situation.
            return;
        }

        if (MaintenanceRequest::query()->where('building_id', $building->getKey())->exists()) {
            // Idempotent in the only way that matters: a second run must not
            // double the queue the demonstration is walked through.
            return;
        }

        $today = CarbonImmutable::now();

        $this->submitted($building, $residents[0], $today, 0);
        $this->accepted($building, $residents[1 % count($residents)], $warden, $today, 1);
        $this->inProgress($building, $residents[2 % count($residents)], $warden, $today, 2);
        $this->completed($building, $residents[0], $warden, $today, 3);
        $this->confirmed($building, $residents[1 % count($residents)], $warden, $today, 4);
        $this->autoClosed($building, $residents[2 % count($residents)], $warden, $today, 5);
        $this->rejected($building, $residents[0], $warden, $today, 6);
        $this->overdue($building, $residents[1 % count($residents)], $warden, $today, 7);
    }

    private function submitted(Building $building, User $reporter, CarbonImmutable $day, int $index): void
    {
        $this->make($building, $reporter, $index, $day->subDay(), [
            'urgency' => MaintenanceUrgency::Urgent,
        ]);
    }

    private function accepted(
        Building $building,
        User $reporter,
        User $warden,
        CarbonImmutable $day,
        int $index,
    ): void {
        $request = $this->make($building, $reporter, $index, $day->subDays(3), [
            'status' => MaintenanceRequestStatus::Accepted,
            'target_date' => $day->addDays(2)->toDateString(),
            'assigned_to' => $warden->getKey(),
            'assigned_at' => $day->subDays(2),
        ]);

        $this->log($request, $warden, MaintenanceRequestStatus::Submitted, MaintenanceRequestStatus::Accepted,
            'The electrician is on site on Thursday.', $day->subDays(2));
    }

    private function inProgress(
        Building $building,
        User $reporter,
        User $warden,
        CarbonImmutable $day,
        int $index,
    ): void {
        $request = $this->make($building, $reporter, $index, $day->subDays(5), [
            'status' => MaintenanceRequestStatus::InProgress,
            'target_date' => $day->addDay()->toDateString(),
            'assigned_to' => $warden->getKey(),
            'assigned_at' => $day->subDays(4),
        ]);

        $this->log($request, $warden, MaintenanceRequestStatus::Submitted, MaintenanceRequestStatus::Accepted,
            'Taken into work.', $day->subDays(4));
        $this->log($request, $warden, MaintenanceRequestStatus::Accepted, MaintenanceRequestStatus::InProgress,
            'The part arrived this morning.', $day->subDay());
    }

    /**
     * The state the whole module is built around: the work is reported done
     * and the request is **not** closed, because closing it belongs to the
     * person who reported the defect (§3.5.2).
     */
    private function completed(
        Building $building,
        User $reporter,
        User $warden,
        CarbonImmutable $day,
        int $index,
    ): void {
        $request = $this->make($building, $reporter, $index, $day->subDays(6), [
            'status' => MaintenanceRequestStatus::Completed,
            'target_date' => $day->subDay()->toDateString(),
            'assigned_to' => $warden->getKey(),
            'assigned_at' => $day->subDays(5),
            'completed_at' => $day->subDay(),
        ]);

        $this->walkTheGraph($request, $warden, $day->subDays(5), $day->subDays(2), $day->subDay());
    }

    private function confirmed(
        Building $building,
        User $reporter,
        User $warden,
        CarbonImmutable $day,
        int $index,
    ): void {
        $request = $this->make($building, $reporter, $index, $day->subDays(12), [
            'status' => MaintenanceRequestStatus::Closed,
            'target_date' => $day->subDays(8)->toDateString(),
            'assigned_to' => $warden->getKey(),
            'assigned_at' => $day->subDays(11),
            'completed_at' => $day->subDays(9),
            'confirmed_at' => $day->subDays(8),
            'closed_at' => $day->subDays(8),
            'auto_closed' => false,
        ]);

        $this->walkTheGraph($request, $warden, $day->subDays(11), $day->subDays(10), $day->subDays(9));

        $this->log($request, $reporter, MaintenanceRequestStatus::Completed, MaintenanceRequestStatus::Closed,
            'The tap no longer drips. Thank you.', $day->subDays(8));
    }

    /**
     * The other ending, beside the one above: FR-39's «a request not confirmed
     * within the window closes automatically», with a null actor because
     * nobody decided.
     */
    private function autoClosed(
        Building $building,
        User $reporter,
        User $warden,
        CarbonImmutable $day,
        int $index,
    ): void {
        $request = $this->make($building, $reporter, $index, $day->subDays(25), [
            'status' => MaintenanceRequestStatus::Closed,
            'target_date' => $day->subDays(20)->toDateString(),
            'assigned_to' => $warden->getKey(),
            'assigned_at' => $day->subDays(24),
            'completed_at' => $day->subDays(21),
            'confirmed_at' => null,
            'closed_at' => $day->subDays(14),
            'auto_closed' => true,
        ]);

        $this->walkTheGraph($request, $warden, $day->subDays(24), $day->subDays(23), $day->subDays(21));

        $this->log($request, null, MaintenanceRequestStatus::Completed, MaintenanceRequestStatus::Closed,
            'Closed automatically: the confirmation window of 7 day(s) passed with no answer from the reporter.',
            $day->subDays(14));
    }

    private function rejected(
        Building $building,
        User $reporter,
        User $warden,
        CarbonImmutable $day,
        int $index,
    ): void {
        $request = $this->make($building, $reporter, $index, $day->subDays(9), [
            'status' => MaintenanceRequestStatus::Rejected,
            'closed_at' => $day->subDays(8),
        ]);

        $this->log($request, $warden, MaintenanceRequestStatus::Submitted, MaintenanceRequestStatus::Rejected,
            'The kettle belongs to the resident and not to the dormitory, so this is not ours to repair.',
            $day->subDays(8));
    }

    /**
     * FR-40, fourth criterion. Long past both the default threshold and its
     * own planned date — the state a demonstration cannot reach by waiting.
     */
    private function overdue(
        Building $building,
        User $reporter,
        User $warden,
        CarbonImmutable $day,
        int $index,
    ): void {
        $request = $this->make($building, $reporter, $index, $day->subDays(30), [
            'status' => MaintenanceRequestStatus::Accepted,
            'urgency' => MaintenanceUrgency::Emergency,
            'target_date' => $day->subDays(20)->toDateString(),
            'assigned_to' => $warden->getKey(),
            'assigned_at' => $day->subDays(29),
        ]);

        $this->log($request, $warden, MaintenanceRequestStatus::Submitted, MaintenanceRequestStatus::Accepted,
            'Waiting on the contractor.', $day->subDays(29));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function make(
        Building $building,
        User $reporter,
        int $index,
        CarbonImmutable $filedAt,
        array $overrides = [],
    ): MaintenanceRequest {
        [$category, $description] = self::DEFECTS[$index % count(self::DEFECTS)];

        $room = $this->roomOf($reporter, $building);

        $request = MaintenanceRequest::query()->create(array_merge([
            'building_id' => $building->getKey(),
            'room_id' => $room?->getKey(),
            'reporter_id' => $reporter->getKey(),
            'category' => $category,
            'location' => $room === null ? MaintenanceLocation::CommonArea : MaintenanceLocation::OwnRoom,
            'location_note' => $room === null ? 'The kitchen on the fourth floor' : null,
            'description' => $description,
            'urgency' => MaintenanceUrgency::Routine,
            'photo_paths' => [],
            'status' => MaintenanceRequestStatus::Submitted,
        ], $overrides));

        // The submission's own row, and the timestamps the demonstration needs
        // the queue to be able to compute an age from.
        $request->forceFill(['created_at' => $filedAt, 'updated_at' => $filedAt])->save();

        $this->log($request, $reporter, null, MaintenanceRequestStatus::Submitted, $description, $filedAt);

        return $request;
    }

    private function walkTheGraph(
        MaintenanceRequest $request,
        User $warden,
        CarbonImmutable $acceptedAt,
        CarbonImmutable $startedAt,
        CarbonImmutable $completedAt,
    ): void {
        $this->log($request, $warden, MaintenanceRequestStatus::Submitted, MaintenanceRequestStatus::Accepted,
            'Taken into work.', $acceptedAt);
        $this->log($request, $warden, MaintenanceRequestStatus::Accepted, MaintenanceRequestStatus::InProgress,
            null, $startedAt);
        $this->log($request, $warden, MaintenanceRequestStatus::InProgress, MaintenanceRequestStatus::Completed,
            'The washer is replaced.', $completedAt);
    }

    private function log(
        MaintenanceRequest $request,
        ?User $actor,
        ?MaintenanceRequestStatus $from,
        MaintenanceRequestStatus $to,
        ?string $comment,
        CarbonImmutable $at,
    ): MaintenanceWorkLog {
        return MaintenanceWorkLog::query()->create([
            'maintenance_request_id' => $request->getKey(),
            'actor_id' => $actor?->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'comment' => $comment,
            'created_at' => $at,
        ]);
    }

    private function roomOf(User $reporter, Building $building): ?Room
    {
        return Residency::query()
            ->where('user_id', $reporter->getKey())
            ->inBuilding($building)
            ->currentOn(CarbonImmutable::now())
            ->with('bed.room')
            ->first()?->bed?->room;
    }

    /**
     * @return list<User>
     */
    private function residentsOf(Building $building): array
    {
        return User::query()
            ->whereHas('roleGrants', fn (Builder $grant) => $grant
                ->where('building_id', $building->getKey())
                ->whereHas('role', fn (Builder $role) => $role->where('code', RoleCode::Resident->value)))
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->all();
    }

    /**
     * Whoever triages in this dormitory — asked as a capability rather than as
     * two role names, for the reason §3.3.3 gives.
     */
    private function triageStaffOf(Building $building): ?User
    {
        $codes = array_values(array_map(
            static fn (RoleCode $code): string => $code->value,
            array_filter(
                RoleCode::cases(),
                static fn (RoleCode $code): bool => $code->grants(Permission::TriageMaintenanceRequests),
            ),
        ));

        if ($codes === [] || Role::query()->whereIn('code', $codes)->doesntExist()) {
            return null;
        }

        return User::query()
            ->whereHas('roleGrants', fn (Builder $grant) => $grant
                ->where('building_id', $building->getKey())
                ->whereHas('role', fn (Builder $role) => $role->whereIn('code', $codes)))
            ->orderBy('id')
            ->first();
    }
}
