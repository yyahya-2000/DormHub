<?php

declare(strict_types=1);

namespace App\Services;

use App\Consent\ConsentText;
use App\Enums\AuditAction;
use App\Enums\ConsentDocument;
use App\Exceptions\ConsentRequiredException;
use App\Models\ConsentRecord;
use App\Models\GuestRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FR-35, «Consent to personal-data processing», in one place.
 *
 * Four criteria, and each of them is a method below.
 *
 * **«Displayed and recorded on first login.»** `pendingFor()` answers what a
 * person has not yet consented to; the sign-in response and `GET /auth/me`
 * carry it, and the client draws the screen. Nothing here blocks the session:
 * see the note on voluntariness below.
 *
 * **«Executed separately from other documents.»** Art. 9 part 1 of Federal Law
 * No. 152-FZ, and it is a rule about the *act*, not about the layout. So
 * `record()` is reached by a request of its own that carries the document and
 * the revision and nothing else — there is no field on the sign-in form, no
 * field on the account form, and no way to consent as a side effect of doing
 * something else. A second document is a second act: `ConsentDocument` has two
 * cases and they are recorded, withdrawn and counted separately.
 *
 * **«The fact, the date and the text revision are stored.»** `record()` writes
 * all three, and the revision is checked against `ConsentTexts` first, so a
 * record can never name a wording the repository cannot produce.
 *
 * **«Withdrawal is available from the personal account.»** `withdraw()`.
 *
 * ---
 *
 * **What happens after a withdrawal, which the criterion does not say.**
 *
 * The decision taken here is that withdrawal stops the processing that rested
 * on consent and nothing else, and that it never deletes the record of the
 * consent itself.
 *
 * For a resident that means: the housing register, the resident card, the
 * accommodation history and the notifications the dormitory is obliged to send
 * all continue, because their ground is the accommodation contract (art. 6
 * part 1 cl. 5) and not consent. What stops are the notification categories
 * `NotificationCategory::restsOnConsent()` marks, which `User::notify()`
 * silences from the moment the consent is withdrawn. A
 * resident who withdraws is then pending again, so the next sign-in offers the
 * text once more; they are not shut out of the application, and this is the
 * substantive point: art. 9 part 1 requires consent to be **free**, and a
 * system that answered a withdrawal by locking the account would be extracting
 * consent rather than receiving it.
 *
 * For a guest the same withdrawal is absolute, and `requireGranted()` is where
 * that shows: consent is the sole ground for processing a guest's data
 * (§2.7.1), so with none on record the entry cannot be written at all.
 *
 * And in both cases the row stays. Art. 9 part 3 puts on the operator the
 * burden of proving that consent was given; deleting the row on withdrawal
 * would destroy the proof that the processing before the withdrawal was
 * lawful, which is the opposite of what the article asks for.
 */
