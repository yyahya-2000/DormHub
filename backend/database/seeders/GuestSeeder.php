<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConsentDocument;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestVisitStatus;
use App\Enums\RoleCode;
use App\Guests\AccessCodeGenerator;
use App\Models\Building;
use App\Models\ConsentRecord;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The guest module on the development stand: one request in each state the
 * duty officer's queue, the security post and the visitor register have to be
 * able to draw.
 *
 * **Every guest here is invented (C-05).** The names are taken from a fixed
 * list by index, never sampled, so a second run produces the same people.
 *
 * **The states, and why each of them is here.** Pending review, because the
 * queue is empty without it and the queue is the officer's screen. Approved
 * with a code, because that is what the post is handed. In progress with an
 * open visit, so the «who is still inside» list is not empty. Overdue, because
 * FR-20's second criterion is the hardest thing to see by hand — waiting for
 * the closing hour is not a demonstration. Completed, so the register of FR-21
 * has rows with both times filled in. And rejected with a reason, because
 * FR-17's second criterion is about the reason and not about the refusal.
 *
 * The seeder runs after the housing register and the staff accounts, because
 * every request names a resident who invites and a dormitory they live in. It
 * seeds nothing when either is missing, exactly as the consent and
 * notification seeders do.
 */
class GuestSeeder extends Seeder
{
    /** Invented guests: given names and surnames combined by index. */
    private const GUEST_NAMES = [
        'Ostap Verigin', 'Lidia Zhuravskaya', 'Ruslan Ashikhmin', 'Evgenia Tolokonnikova',
        'Marat Sagdiev', 'Ksenia Perevalova', 'Bogdan Ryzhkov', 'Oksana Shatalina',
    ];

    public function run(): void
    {
        $building = Building::query()->orderBy('id')->first();

        if ($building === null) {
            return;
        }

        $residents = $this->residentsOf($building);
        $officer = $this->staffOf($building, RoleCode::DutyOfficer);
        $guard = $this->staffOf($building, RoleCode::SecurityOfficer);

        if ($residents === [] || $officer === null || $guard === null) {
            return;
        }

        if (GuestRequest::query()->where('building_id', $building->getKey())->exists()) {
            // Idempotent in the only way that matters: a second run must not
            // double the queue the demonstration is walked through.
            return;
        }

        $today = CarbonImmutable::now();

        $this->pending($building, $residents[0], $today, 0);
        $this->approved($building, $residents[0 % count($residents)], $today, 1);
        $this->rejected($building, $residents[1 % count($residents)], $today, 2, $officer);
        $this->inProgress($building, $residents[1 % count($residents)], $today, 3, $officer, $guard);
        $this->overdue($building, $residents[2 % count($residents)], $today, 4, $officer, $guard);
        $this->completed($building, $residents[2 % count($residents)], $today, 5, $officer, $guard);
    }

    private function pending(Building $building, User $student, CarbonImmutable $day, int $index): GuestRequest
    {
        return $this->make($building, $student, $index, [
            'visit_date' => $day->addDay()->toDateString(),
            'planned_from' => '15:00:00',
            'planned_to' => '20:00:00',
            'status' => GuestRequestStatus::PendingReview,
        ]);
    }

    private function approved(Building $building, User $student, CarbonImmutable $day, int $index): GuestRequest
    {
        return $this->make($building, $student, $index, [
            'visit_date' => $day->toDateString(),
            'planned_from' => '14:00:00',
            'planned_to' => '23:00:00',
            'status' => GuestRequestStatus::Approved,
            'access_code' => app(AccessCodeGenerator::class)->generate(),
            'decided_by' => $this->staffOf($building, RoleCode::DutyOfficer)?->getKey(),
            'decided_at' => $day->subHours(3),
        ]);
    }

    private function rejected(
        Building $building,
        User $student,
        CarbonImmutable $day,
        int $index,
        User $officer,
    ): GuestRequest {
        return $this->make($building, $student, $index, [
            'visit_date' => $day->toDateString(),
            'planned_from' => '21:00:00',
            'planned_to' => '23:00:00',
            'status' => GuestRequestStatus::Rejected,
            'decided_by' => $officer->getKey(),
            'decided_at' => $day->subHours(6),
            'decision_comment' => 'The daily ceiling for this room has already been reached.',
        ]);
    }

    private function inProgress(
        Building $building,
        User $student,
        CarbonImmutable $day,
        int $index,
        User $officer,
        User $guard,
    ): void {
        $request = $this->make($building, $student, $index, [
            'visit_date' => $day->toDateString(),
            'planned_from' => '12:00:00',
            'planned_to' => '22:00:00',
            'status' => GuestRequestStatus::InProgress,
            'access_code' => app(AccessCodeGenerator::class)->generate(),
            'decided_by' => $officer->getKey(),
            'decided_at' => $day->subHours(8),
        ]);

        $this->guestConsent($request);

        GuestVisit::query()->create([
            'guest_request_id' => $request->getKey(),
            'checked_in_at' => $day->subHours(2),
            'checked_in_by' => $guard->getKey(),
            'status' => GuestVisitStatus::InBuilding,
            'due_at' => $request->dueAt($building),
        ]);
    }

