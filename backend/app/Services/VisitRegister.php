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
use Illuminate\Support\Collection;

/**
 * FR-21, «Visitor register», and §3.9.6's «export of the visitor register».
 *
 * **It is the journal of clause 2.1.2 with the pen removed (§2.5.2).** The
 * clause makes the security service write down, by hand, six things about
 * every outsider admitted: who the guest is, when they came, when they left,
 * which premises they went to, whom they were visiting, and the details of
 * their document. Those six are the columns below. The seventh — the operator
 * who recorded the entry — is not in the clause and is not optional either: a
 * paper journal is written in somebody's handwriting on a numbered page, and
 * an electronic one has to say in words what the page said by existing.
 *
 * **The document number is exported masked.** That is a departure from the
 * paper journal, which holds the number in full, and it is deliberate. NFR-06
 * has the column encrypted and displayed masked; an export is a file that
 * leaves the system and is copied, so it is the last place a full number
 * should appear by default. The type and the last four characters identify the
 * entry against the original document, and the number in full is available one
 * request at a time through the audited route of `GuestRequestService`. The
 * decision belongs to the operator's responsible officer rather than to a
 * developer, and it is recorded here so that it can be put to them.
 *
 * **The corrections travel with the rows they correct.** FR-21's second
 * criterion makes the entries immutable and a correction a correcting entry;
 * an export that showed the entries and hid the corrections would present a
 * record the register itself does not stand behind. Each row therefore carries
 * the `guest_visit.corrected` log entries that name it.
 *
 * §3.9.6 also asks for PDF beside CSV. This iteration produces CSV and JSON;
 * see the report on what is left for the next.
 */
final readonly class VisitRegister
{
    /** The seven columns, in the order clause 2.1.2 lists them. */
    public const COLUMNS = [
        'guest_full_name' => 'Guest',
        'guest_document' => 'Document',
        'inviting_resident' => 'Visiting',
        'room' => 'Premises',
        'checked_in_at' => 'Entered',
        'checked_out_at' => 'Left',
        'operator' => 'Recorded by',
    ];

    public function __construct(private AuditRecorder $audit) {}

    /**
     * The register of one dormitory over an arbitrary period (FR-21, first
     * criterion), both days included.
     *
     * The period is taken against the **entry**, not against the visit date of
     * the request. A guest admitted at 23:50 on the 30th and recorded leaving
     * at 00:20 on the 1st belongs to the 30th, which is the day the officer
     * would have written them down on.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(
        Building $building,
        CarbonInterface $from,
        CarbonInterface $until,
        int $limit,
        int $offset = 0,
    ): Collection {
        $visits = GuestVisit::query()
            ->inBuilding($building)
            ->whereBetween('checked_in_at', [
                $from->copy()->startOfDay(),
                $until->copy()->endOfDay(),
            ])
            ->with(['request.student', 'checkedInBy', 'checkedOutBy'])
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $corrections = $this->correctionsFor($visits);

        return $visits->map(fn (GuestVisit $visit): array => $this->row($visit, $corrections));
    }

    public function count(Building $building, CarbonInterface $from, CarbonInterface $until): int
    {
        return GuestVisit::query()
            ->inBuilding($building)
            ->whereBetween('checked_in_at', [
                $from->copy()->startOfDay(),
                $until->copy()->endOfDay(),
            ])
            ->count();
    }

    /**
     * The same rows as CSV, with a header line naming the seven columns.
     *
     * Written through `fputcsv` into a memory stream rather than by joining
     * strings: a guest's name can contain a comma, an operator's note can
     * contain a quotation mark, and a register that breaks its own format on
     * one row in a thousand is worse than no export at all.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function toCsv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_values(self::COLUMNS));

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['guest_full_name'],
                $row['guest_document'],
                $row['inviting_resident'],
                $row['room'],
                $row['checked_in_at'],
                $row['checked_out_at'] ?? '',
                $row['operator'],
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * §3.9.6: the export «is itself an audited action».
     */
    public function recordExport(
        User $viewer,
        Building $building,
        CarbonInterface $from,
        CarbonInterface $until,
        string $format,
        int $rows,
        ?string $ipAddress = null,
    ): void {
        $this->audit->record(
            action: $format === 'json'
                ? AuditAction::VisitRegisterViewed
                : AuditAction::VisitRegisterExported,
            actor: $viewer,
            subject: $building,
            payload: [
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
                'format' => $format,
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
            'guest_document' => $request === null
                ? ''
                : $request->guest_doc_type?->label().' '.$request->maskedDocumentNumber(),
            'guest_doc_type' => $request?->guest_doc_type?->value,
            'is_foreign_document' => (bool) $request?->is_foreign_document,
            'inviting_resident' => (string) $student?->full_name,
            'inviting_resident_id' => $student?->getKey(),
            'room' => $student === null ? '' : $this->roomOf($student, $visit),
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            'due_at' => $visit->due_at?->toIso8601String(),
            'status' => $visit->status->value,
            // The operator of the entry is the one the journal names. The exit
            // may have been recorded by the officer of the next shift, so both
            // are carried and the CSV column shows the pair when they differ.
            'operator' => $this->operatorOf($visit),
            'recorded_by' => $visit->checkedInBy?->full_name,
            'closed_by' => $visit->checkedOutBy?->full_name,
            'admitted_on_decision' => $visit->admitted_on_decision,
            'admission_note' => $visit->admission_note,
            'corrections' => $corrections[(int) $visit->getKey()] ?? [],
        ];
    }

    private function operatorOf(GuestVisit $visit): string
    {
        $entered = (string) $visit->checkedInBy?->full_name;
        $left = $visit->checkedOutBy?->full_name;

        return $left === null || $left === $entered
            ? $entered
            : $entered.' / '.$left;
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
