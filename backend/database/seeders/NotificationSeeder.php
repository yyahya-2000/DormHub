<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GuestVisitStatus;
use App\Enums\RoleCode;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use App\Models\MaintenanceWorkLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GuestRequestDecided;
use App\Notifications\GuestVisitOverdue;
use App\Notifications\MaintenanceRequestStatusChanged;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * FR-34 on the development stand: messages to read.
 *
 * **The rows are written, not sent.** The notifications table is filled
 * directly rather than by dispatching the notifications, for two reasons. A
 * dispatch would go through the queue and through a mail transport, and a
 * seeder that needs a worker running is a seeder that fails half the time. And
 * the point of seeding here is the *state* of the personal account — three
 * categories, one of them already read and the rest not — not the delivery,
 * which the tests cover on the path that matters.
 *
 * **Every message now describes a row that exists** (acceptance of
 * 15.09.2026). It used to describe rows that did not: guest request 1000+n,
 * visit 2000+n, maintenance request 3000+n, and a repair moving from
 * «assigned» to «done» — two states `MaintenanceRequestStatus` has never had,
 * because they are §3.5.2's diagram labels and not FR-38's vocabulary. On the
 * demonstration a resident opened his personal account and read that request
 * 3002 had gone to «done», and both the number and the word were fiction. A
 * stand that says things about records nobody can open is worse than a stand
 * with an empty inbox.
 *
 * So the seeder reads the register instead of inventing one, which is why it
 * runs last in `DatabaseSeeder`: the guest and maintenance modules have to
 * have written their rows before there is anything to write a message about.
 * A resident with nothing in either module gets nothing here — the demo
 * accounts of the other seeders are the ones with a full inbox, and they are
 * the accounts the demonstration signs in as.
 *
 * Every name and comment the messages carry comes from the seeded row, and
 * everything in those rows is invented (C-05).
 */
class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->residents() as $resident) {
            if ($resident->notifications()->exists()) {
                continue;
            }

            foreach ($this->messagesFor($resident) as $position => [$notification, $readAfterDays]) {
                $sentAt = now()->subDays(10 - $position);

                $resident->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => $notification::class,
                    'data' => $notification->toDatabase($resident),
                    'read_at' => $readAfterDays === null ? null : $sentAt->copy()->addDays($readAfterDays),
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ]);
            }
        }
    }

    /**
     * One message of each category the personal account can draw, for the
     * occasions this resident actually has. The first is read and the rest are
     * not, which is the state the screen is worth looking at in.
     *
     * @return list<array{0: object, 1: int|null}>
     */
    private function messagesFor(User $resident): array
    {
        return array_values(array_filter([
            $this->theDecisionOnTheirGuest($resident),
            $this->theGuestWhoStayedTooLong($resident),
            $this->theRepairThatMoved($resident),
        ]));
    }

    /**
     * FR-17's decision, taken from the request the guest seeder decided.
     *
     * @return array{0: object, 1: int|null}|null
     */
    private function theDecisionOnTheirGuest(User $resident): ?array
    {
        $request = GuestRequest::query()
            ->where('student_id', $resident->getKey())
            ->whereNotNull('decided_at')
            ->orderBy('id')
            ->first();

        if ($request === null) {
            return null;
        }

        $approved = $request->status?->value !== 'rejected';

        return [new GuestRequestDecided(
            requestId: (int) $request->getKey(),
            guestName: (string) $request->guest_full_name,
            approved: $approved,
            comment: $request->decision_comment,
        ), 2];
    }

    /**
     * FR-20's overdue visit, taken from the visit the guest seeder left open
     * past the closing hour.
     *
     * @return array{0: object, 1: int|null}|null
     */
    private function theGuestWhoStayedTooLong(User $resident): ?array
    {
        $visit = GuestVisit::query()
            ->whereHas('request', fn ($query) => $query->where('student_id', $resident->getKey()))
            ->whereIn('status', [GuestVisitStatus::Overdue->value, GuestVisitStatus::ClosedLate->value])
            ->with('request.building')
            ->orderBy('id')
            ->first();

        if ($visit === null || $visit->due_at === null) {
            return null;
        }

        return [new GuestVisitOverdue(
            visitId: (int) $visit->getKey(),
            guestName: (string) $visit->request?->guest_full_name,
            buildingName: (string) $visit->request?->building?->name,
            dueAt: $visit->due_at,
        ), null];
    }

    /**
     * FR-38's move, taken from the work log of a request this resident filed —
     * so the two states in the message are the two states of the row, in
     * FR-38's vocabulary, and the number is a request the reader can open.
     *
     * @return array{0: object, 1: int|null}|null
     */
    private function theRepairThatMoved(User $resident): ?array
    {
        $entry = MaintenanceWorkLog::query()
            ->whereHas('request', fn ($query) => $query->where('reporter_id', $resident->getKey()))
            ->whereNotNull('from_status')
            ->orderByDesc('id')
            ->first();

        if ($entry === null || $entry->from_status === null || $entry->to_status === null) {
            return null;
        }

        return [new MaintenanceRequestStatusChanged(
            requestId: (int) $entry->maintenance_request_id,
            fromStatus: $entry->from_status->value,
            toStatus: $entry->to_status->value,
            comment: $entry->comment,
        ), null];
    }

    /**
     * @return list<User>
     */
    private function residents(): array
    {
        $role = Role::query()->where('code', RoleCode::Resident->value)->first();

        if ($role === null) {
            return [];
        }

        return User::query()
            ->whereHas('roleGrants', fn ($query) => $query->where('role_id', $role->getKey()))
            ->orderBy('id')
            ->get()
            ->all();
    }
}