    /**
     * FR-20. Yesterday's visit, reported overdue and never closed — the state
     * a demonstration cannot reach by waiting.
     */
    private function overdue(
        Building $building,
        User $student,
        CarbonImmutable $day,
        int $index,
        User $officer,
        User $guard,
    ): void {
        $yesterday = $day->subDay();

        $request = $this->make($building, $student, $index, [
            'visit_date' => $yesterday->toDateString(),
            'planned_from' => '18:00:00',
            'planned_to' => '23:00:00',
            'status' => GuestRequestStatus::Overdue,
            'access_code' => app(AccessCodeGenerator::class)->generate(),
            'decided_by' => $officer->getKey(),
            'decided_at' => $yesterday->setTime(16, 0),
        ]);

        $this->guestConsent($request);

        $due = $request->dueAt($building);

        GuestVisit::query()->create([
            'guest_request_id' => $request->getKey(),
            'checked_in_at' => $yesterday->setTime(18, 0),
            'checked_in_by' => $guard->getKey(),
            'status' => GuestVisitStatus::Overdue,
            'due_at' => $due,
            'overdue_notified_at' => $due->addMinutes(10),
        ]);
    }

    private function completed(
        Building $building,
        User $student,
        CarbonImmutable $day,
        int $index,
        User $officer,
        User $guard,
    ): void {
        $yesterday = $day->subDay();

        $request = $this->make($building, $student, $index, [
            'visit_date' => $yesterday->toDateString(),
            'planned_from' => '13:00:00',
            'planned_to' => '17:00:00',
            'status' => GuestRequestStatus::Completed,
            'access_code' => app(AccessCodeGenerator::class)->generate(),
            'decided_by' => $officer->getKey(),
            'decided_at' => $yesterday->setTime(11, 0),
        ]);

        $this->guestConsent($request);

        GuestVisit::query()->create([
            'guest_request_id' => $request->getKey(),
            'checked_in_at' => $yesterday->setTime(13, 15),
            'checked_in_by' => $guard->getKey(),
            'checked_out_at' => $yesterday->setTime(16, 40),
            'checked_out_by' => $guard->getKey(),
            'status' => GuestVisitStatus::Closed,
            'due_at' => $request->dueAt($building),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(Building $building, User $student, int $index, array $attributes): GuestRequest
    {
        // The caller's attributes come first: `+` on arrays keeps the left
        // operand, so the defaults have to be the right-hand side or a state
        // that overrides one of them is silently discarded.
        return GuestRequest::query()->create($attributes + [
            'student_id' => $student->getKey(),
            'building_id' => $building->getKey(),
            'guest_full_name' => self::GUEST_NAMES[$index % count(self::GUEST_NAMES)],
        ]);
    }

    /**
     * The consent taken at the post (§2.7.1). Every request that produced a
     * visit carries one, because without it the entry could not lawfully have
     * been recorded — a stand whose register held entries with no consent
     * behind them would be demonstrating the wrong thing.
     */
    private function guestConsent(GuestRequest $request): void
    {
        ConsentRecord::query()->create([
            'user_id' => null,
            'guest_request_id' => $request->getKey(),
            'document_code' => ConsentDocument::GuestPersonalData->value,
            'document_revision' => (string) config(
                'dormitory.consent.revisions.'.ConsentDocument::GuestPersonalData->value
            ),
            'accepted_at' => now(),
            // RFC 5737's documentation range, which cannot belong to anyone.
            'ip_address' => '192.0.2.200',
        ]);
    }

    /**
     * @return list<User>
     */
    private function residentsOf(Building $building): array
    {
        $role = Role::query()->where('code', RoleCode::Resident->value)->first();

        if ($role === null) {
            return [];
        }

        return User::query()
            ->whereHas('roleGrants', fn ($query) => $query
                ->where('role_id', $role->getKey())
                ->where('building_id', $building->getKey()))
            ->orderBy('id')
            ->limit(4)
            ->get()
            ->all();
    }

    private function staffOf(Building $building, RoleCode $code): ?User
    {
        $role = Role::query()->where('code', $code->value)->first();

        if ($role === null) {
            return null;
        }

        return User::query()
            ->whereHas('roleGrants', fn ($query) => $query
                ->where('role_id', $role->getKey())
                ->where('building_id', $building->getKey()))
            ->orderBy('id')
            ->first();
    }
}