final readonly class ConsentRegistry
{
    public function __construct(
        private ConsentTexts $texts,
        private AuditRecorder $audit,
    ) {}

    /**
     * The documents this person still owes, as the texts themselves.
     *
     * A consent given against a superseded revision does not count: the person
     * agreed to a wording, and the wording has changed. That falls out of
     * comparing the record's revision with the current one and needs no rule
     * of its own.
     *
     * @return list<ConsentText>
     */
    public function pendingFor(User $user): array
    {
        $pending = [];

        foreach (ConsentDocument::askedAtFirstLogin() as $document) {
            $current = $this->texts->current($document);
            $record = $this->inForce($user, $document);

            if ($record === null || $record->document_revision !== $current->revision) {
                $pending[] = $current;
            }
        }

        return $pending;
    }

    /**
     * The consent of this document that stands today, if any.
     *
     * A caller that has already loaded the history — the sign-in answer does,
     * for the pending list it carries — is answered from it rather than with a
     * query per document.
     */
    public function inForce(User $user, ConsentDocument $document): ?ConsentRecord
    {
        if ($user->relationLoaded('consentRecords')) {
            return $user->consentRecords
                ->first(fn (ConsentRecord $record): bool => $record->document_code === $document
                    && $record->isInForce());
        }

        return ConsentRecord::query()
            ->where('user_id', $user->getKey())
            ->forDocument($document)
            ->inForce()
            ->latest('accepted_at')
            ->first();
    }

    public function hasInForce(User $user, ConsentDocument $document): bool
    {
        return $this->inForce($user, $document) !== null;
    }

    /**
     * The whole history, newest first: given, withdrawn, given again. This is
     * what the personal account shows next to the withdrawal button, and what
     * an operator produces when asked on what ground data was processed on a
     * given day.
     *
     * @return Collection<int, ConsentRecord>
     */
    public function historyFor(User $user): Collection
    {
        return ConsentRecord::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Records one act of consent.
     *
     * The revision is checked against the repository before anything is
     * written. A record pointing at a text nobody can produce would satisfy the
     * column and fail the requirement: FR-35 asks for the revision to be stored
     * so that what was agreed to can be shown.
     */
    public function record(
        User $user,
        ConsentDocument $document,
        string $revision,
        ?string $ipAddress = null,
    ): ConsentRecord {
        if (! $this->texts->has($document, $revision)) {
            throw new RuntimeException(sprintf(
                'Revision «%s» of «%s» is not in the repository, so a record naming it would prove nothing.',
                $revision,
                $document->value,
            ));
        }

        $standing = $this->inForce($user, $document);

        /*
         * Consenting again to the revision already in force is not an event:
         * it is a second click on the same button, and a second row would put
         * two «current» consents in the history for no reason. The partial
         * unique index would refuse it anyway; answering with the standing
         * record is the kinder form of the same rule.
         */
        if ($standing !== null && $standing->document_revision === $revision) {
            return $standing;
        }

        /*
         * Three writes that have to stand or fall together: the old consent is
         * closed, the new one is opened, and the log records the act. The
         * partial unique index admits only one consent of a document at a
         * time, so the first two are ordered rather than simultaneous — and
         * outside a transaction a failure between them would leave the person
         * with no consent at all, which is worse than the state they started
         * from.
         */
        return DB::transaction(function () use ($user, $document, $revision, $ipAddress, $standing): ConsentRecord {
            // A new revision supersedes the old consent rather than standing
            // beside it. The old row keeps its dates and is marked withdrawn
            // on the day the new one was given.
            $standing?->forceFill(['revoked_at' => now()])->save();

            $record = ConsentRecord::query()->create([
                'user_id' => $user->getKey(),
                'document_code' => $document->value,
                'document_revision' => $revision,
                'accepted_at' => now(),
                'ip_address' => $ipAddress,
            ]);

            $this->audit->record(
                action: AuditAction::ConsentGranted,
                actor: $user,
                subject: $record,
                payload: [
                    'document' => $document->value,
                    'revision' => $revision,
                    'superseded_record_id' => $standing?->getKey(),
                ],
                ipAddress: $ipAddress,
            );

            return $record;
        });
    }

    /**
     * Withdraws the consent of this document, if one stands.
     *
     * Returns null when there was nothing to withdraw, so a second click is
     * not an error — the state the caller asked for is the state they get.
     */
    public function withdraw(
        User $user,
        ConsentDocument $document,
        ?string $ipAddress = null,
    ): ?ConsentRecord {
        $record = $this->inForce($user, $document);

        if ($record === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $document, $record, $ipAddress): ConsentRecord {
            $record->forceFill(['revoked_at' => now()])->save();

            $this->audit->record(
                action: AuditAction::ConsentWithdrawn,
                actor: $user,
                subject: $record,
                payload: [
                    'document' => $document->value,
                    'revision' => $record->document_revision,
                    'accepted_at' => $record->accepted_at?->toIso8601String(),
                ],
                ipAddress: $ipAddress,
            );

            return $record;
        });
    }

    /**
     * FR-35, first criterion, guest half: the consent taken at the post,
     * recorded against the request rather than against an account.
     *
     * **Why this is a second method and not a nullable argument on the first.**
     * `record()` above takes a `User` because every criterion it serves is
     * about a person with a personal account: the pending list, the sign-in
     * answer, the withdrawal button. A guest has none of those. They have a
     * request, they are standing at a desk, and the officer in front of them
     * is the one operating the screen — so the row names the request as its
     * subject and the operator as the person who witnessed the act. Folding
     * the two into one method with two nullable arguments would have produced
     * exactly one call site for each branch and a signature that says neither.
     *
     * The revision is checked against the repository for the same reason as
     * above: a record naming a wording nobody can produce proves nothing, and
     * art. 9 part 3 of Federal Law No. 152-FZ puts the proving on the operator.
     *
     * A second act on a request that already carries a consent in force at the
     * same revision answers with the standing record — the guest is not asked
     * twice because the officer clicked twice, and the partial unique index
     * `consent_records_guest_active_uniq` would refuse the duplicate anyway.
     */
    public function recordForGuest(
        GuestRequest $request,
        string $revision,
        ?User $operator = null,
        ?string $ipAddress = null,
    ): ConsentRecord {
        $document = ConsentDocument::GuestPersonalData;

        if (! $this->texts->has($document, $revision)) {
            throw new RuntimeException(sprintf(
                'Revision «%s» of «%s» is not in the repository, so a record naming it would prove nothing.',
                $revision,
                $document->value,
            ));
        }

        $standing = $this->inForceForGuest($request);

        if ($standing !== null && $standing->document_revision === $revision) {
            return $standing;
        }

        return DB::transaction(function () use ($request, $document, $revision, $operator, $ipAddress, $standing): ConsentRecord {
            $standing?->forceFill(['revoked_at' => now()])->save();

            $record = ConsentRecord::query()->create([
                'user_id' => null,
                'guest_request_id' => $request->getKey(),
                'document_code' => $document->value,
                'document_revision' => $revision,
                'accepted_at' => now(),
                'ip_address' => $ipAddress,
            ]);

            /*
             * The actor is the officer, not the guest: the guest has no
             * account and the log's `who` column is an account. Whose consent
             * it was is in the payload and in the row itself, so the two
             * questions the log has to answer — «who operated the screen» and
             * «whose data is this» — keep separate answers.
             */
            $this->audit->record(
                action: AuditAction::ConsentGranted,
                actor: $operator,
                subject: $record,
                payload: [
                    'document' => $document->value,
                    'revision' => $revision,
                    'guest_request_id' => $request->getKey(),
                    'taken_at' => 'security post',
                    'superseded_record_id' => $standing?->getKey(),
                ],
                ipAddress: $ipAddress,
            );

            return $record;
        });
    }

    /**
     * The guest's consent that stands on this request, if any.
     */
    public function inForceForGuest(GuestRequest $request): ?ConsentRecord
    {
        return ConsentRecord::query()
            ->forGuestRequest($request)
            ->forDocument(ConsentDocument::GuestPersonalData)
            ->inForce()
            ->latest('accepted_at')
            ->first();
    }

    public function hasInForceForGuest(GuestRequest $request): bool
    {
        return $this->inForceForGuest($request) !== null;
    }

    /**
     * The gate at the security post, and the first line of
     * `CheckpointService::checkIn()`.
     *
     * It is the guest-shaped twin of `requireGranted()` below and refuses on
     * exactly the same ground: for a guest, consent under art. 6 part 1 cl. 1
     * of Federal Law No. 152-FZ is the **only** basis there is (§2.7.1), so an
     * entry written without one would be processing with no ground at all. The
     * refusal names the document and the revision, which is what the post
     * needs in order to put the right text on the screen and take it properly.
     *
     * @throws ConsentRequiredException
     */
    public function requireGrantedForGuest(GuestRequest $request): void
    {
        if ($this->hasInForceForGuest($request)) {
            return;
        }

        throw new ConsentRequiredException(
            document: ConsentDocument::GuestPersonalData,
            revision: $this->texts->currentRevision(ConsentDocument::GuestPersonalData),
        );
    }

    /**
     * The gate. Refuses the operation unless consent to this document stands.
     *
     * **This is the point the security post plugs into.** Increment 1's
     * `CheckpointService::checkIn()` calls it with the guest's account before
     * it writes anything into the visitor register, and FR-35's first criterion
     * — «for the guest, before entry is recorded at the security post» — is
     * then one line at the top of that method rather than a rule spread over a
     * controller. The subject is nullable because at the post there may be no
     * account at all yet, and «nobody has consented» is the same refusal as
     * «this person has not».
     *
     * @throws ConsentRequiredException
     */
    public function requireGranted(?User $subject, ConsentDocument $document): void
    {
        if ($subject !== null && $this->hasInForce($subject, $document)) {
            return;
        }

        throw new ConsentRequiredException(
            document: $document,
            revision: $this->texts->currentRevision($document),
        );
    }
}
