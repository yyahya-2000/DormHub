<?php

declare(strict_types=1);

namespace App\Guests;

use App\Exceptions\EntryNotPermittedException;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use App\Models\Residency;
use Carbon\CarbonInterface;

/**
 * What the officer at the post reads, and the whole of FR-18's second
 * criterion: «the card contains all five fields — guest, inviting resident,
 * room, departure deadline, status».
 *
 * A value object rather than a resource, because the card is a domain answer
 * and not a view of one model. Three tables meet in it — the request, the
 * residency register that says which room the host holds, and the visit if
 * there already is one — and the officer's next action follows from all three
 * together. Assembling that in a JSON resource would put the decision «may
 * this guest be admitted» in the presentation layer, which §3.3.3 is about.
 *
 * **The admission verdict travels with the card**, and this is what §2.4.2's
 * two scenarios turn on. At 14:20 on a 14:00–23:00 request the card shows the
 * five fields and the entry action is enabled. At 23:40 the same code shows
 * «outside the permitted interval», the entry action is blocked, and the
 * action that admits the guest on the responsible officer's decision is
 * offered with a mandatory reason. Both are the same object with a different
 * `refusal`, so the screen cannot offer a button the server would then refuse.
 */
final readonly class CheckpointCard
{
    public function __construct(
        public GuestRequest $request,
        public ?GuestVisit $visit,
        public ?string $room,
        public ?EntryNotPermittedException $refusal,
        public CarbonInterface $at,
    ) {}

    public function mayRecordEntry(): bool
    {
        return $this->refusal === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $window = $this->request->plannedWindow();
        $student = $this->request->student;

        return [
            'guest_request_id' => $this->request->getKey(),
            'access_code' => $this->request->access_code,

            // 1. The guest.
            'guest' => [
                'full_name' => $this->request->guest_full_name,
                'document_type' => $this->request->guest_doc_type?->value,
                'document_type_label' => $this->request->guest_doc_type?->label(),
                // NFR-06. What a comparison against the document in the
                // officer's hand needs, and not a character more.
                'document_number_masked' => $this->request->maskedDocumentNumber(),
                'is_foreign_document' => (bool) $this->request->is_foreign_document,
            ],

            // 2. The inviting resident.
            'inviting_resident' => [
                'id' => $student?->getKey(),
                'full_name' => $student?->full_name,
            ],

            // 3. The room. Null when the register has no bed for the host,
            // which is a real state — an account issued before a place was
            // assigned (FR-42 before FR-03) — and is shown as absent rather
            // than guessed at.
            'room' => $this->room,

            // 4. The departure deadline.
            'due_at' => $this->request->dueAt()->toIso8601String(),
            'permitted_interval' => $window->format(),

            // 5. The status.
            'status' => $this->request->status->value,
            'status_label' => $this->request->status->label(),

            'visit' => $this->visit === null ? null : [
                'id' => $this->visit->getKey(),
                'checked_in_at' => $this->visit->checked_in_at?->toIso8601String(),
                'checked_out_at' => $this->visit->checked_out_at?->toIso8601String(),
                'status' => $this->visit->status->value,
            ],

            'consent_on_record' => $this->request->consentRecords
                ->contains(fn ($record): bool => $record->isInForce()),

            'admission' => [
                'allowed' => $this->mayRecordEntry(),
                'reason_code' => $this->refusal?->reasonCode,
                'reason' => $this->refusal?->getMessage(),
                'override_available' => $this->refusal?->overrideAvailable ?? false,
                'override_requires_reason' => $this->refusal?->overrideAvailable ?? false,
            ],

            'checked_at' => $this->at->toIso8601String(),
        ];
    }

    /**
     * The room the inviting resident holds today, read off the residency
     * register — BUILDING → ROOM → BED → RESIDENCY travelled backwards.
     */
    public static function roomOf(GuestRequest $request, CarbonInterface $on): ?string
    {
        $student = $request->student;

        if ($student === null) {
            return null;
        }

        $residency = Residency::query()
            ->where('user_id', $student->getKey())
            ->currentOn($on)
            ->with('bed.room')
            ->first();

        $room = $residency?->bed?->room;

        return $room === null ? null : (string) $room->number;
    }
}
