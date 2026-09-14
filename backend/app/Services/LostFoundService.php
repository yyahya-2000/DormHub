<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Enums\LostFoundClaimStatus;
use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use App\Enums\Permission;
use App\Enums\RoleCode;
use App\Exceptions\IllegalTransitionException;
use App\Exceptions\NoAcceptedClaimException;
use App\LostFound\LostFoundClaimStateMachine;
use App\LostFound\LostFoundItemStateMachine;
use App\Models\Building;
use App\Models\LostFoundClaim;
use App\Models\LostFoundItem;
use App\Models\User;
use App\Notifications\LostFoundClaimDecided;
use App\Notifications\LostFoundClaimFiled;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The lost-and-found module's one owner of state (FR-24, FR-25, FR-26), built
 * to the specification §3.3.4 sets for a service of this layer.
 *
 * *Purpose*: owns the life of a find — publication, the claims made against
 * it, the holder's answer, the referral of a disagreement and the closure.
 * *Subordinates*: `LostFoundItemStateMachine`, `LostFoundClaimStateMachine`,
 * `AuditRecorder`, `Notifier`. *Dependencies*: the domain layer only; no
 * controller, no HTTP object, no status code (§3.3.1) — the photograph arrives
 * as a path, which is why `App\Files\PhotoStore` exists.
 *
 * **The module is peer-to-peer and this class is where that is enforced rather
 * than merely intended (§2.5.4).** Publication writes a row and notifies
 * nobody's queue: there is no approval step between it and the feed, because
 * a moderation queue would cost the module its only advantage over a message
 * board, which is speed. The decision on a claim goes to whoever is holding
 * the object — the finder on the default path, the staff of the dormitory on
 * the deposited one — and a member of staff enters an ordinary find at no
 * point at all. `decide()` is the single exception FR-26 names and it is
 * reachable only through a claim the claimant referred.
 *
 * **Whose decision it is, asked once.** `LostFoundItem::decisionRestsWith()`
 * answers it, the policy asks the model, and this class asks the model too —
 * so the person the notification goes to and the person the policy admits are
 * the same person by construction.
 *
 * **The three ordering rules of §3.3.4 are kept here as they are in
 * `MaintenanceService`.** The row is taken under `SELECT … FOR UPDATE`, so two
 * claimants pressing at once do not both move it. The audit record is written
 * **inside** the transaction of the change it describes. The notification is
 * dispatched **after** the commit, because a message queued from inside a
 * transaction that then rolls back is a message about something that did not
 * happen. And a refusal is recorded **outside** the transaction it refuses
 * (§3.9.6), for the reason a refusal written inside one is carried off by the
 * rollback.
 */
