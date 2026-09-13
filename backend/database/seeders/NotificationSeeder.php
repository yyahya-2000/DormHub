<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NotificationCategory;
use App\Enums\RoleCode;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Notifications\DocumentAwaitingSignature;
use App\Notifications\GuestRequestDecided;
use App\Notifications\GuestVisitOverdue;
use App\Notifications\MaintenanceRequestStatusChanged;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * FR-34 on the development stand: messages to read and a switch that has been
 * moved.
 *
 * **The rows are written, not sent.** The notifications table is filled
 * directly rather than by dispatching the notifications, for two reasons. A
 * dispatch would go through the queue and through a mail transport, and a
 * seeder that needs a worker running is a seeder that fails half the time. And
 * the point of seeding here is the *state* of the personal account — read and
 * unread, four categories, one of them muted — not the delivery, which the
 * tests cover on the path that matters.
 *
 * Every name, request number and comment below is invented (C-05).
 *
 * The last resident of the list has the maintenance-status category switched
 * off, so that the settings screen has a switch in each position and the
 * consequence of the switch is visible: they have no message of that category.
 */
class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $residents = $this->residents();

        if ($residents === []) {
            return;
        }

        $muted = $residents[count($residents) - 1];

        NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $muted->getKey(),
                'category' => NotificationCategory::MaintenanceStatus->value,
            ],
            ['enabled' => false],
        );

        foreach ($residents as $index => $resident) {
            if ($resident->notifications()->exists()) {
                continue;
            }

            foreach ($this->messagesFor($index, $resident->is($muted)) as $position => [$notification, $readAfterDays]) {
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
     * One message of each category, minus the one this person switched off.
     *
     * @return list<array{0: object, 1: int|null}>
     */
    private function messagesFor(int $index, bool $maintenanceMuted): array
    {
        $messages = [
            [new GuestRequestDecided(
                requestId: 1000 + $index,
                guestName: ['Yuliana Beketova', 'Prokhor Ovsyannikov', 'Rimma Khoroshilova'][$index % 3],
                approved: $index % 2 === 0,
                comment: $index % 2 === 0 ? null : 'The visiting window is already full for that evening.',
            ), 2],
            [new GuestVisitOverdue(
                visitId: 2000 + $index,
                guestName: ['Yuliana Beketova', 'Prokhor Ovsyannikov', 'Rimma Khoroshilova'][$index % 3],
                buildingName: 'Block A',
                dueAt: now()->subDays(9)->setTime(23, 0),
            ), null],
            [new DocumentAwaitingSignature(
                documentCode: 'accommodation_agreement',
                revision: '2026-08-15',
                title: 'Accommodation agreement, annexe 2',
                dueAt: now()->addDays(14),
            ), null],
        ];

        if (! $maintenanceMuted) {
            $messages[] = [new MaintenanceRequestStatusChanged(
                requestId: 3000 + $index,
                fromStatus: 'assigned',
                toStatus: 'done',
                comment: 'The tap in the kitchen was replaced.',
            ), null];
        }

        return $messages;
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
