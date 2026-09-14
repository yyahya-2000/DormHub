<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of event a person can be told about (FR-34).
 *
 * FR-34 names four occasions — a decision on a request, an overdue visit, a
 * change in the state of a maintenance request, a document awaiting signature
 * — and three of the four belong to increments that are not written yet. That
 * is the reason this enumeration exists at all rather than the dispatch simply
 * naming notification classes: **the category is the unit the delivery rule is
 * written against**, so a later increment adds a case here and a notification
 * class that returns it, and no line of the dispatch changes.
 *
 * **Mandatory and optional, and why the line falls where it does.** FR-34's
 * second criterion lets a person switch off the categories that are not
 * mandatory, which only means anything if the two sets are separated by a
 * stated principle rather than by taste. The principle here is the legal
 * ground the message rests on, the same one §2.7.1 applies to the data itself.
 *
 * A message the service cannot be delivered without is mandatory: it rests on
 * the accommodation contract or on the rules of internal order, not on
 * consent, and switching it off would not be a preference but a refusal of the
 * service. The one-time credential is of that kind — without it the account
 * cannot be used at all. So is the overdue visit: clause 2.2 of the rules puts
 * the guest in the dormitory only while the resident who invited them is
 * present, and the resident is the person answerable for the departure. So is
 * a document awaiting signature, which is a legal act with a deadline.
 *
 * A message that only saves a person from opening the application is optional:
 * the decision on their own request and the movement of their own maintenance
 * request are both visible on screen the moment they ask. Those rest on
 * consent (§2.7.1), and consent may be withdrawn — which is why
 * `App\Models\User::notify()` silences exactly this set when it is.
 */
enum NotificationCategory: string
{
    /** FR-17. The duty officer has approved or refused a guest request. */
    case RequestDecision = 'request_decision';

    /** FR-20. A visit is past the hour by which the guest was to have left. */
    case VisitOverdue = 'visit_overdue';

    /** FR-38. A maintenance request has moved to another state. */
    case MaintenanceStatus = 'maintenance_status';

    /** FR-35, FR-14. A document is waiting for the person to sign it. */
    case DocumentSignature = 'document_signature';

    /**
     * FR-09. A routine announcement has been published to this dormitory.
     *
     * Optional, by the principle stated above: the feed of FR-11 is on screen
     * and the message only saves the resident from opening it. A film evening
     * rests on nothing but convenience, and a resident who would rather read
     * the feed themselves may say so.
     */
    case Announcement = 'announcement';

    /**
     * FR-09 and FR-12. An announcement the resident is required to acknowledge.
     *
     * **Two categories for one kind of object, and this is where the line of
     * the class docblock actually falls.** The category is the unit a delivery
     * rule is written against, so «sometimes mandatory» is not a thing one
     * category can be; the choice was either to make every announcement
     * optional or to split the enumeration along the column that already
     * exists, `ANNOUNCEMENT.is_mandatory`. It is split, and the ground is the
     * same one the whole enumeration is drawn on.
     *
     * A mandatory announcement rests on the rules of internal order and not on
     * consent. Clause 4.2.7 of the HSE rules obliges the resident to comply
     * with the lawful instructions of the administration, and a fire drill, an
     * evacuation, a water shutoff or a change to the regime is such an
     * instruction; the dormitory does not ask permission to issue one. FR-12
     * then makes the delivery evidential — SN-11 wants «I was not told» to be
     * a checkable statement — and a switch that could silence the message
     * would hollow out the record it is supposed to support: the warden would
     * hold a list of people who had not acknowledged a notice they were never
     * sent.
     *
     * The optional case above keeps the resident's control over everything
     * that is not an instruction, which is most of the feed.
     */
    case MandatoryAnnouncement = 'mandatory_announcement';

    /**
     * FR-26. Something has happened to a claim on a find: one has arrived, one
     * has been accepted with a handover point, one has been declined, one has
     * been referred to the warden, or the warden has decided it.
     *
     * **One category for the whole exchange, and that is the decision here.**
     * The obvious split — «a claim arrived» to the holder, «your claim was
     * answered» to the claimant — would produce two switches held by the same
     * people for the same conversation, and a resident who silenced one half
     * would be left holding an object nobody would ever come for, or waiting
     * at a handover point nobody named. The two messages are two ends of one
     * exchange and they go on or off together.
     *
     * Optional, by the principle stated above: every one of these movements is
     * on the person's own screen the moment they open the entry, and nothing
     * in the rules of internal order obliges anybody to answer a claim at all
     * — §2.5.4's module rests on the residents' willingness rather than on a
     * duty. The message only saves them from looking.
     *
     * **This is the one category whose messages name another resident**, and
     * the composition is narrow on purpose (§3.5.3): the claimant learns where
     * to collect the object, and the holder learns that somebody has claimed
     * it. No telephone number, no address of any kind and no e-mail travels in
     * either direction — the exchange runs through the system, which is the
     * whole reason the find card carries no contacts (FR-25).
     */
    case LostFoundClaim = 'lost_found_claim';

    /**
     * Whether the category may not be switched off.
     *
     * @see self for the ground the line is drawn on.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::VisitOverdue, self::DocumentSignature,
            self::MandatoryAnnouncement => true,
            self::RequestDecision, self::MaintenanceStatus, self::Announcement,
            self::LostFoundClaim => false,
        };
    }

    /**
     * The mirror of the question above, asked the way §2.7.1 asks it: an
     * optional message is one whose only ground is the person's consent, so
     * withdrawing that consent has to stop it.
     */
    public function restsOnConsent(): bool
    {
        return ! $this->isMandatory();
    }

    public function label(): string
    {
        return match ($this) {
            self::RequestDecision => 'Decision on a guest request',
            self::VisitOverdue => 'Guest overdue at the checkpoint',
            self::MaintenanceStatus => 'Maintenance request status',
            self::DocumentSignature => 'Document awaiting signature',
            self::Announcement => 'Announcements of the dormitory',
            self::MandatoryAnnouncement => 'Announcements requiring acknowledgement',
            self::LostFoundClaim => 'Claims on a lost or found item',
        };
    }

    /**
     * What a person reads next to the switch, so that the choice is informed.
     */
    public function description(): string
    {
        return match ($this) {
            self::RequestDecision => 'Your guest request has been approved or refused.',
            self::VisitOverdue => 'A guest you invited has not left by the hour the rules of internal order set.',
            self::MaintenanceStatus => 'A maintenance request you filed has moved to another state.',
            self::DocumentSignature => 'A document is waiting for your signature.',
            self::Announcement => 'The warden has published an announcement for your dormitory.',
            self::MandatoryAnnouncement => 'An announcement you are required to acknowledge, such as a change to the regime or a planned shutoff.',
            self::LostFoundClaim => 'Somebody has claimed something you found, or a claim of yours has been answered.',
        };
    }

    /**
     * @return list<self>
     */
    public static function optional(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $category): bool => ! $category->isMandatory(),
        ));
    }
}