final readonly class LostFoundService
{
    public function __construct(
        private LostFoundItemStateMachine $items,
        private LostFoundClaimStateMachine $claims,
        private AuditRecorder $audit,
        private Notifier $notifier,
    ) {}

    /**
     * FR-24: the entry goes straight into the feed.
     *
     * **There is no approval step and no queue, and the absence is the
     * requirement** (FR-24, fourth criterion; §2.5.4). The row is created in
     * `published` and the method returns; nothing is dispatched to a member of
     * staff for review, and the status vocabulary has no state for a reviewer
     * to move it out of.
     *
     * **`custody` records which of the two paths this find took and decides
     * nothing else** (§2.7.5). `Administration` is an object handed in at the
     * post or deposited with the administration for safekeeping — the case
     * Civil Code art. 227 cl. 1 para. 2 addresses, in which the person
     * representing the owner of the premises acquires the rights and bears the
     * duties of the finder. `Finder`, the default, is an object that never
     * left the resident who found it, so no hand-over within the meaning of
     * that paragraph takes place. The form decides which, the policy decides
     * who may say `Administration`, and this method writes it down.
     *
     * **`declaredOn` is a fact the publisher states and never one this method
     * infers.** It is the day the find was declared to the police or to a
     * local self-government body (art. 227 cl. 2), NULL while nothing has been
     * declared. The six-month period of art. 228 cl. 1 runs from it; it never
     * runs from `happenedOn` and never from the registration. FR-27's
     * automated control of that period is outside the MVP and nothing here
     * counts days — the column exists because it cannot be retrofitted later
     * (§2.5.4).
     *
     * @param  string|null  $photoPath  Already in the object store; see `PhotoStore`.
     */
    public function publish(
        User $reporter,
        Building $building,
        string $title,
        string $place,
        CarbonInterface $happenedOn,
        LostFoundItemKind $kind = LostFoundItemKind::Found,
        LostFoundCustody $custody = LostFoundCustody::Finder,
        ?string $description = null,
        ?CarbonInterface $declaredOn = null,
        ?string $photoPath = null,
        ?string $ipAddress = null,
    ): LostFoundItem {
        return DB::transaction(function () use (
            $reporter, $building, $title, $place, $happenedOn, $kind,
            $custody, $description, $declaredOn, $photoPath, $ipAddress
        ): LostFoundItem {
            $item = LostFoundItem::query()->create([
                'building_id' => $building->getKey(),
                'reporter_id' => $reporter->getKey(),
                'kind' => $kind,
                'custody' => $custody,
                'title' => $title,
                'description' => $description,
                'place' => $place,
                'happened_on' => $happenedOn->toDateString(),
                'declared_on' => $declaredOn?->toDateString(),
                'photo_path' => $photoPath,
                'status' => LostFoundItemStatus::Published,
            ]);

            $this->audit->record(
                action: AuditAction::LostFoundItemPublished,
                actor: $reporter,
                subject: $item,
                payload: [
                    'building_id' => $building->getKey(),
                    'kind' => $kind->value,
                    // Which of §2.5.4's two paths, and whether a declaration
                    // was recorded against it. Neither is reconstructible from
                    // the row afterwards if somebody edits it.
                    'custody' => $custody->value,
                    'declared_on' => $declaredOn?->toDateString(),
                    'has_photograph' => $photoPath !== null,
                ],
                ipAddress: $ipAddress,
            );

            return $item;
        });
    }

    /**
     * FR-26: «that is mine, and here is how I know».
     *
     * The entry moves `Published → Claimed` on the first claim and stays where
     * it is on the second, which is §3.5.5's note — «several claims may
     * coexist» — expressed as the one place that writes the column. A claim
     * offered against a closed entry runs into `assert(Resolved, Claimed)` and
     * comes back as a 409 naming both ends, which is the only useful thing to
     * tell somebody whose page was open while the object went home.
     *
     * Who may claim, and that it is not one's own entry, is settled before
     * this method is reached: `StoreLostFoundClaimRequest` refuses a claim on
     * one's own entry as a 422 naming the field, and
     * `LostFoundItemPolicy::claim` decides whether the account belongs to the
     * dormitory at all.
     *
     * @throws IllegalTransitionException the entry is closed (409)
     */
    public function claim(
        User $claimant,
        LostFoundItem $item,
        string $marks,
        ?string $ipAddress = null,
    ): LostFoundClaim {
        [$claim, $entry] = DB::transaction(function () use ($claimant, $item, $marks, $ipAddress): array {
            $locked = LostFoundItem::query()->lockForUpdate()->findOrFail($item->getKey());

            $from = $locked->status ?? LostFoundItemStatus::Published;

            if ($from !== LostFoundItemStatus::Claimed) {
                $this->items->assert($from, LostFoundItemStatus::Claimed);

                $locked->status = LostFoundItemStatus::Claimed;
                $locked->save();
            }

            $claim = LostFoundClaim::query()->create([
                'lost_found_item_id' => $locked->getKey(),
                'claimant_id' => $claimant->getKey(),
                'message' => $marks,
                'status' => LostFoundClaimStatus::New,
            ]);

            $this->audit->record(
                action: AuditAction::LostFoundClaimFiled,
                actor: $claimant,
                subject: $claim,
                payload: [
                    'lost_found_item_id' => $locked->getKey(),
                    'building_id' => $locked->building_id,
                    'from_status' => $from->value,
                ],
                ipAddress: $ipAddress,
            );

            return [$claim, $locked];
        });

        /*
         * §2.4.4 and §3.5.3: the person who has to answer is told. On the
         * default path that is one resident; on the deposited path it is the
         * circle of staff holding the object, because the officer who took it
         * in on Friday is not on shift on Monday.
         */
        $this->notifier->sendOnce(
            $this->holdersOf($entry),
            new LostFoundClaimFiled(
                itemId: (int) $entry->getKey(),
                claimId: (int) $claim->getKey(),
                itemTitle: (string) $entry->title,
                marks: $marks,
            ),
        );

        return $claim;
    }

    /**
     * FR-26, §2.4.4, first scenario: «the finder accepts the claim».
     *
     * «Then the claim moves to accepted and the claimant is notified with the
     * handover point» — so the place is a required argument here and a
     * required field in the form request, for the reason
     * `MaintenanceService::accept()` requires its planned date: a signature
     * that admits the acceptance without a place is a signature that will one
     * day be called that way. The CHECK constraint states it a third time for
     * the sake of everything that is neither.
     *
     * **The entry is not closed by this.** It stays `claimed` until the object
     * actually changes hands and somebody says so, which is the second half of
     * the same scenario and is `resolve()`. An acceptance that closed the
     * entry would be the system recording a handover that has not happened.
     *
     * @throws IllegalTransitionException the claim has already been answered (409)
     */
    public function accept(
        User $actor,
        LostFoundClaim $claim,
        string $handoverPoint,
        ?string $note = null,
        ?string $ipAddress = null,
    ): LostFoundClaim {
        return $this->answer(
            actor: $actor,
            claim: $claim,
            to: LostFoundClaimStatus::Accepted,
            handoverPoint: $handoverPoint,
            note: $note,
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-26, §2.4.4, second scenario: «the finder declines the claim».
     *
     * «Then the claim moves to declined and the find returns to the published
     * list» — and §3.5.5's note says when: «the entry returns to Published if
     * none of them is confirmed». So the entry goes back only once nothing is
     * outstanding on it, which is the condition `restoreTheEntryIfNothingIsOutstanding()`
     * asks. With two claims on the table the first refusal changes nothing
     * about the entry, and the second puts it back.
     *
     * «And the claimant is offered the option of referring the decision to the
     * warden» — the offer travels in the notification and on the claim itself,
     * so a client draws the button from a flag rather than inferring one from
     * a status.
     *
     * @throws IllegalTransitionException
     */
    public function decline(
        User $actor,
        LostFoundClaim $claim,
        ?string $reason = null,
        ?string $ipAddress = null,
    ): LostFoundClaim {
        return $this->answer(
            actor: $actor,
            claim: $claim,
            to: LostFoundClaimStatus::Declined,
            note: $reason,
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-26: «a claim the two sides cannot settle is referred to the warden,
     * who decides».
     *
     * The claimant's own act, and the first of §2.5.4's two occasions for a
     * member of staff. It moves the claim and never the entry: the entry is
     * already out of the feed while the disagreement is open, because a
     * referred claim is outstanding — offering the object to somebody else
     * while the warden is still looking at it is precisely what the status is
     * for.
     *
     * **A claim may be referred once.** A second attempt raises the same
     * exception with the same message — «a claim that is “Declined” cannot
     * become “Referred to the warden”» — which is exactly what has happened to
     * a claim the warden has already refused. The guard is here and not in the
     * transition table for the reason `ConfirmationWindowClosedException` is
     * not in one: a table with a condition in it is a table that has stopped
     * being a table.
     *
     * @throws IllegalTransitionException the claim is not one that can go to the warden (409)
     */
    public function refer(
        User $claimant,
        LostFoundClaim $claim,
        ?string $note = null,
        ?string $ipAddress = null,
    ): LostFoundClaim {
        $from = $claim->status ?? LostFoundClaimStatus::New;

        if (! $claim->mayBeReferred()) {
            $refusal = new IllegalTransitionException($from, LostFoundClaimStatus::Referred);

            $this->audit->record(
                action: AuditAction::LostFoundClaimReferred,
                actor: $claimant,
                subject: $claim,
                payload: ['reason' => $refusal->getMessage()] + $refusal->context(),
                result: AuditResult::Denied,
                ipAddress: $ipAddress,
            );

            throw $refusal;
        }

        [$referred, $entry] = DB::transaction(function () use ($claimant, $claim, $note, $ipAddress): array {
            $locked = LostFoundClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            $entry = LostFoundItem::query()->findOrFail($locked->lost_found_item_id);

            $this->claims->assert(
                $locked->status ?? LostFoundClaimStatus::New,
                LostFoundClaimStatus::Referred,
            );

            $locked->status = LostFoundClaimStatus::Referred;
            $locked->referred_at = now();

            if ($note !== null && trim($note) !== '') {
                $locked->decision_note = trim($note);
            }

            $locked->save();

            $this->audit->record(
                action: AuditAction::LostFoundClaimReferred,
                actor: $claimant,
                subject: $locked,
                payload: [
                    'lost_found_item_id' => $entry->getKey(),
                    'building_id' => $entry->building_id,
                    'note' => $note,
                ],
                ipAddress: $ipAddress,
            );

            return [$locked, $entry];
        });

        $this->notifier->sendOnce(
            $this->disputeStaffOf($entry),
            new LostFoundClaimFiled(
                itemId: (int) $entry->getKey(),
                claimId: (int) $referred->getKey(),
                itemTitle: (string) $entry->title,
                marks: (string) $referred->message,
                referred: true,
            ),
        );

        return $referred;
    }

    /**
     * FR-26, first criterion: «a warden's decision on a referred claim».
     *
     * The second of §2.5.4's two occasions for a member of staff, and the only
     * decision in the module taken over the head of the person holding the
     * object. It is reachable only through a referral — `decide()` on a claim
     * nobody referred raises the transition exception — which is what keeps
     * the exception an exception.
     *
     * **Upholding the claim does not close the entry either.** It makes the
     * claim `accepted`, which is the ground FR-26's first criterion admits for
     * a closure, and the closure itself is still the act of the person handing
     * the object over. A warden who could close an entry from a screen would
     * be recording a handover nobody witnessed, and the record of a handover
     * is the only thing the module produces.
     *
     * @throws IllegalTransitionException the claim was never referred (409)
     */
    public function decide(
        User $staff,
        LostFoundClaim $claim,
        bool $upheld,
        ?string $handoverPoint = null,
        ?string $note = null,
        ?string $ipAddress = null,
    ): LostFoundClaim {
        $from = $claim->status ?? LostFoundClaimStatus::New;

        /*
         * **Only a referred claim.** The transition table cannot say this on
         * its own: `new → accepted` is a legal move, correctly, because it is
         * the move the person holding the object makes every day. What is
         * missing is the referral that put this decision in a member of
         * staff's hands at all, and the exception names it — «a claim that is
         * “Awaiting the holder” cannot become “Referred to the warden”», which
         * is the step that never happened.
         *
         * Recorded outside the transaction, for §3.9.6's reason: a refusal
         * written inside the transaction it refuses is carried off by the
         * rollback.
         */
        if ($from !== LostFoundClaimStatus::Referred) {
            $refusal = new IllegalTransitionException($from, LostFoundClaimStatus::Referred);

            $this->audit->record(
                action: AuditAction::LostFoundClaimDecidedByStaff,
                actor: $staff,
                subject: $claim,
                payload: ['reason' => $refusal->getMessage()] + $refusal->context(),
                result: AuditResult::Denied,
                ipAddress: $ipAddress,
            );

            throw $refusal;
        }

        return $this->answer(
            actor: $staff,
            claim: $claim,
            to: $upheld ? LostFoundClaimStatus::Accepted : LostFoundClaimStatus::Declined,
            handoverPoint: $upheld ? $handoverPoint : null,
            note: $note,
            byStaff: true,
            ipAddress: $ipAddress,
        );
    }

    /**
     * FR-26, §2.4.4: «when the finder then marks the item returned, the status
     * becomes resolved with the time and the record no longer appears in the
     * public list of available finds».
     *
     * **The «only» of FR-26's first criterion lives here.** A find closes as
     * returned on a claim the finder accepted or on a warden's decision on a
     * referred one, and both of those arrive at one place — a claim in status
     * `accepted`. So the check is one question asked of the entry, and an
     * entry nobody has claimed, or one whose claims are all outstanding,
     * cannot be closed at all.
     *
     * The refusal is recorded outside the transaction, for §3.9.6's reason.
     *
     * @throws NoAcceptedClaimException nothing has been accepted on the entry (409)
     * @throws IllegalTransitionException the entry is not in a state that closes (409)
     */
    public function resolve(
        User $actor,
        LostFoundItem $item,
        ?string $ipAddress = null,
    ): LostFoundItem {
        if (! $item->hasAnAcceptedClaim()) {
            $refusal = new NoAcceptedClaimException(
                itemId: (int) $item->getKey(),
                outstandingClaims: $item->claims()->outstanding()->count(),
            );

            $this->audit->record(
                action: AuditAction::LostFoundItemResolved,
                actor: $actor,
                subject: $item,
                payload: ['reason' => $refusal->getMessage()] + $refusal->context(),
                result: AuditResult::Denied,
                ipAddress: $ipAddress,
            );

            throw $refusal;
        }

        return DB::transaction(function () use ($actor, $item, $ipAddress): LostFoundItem {
            $locked = LostFoundItem::query()->lockForUpdate()->findOrFail($item->getKey());

            $from = $locked->status ?? LostFoundItemStatus::Published;

            $this->items->assert($from, LostFoundItemStatus::Resolved);

            $locked->status = LostFoundItemStatus::Resolved;
            $locked->resolved_at = now();
            $locked->save();

            $this->audit->record(
                action: AuditAction::LostFoundItemResolved,
                actor: $actor,
                subject: $locked,
                payload: [
                    'building_id' => $locked->building_id,
                    'from_status' => $from->value,
                    'custody' => $locked->custody?->value,
                    'resolved_at' => $locked->resolved_at?->toIso8601String(),
                ],
                ipAddress: $ipAddress,
            );

            return $locked;
        });
    }

    /**
     * Who is holding the object, and therefore who answers a claim on it
     * (§2.5.4).
     *
     * One resident on the default path. On the deposited path, everybody whose
     * role carries `HoldLostFoundItems` in that dormitory — named as a
     * capability and not as three role codes, for §3.3.3's reason.
     *
     * @return Collection<int, User>
     */
    public function holdersOf(LostFoundItem $item): Collection
    {
        $holder = $item->decisionRestsWith();

        if ($holder !== null) {
            $person = User::query()->find($holder);

            return $person === null ? new Collection : new Collection([$person]);
        }

        return $this->staffOf($item, Permission::HoldLostFoundItems);
    }

    /**
     * The warden and the manager of the dormitory: everybody whose role
     * carries the capability FR-26 gives the disputed claim to.
     *
     * @return Collection<int, User>
     */
    public function disputeStaffOf(LostFoundItem $item): Collection
    {
        return $this->staffOf($item, Permission::DecideLostFoundDisputes);
    }

    /**
     * The one method that writes `lost_found_claims.status`.
     *
     * Assert, write, audit — in that order and in one transaction — then
     * notify once it has committed. Three public methods reach it, and that
     * they do is the design rather than an economy: FR-26's first criterion
     * distinguishes a claim the finder accepted from a warden's decision on a
     * referred one, and the two differ in who decided and in what follows,
     * never in how the row is written.
     *
     * @throws IllegalTransitionException
     */
    private function answer(
        User $actor,
        LostFoundClaim $claim,
        LostFoundClaimStatus $to,
        ?string $handoverPoint = null,
        ?string $note = null,
        bool $byStaff = false,
        ?string $ipAddress = null,
    ): LostFoundClaim {
        [$answered, $entry, $from] = DB::transaction(function () use (
            $actor, $claim, $to, $handoverPoint, $note, $byStaff, $ipAddress
        ): array {
            // §3.3.4: the entry first and the claim under it, always in that
            // order. Two claimants pressing at once reach this line together
            // and the second waits, and taking the two rows in one order
            // everywhere is what keeps two concurrent answers from deadlocking
            // against each other.
            $entry = LostFoundItem::query()->lockForUpdate()->findOrFail($claim->lost_found_item_id);
            $locked = LostFoundClaim::query()->lockForUpdate()->findOrFail($claim->getKey());

            $from = $locked->status ?? LostFoundClaimStatus::New;

            $this->claims->assert($from, $to);

            $locked->status = $to;
            $locked->decided_by = $actor->getKey();
            $locked->decided_at = now();

            if ($handoverPoint !== null && trim($handoverPoint) !== '') {
                $locked->handover_point = trim($handoverPoint);
            }

            if ($note !== null && trim($note) !== '') {
                $locked->decision_note = trim($note);
            }

            $locked->save();

            if ($to === LostFoundClaimStatus::Declined) {
                $this->restoreTheEntryIfNothingIsOutstanding($entry);
            }

            $this->audit->record(
                action: $this->actionFor($to, $byStaff),
                actor: $actor,
                subject: $locked,
                payload: [
                    'lost_found_item_id' => $entry->getKey(),
                    'building_id' => $entry->building_id,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'decided_by_staff' => $byStaff,
                    'handover_point' => $locked->handover_point,
                    'note' => $note,
                ],
                ipAddress: $ipAddress,
            );

            return [$locked, $entry, $from];
        });

        $this->notifyTheClaimant($answered, $entry, $byStaff);

        // A claim the warden decided concerns the person holding the object
        // too: the disagreement was about their refusal, and they are the one
        // who will hand the object over or keep it. The referral took the
        // decision out of their hands and this is how they learn what came of
        // it.
        if ($byStaff && $from === LostFoundClaimStatus::Referred) {
            $this->notifier->sendOnce(
                $this->holdersOf($entry),
                $this->decisionNotice($answered, $entry, referralOffered: false, byStaff: true),
            );
        }

        return $answered;
    }

    /**
     * FR-26, second criterion: «a declined claim returns the find to the
     * published list».
     *
     * Asked after every refusal and acted on only when nothing is left
     * outstanding, which is §3.5.5's note. Inside the transaction of the
     * refusal, so an entry never sits in `claimed` with every claim settled.
     */
    private function restoreTheEntryIfNothingIsOutstanding(LostFoundItem $entry): void
    {
        if ($entry->status !== LostFoundItemStatus::Claimed) {
            return;
        }

        if ($entry->hasAnOutstandingClaim() || $entry->hasAnAcceptedClaim()) {
            return;
        }

        $this->items->assert(LostFoundItemStatus::Claimed, LostFoundItemStatus::Published);

        $entry->status = LostFoundItemStatus::Published;
        $entry->save();
    }

    private function notifyTheClaimant(LostFoundClaim $claim, LostFoundItem $entry, bool $byStaff): void
    {
        $claimant = $claim->relationLoaded('claimant')
            ? $claim->claimant
            : $claim->claimant()->first();

        if ($claimant === null) {
            return;
        }

        $claimant->notify($this->decisionNotice(
            claim: $claim,
            entry: $entry,
            // §2.4.4: the offer of a referral goes with a refusal the holder
            // made, and not with one the warden made — a claim the warden has
            // already refused has nowhere further to go inside the module, and
            // a button that led nowhere would be the interface promising
            // otherwise.
            referralOffered: $claim->mayBeReferred() && ! $byStaff,
            byStaff: $byStaff,
        ));
    }

    private function decisionNotice(
        LostFoundClaim $claim,
        LostFoundItem $entry,
        bool $referralOffered,
        bool $byStaff,
    ): LostFoundClaimDecided {
        return new LostFoundClaimDecided(
            itemId: (int) $entry->getKey(),
            claimId: (int) $claim->getKey(),
            itemTitle: (string) $entry->title,
            status: $claim->status ?? LostFoundClaimStatus::New,
            handoverPoint: $claim->handover_point,
            note: $claim->decision_note,
            referralOffered: $referralOffered,
            decidedByStaff: $byStaff,
        );
    }

    private function actionFor(LostFoundClaimStatus $to, bool $byStaff): AuditAction
    {
        if ($byStaff) {
            return AuditAction::LostFoundClaimDecidedByStaff;
        }

        return $to === LostFoundClaimStatus::Accepted
            ? AuditAction::LostFoundClaimAccepted
            : AuditAction::LostFoundClaimDeclined;
    }

    /**
     * Everybody holding `$permission` in the dormitory the entry belongs to.
     *
     * @return Collection<int, User>
     */
    private function staffOf(LostFoundItem $item, Permission $permission): Collection
    {
        $codes = array_values(array_map(
            static fn (RoleCode $code): string => $code->value,
            array_filter(
                RoleCode::cases(),
                static fn (RoleCode $code): bool => $code->grants($permission),
            ),
        ));

        if ($codes === []) {
            return new Collection;
        }

        return User::query()
            ->whereHas('roleGrants', fn (Builder $grant) => $grant
                ->where('building_id', $item->building_id)
                ->whereHas('role', fn (Builder $role) => $role->whereIn('code', $codes)))
            ->get();
    }
}
