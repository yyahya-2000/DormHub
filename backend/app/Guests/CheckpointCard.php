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
     * Whether this card may name anybody.
     *
     * A withdrawn, refused or expired request still answers to its code —
     * deliberately, so that a guest who turns up is told the visit was
     * cancelled rather than that no such code exists — but there is no visit
     * about to be recorded and therefore no ground for putting the guest's
     * name, the host's name and the host's room on the screen (§2.7.1).
     * The verdict and the status stay; the people do not.
     */
    public function disclosesTheGuest(): bool
    {
        return $this->request->status->disclosesTheGuest();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (! $this->disclosesTheGuest()) {
            return $this->withheld();
        }

        $window = $this->request->plannedWindow();
        $student = $this->request->student;

        return [
            'guest_request_id' => $this->request->getKey(),
            'disclosed' => true,
            'access_code' => $this->request->access_code,

            // 1. The guest. A name: the document itself is in the officer's
            // hand and the register keeps no copy of it.
            'guest' => [
                'full_name' => $this->request->guest_full_name,
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
     * The same card with every person taken out of it.
     *
     * The shape is the shape of the card and not a shorter object, so that one
     * screen draws both and the absence is a value the client reads rather
     * than a field it has to discover is missing. What survives is what the
     * officer needs in order to say something true to the person at the desk:
     * this code belongs to a visit that is not going to happen, and here is
     * why.
     *
     * @return array<string, mixed>
     */
    private function withheld(): array
    {
        return [
            'guest_request_id' => $this->request->getKey(),
            'disclosed' => false,
            'access_code' => null,
            'guest' => null,
            'inviting_resident' => null,
            'room' => null,
            'due_at' => null,
            'permitted_interval' => null,
            'status' => $this->request->status->value,
            'status_label' => $this->request->status->label(),
            'visit' => null,
            'admission' => [
                'allowed' => false,
                'reason_code' => $this->refusal?->reasonCode,
                'reason' => $this->refusal?->getMessage(),
                'override_available' => false,
                'override_requires_reason' => false,
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
