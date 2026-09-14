<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\GuestVisit;
use App\Models\Residency;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FR-21, «Visitor register».
 *
 * **It is the journal of clause 2.1.2 with the pen removed (§2.5.2).** The
 * clause makes the security service write down, by hand, who the guest is,
 * when they came, when they left, which premises they went to and whom they
 * were visiting. Those are the columns below. The last one — the operator who
 * recorded the entry — is not in the clause and is not optional either: a
 * paper journal is written in somebody's handwriting on a numbered page, and
 * an electronic one has to say in words what the page said by existing.
 *
 * The details of the document are the one thing the clause asks for that the
 * register no longer holds: the dormitory stopped storing them, so there is
 * nothing to show.
 *
 * **The corrections travel with the rows they correct.** FR-21's second
 * criterion makes the entries immutable and a correction a correcting entry; a
 * register that showed the entries and hid the corrections would present a
 * record it does not itself stand behind. Each row therefore carries the
 * `guest_visit.corrected` log entries that name it.
 */
final readonly class VisitRegister
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * One page of the register of one dormitory (FR-21, first criterion), both
     * days of the period included.
     *
     * The period is taken against the **entry**, not against the visit date of
     * the request. A guest admitted at 23:50 on the 30th and recorded leaving
     * at 00:20 on the 1st belongs to the 30th, which is the day the officer
     * would have written them down on.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(
        Building $building,
        CarbonInterface $from,
        CarbonInterface $until,
        int $perPage,
    ): LengthAwarePaginator {
        $visits = GuestVisit::query()
            ->inBuilding($building)
            ->whereBetween('checked_in_at', [
                $from->copy()->startOfDay(),
                $until->copy()->endOfDay(),
            ])
            ->with(['request.student', 'checkedInBy', 'checkedOutBy'])
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->paginate($perPage);

        $corrections = $this->correctionsFor($visits->getCollection());

        return $visits->setCollection(
            $visits->getCollection()->map(fn (GuestVisit $visit): array => $this->row($visit, $corrections))
        );
    }

    /**
     * §3.9.6: reading the register is itself an audited action.
     */
    public function recordRead(
        User $viewer,
        Building $building,
        CarbonInterface $from,
        CarbonInterface $until,
        int $rows,
        ?string $ipAddress = null,
    ): void {
        $this->audit->record(
            action: AuditAction::VisitRegisterViewed,
            actor: $viewer,
            subject: $building,
            payload: [
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
                'rows' => $rows,
            ],
            ipAddress: $ipAddress,
        );
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $corrections
     * @return array<string, mixed>
     */
    private function row(GuestVisit $visit, array $corrections): array
    {
        $request = $visit->request;
        $student = $request?->student;

        return [
            'guest_visit_id' => $visit->getKey(),
            'guest_full_name' => (string) $request?->guest_full_name,
            'inviting_resident' => (string) $student?->full_name,
            'inviting_resident_id' => $student?->getKey(),
            'room' => $student === null ? '' : $this->roomOf($student, $visit),
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            'due_at' => $visit->due_at?->toIso8601String(),
            'status' => $visit->status->value,
            // The officer who wrote the entry, and the one who wrote the exit:
            // the two may be different shifts, so the row names both.
            'recorded_by' => $visit->checkedInBy?->full_name,
            'closed_by' => $visit->checkedOutBy?->full_name,
            'admitted_on_decision' => $visit->admitted_on_decision,
            'admission_note' => $visit->admission_note,
            'corrections' => $corrections[(int) $visit->getKey()] ?? [],
        ];
    }

    /**
     * The room the guest was bound for: the one the inviting resident holds on
     * the day of the visit.
     *
     * Read against the residency register as it stood **that day** rather than
     * as it stands now. A resident who has since moved rooms did not receive
     * their guest in the room they live in today, and a register that said so
     * would be wrong about the one column clause 2.1.2 calls «premises».
     */
    private function roomOf(User $student, GuestVisit $visit): string
    {
        $on = $visit->checked_in_at ?? $visit->due_at;

        $residency = Residency::query()
            ->where('user_id', $student->getKey())
            ->when($on !== null, fn ($query) => $query->currentOn($on))
            ->with('bed.room')
            ->orderByDesc('moved_in_at')
            ->first();

        $room = $residency?->bed?->room;

        return $room === null ? '' : (string) $room->number;
    }

    /**
     * The correcting entries of FR-21, gathered for a page of visits in one
     * query rather than one per row.
     *
     * @param  Collection<int, GuestVisit>  $visits
     * @return array<int, list<array<string, mixed>>>
     */
    private function correctionsFor(Collection $visits): array
    {
        if ($visits->isEmpty()) {
            return [];
        }

        return AuditLog::query()
            ->where('action', AuditAction::GuestVisitCorrected->value)
            ->where('subject_type', GuestVisit::class)
            ->whereIn('subject_id', $visits->modelKeys())
            ->orderBy('created_at')
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $entries): array => $entries
                ->map(fn (AuditLog $entry): array => [
                    'recorded_at' => $entry->created_at?->toIso8601String(),
                    'by' => $entry->user_id,
                    'correction' => $entry->payload['correction'] ?? null,
                ])
                ->values()
                ->all())
            ->all();
    }
}
