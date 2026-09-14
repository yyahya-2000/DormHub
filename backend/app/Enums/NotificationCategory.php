<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of event a person can be told about (FR-34).
 *
 * FR-34 names four occasions — a decision on a request, an overdue visit, a
 * change in the state of a maintenance request, a document awaiting signature
 * — and the module has since added two more. The enumeration exists so that
 * neither the dispatch nor the personal account has to name a notification
 * class: a message says which category it is, the stored row carries the word,
 * and the client draws the icon and the caption from it.
 *
 * **There is no longer a switch, and therefore no mandatory half.** The
 * per-category settings screen the second criterion asked for is withdrawn
 * from the MVP: the categories used to be split into those a person could turn
 * off and those they could not, and once nothing can be turned off the two
 * halves say the same thing. Every category is delivered.
 *
 * **What survives the switch is the legal ground**, and it is a different
 * question with a different consequence. Some of these messages are owed under
 * the accommodation contract or the rules of internal order — the overdue
 * visit of clause 2.2, a document with a signing deadline — and the dormitory
 * sends them whatever anybody consents to. The rest are conveniences whose
 * only ground is the resident's consent (§2.7.1), and consent may be
 * withdrawn: `restsOnConsent()` marks those, and `App\Models\User::notify()`
 * stops them from the moment the consent of FR-35 is gone. That is this
 * application's answer to the question FR-35's fourth criterion leaves open,
 * «and then what», and it is the only gate on the dispatch that remains.
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
     * FR-09. Every announcement, and there is only one category for them now.
     *
     * The enumeration used to carry a second, unsilenceable announcement
     * category for the notices FR-12 required a resident to acknowledge. The
     * acknowledgement has been withdrawn from the MVP, so there is nothing
     * left for that category to classify and it is gone.
     */
    case Announcement = 'announcement';

    /**
     * FR-26. Something has happened to a claim on a find: one has arrived, one
     * has been accepted with a handover point, one has been declined, one has
     * been referred to the warden, or the warden has decided it.
     *
     * **One category for the whole exchange, and that is the decision here.**
     * The obvious split — «a claim arrived» to the holder, «your claim was
     * answered» to the claimant — would produce two labels on one conversation
     * for the same people, and the two messages are two ends of one exchange.
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
     * Whether the only ground for sending this is the person's consent, so
     * that withdrawing it has to stop the message.
     *
     * The line is the one §2.7.1 draws over the data itself. A message the
     * service cannot be delivered without does not rest on consent: the
     * overdue visit is clause 2.2 of the rules of internal order making the
     * inviting resident answerable for the departure, and a document awaiting
     * signature is a legal act with a deadline. Both are owed under the
     * accommodation contract or the rules, and silencing them would not be a
     * choice about convenience but a refusal of the service.
     *
     * The rest only save a person from opening the application — the decision
     * on their own request, the movement of their own maintenance request, the
     * feed of FR-11, a claim on a find they hold. Those rest on consent, and
     * consent may be withdrawn.
     */
    public function restsOnConsent(): bool
    {
        return match ($this) {
            self::VisitOverdue, self::DocumentSignature => false,
            self::RequestDecision, self::MaintenanceStatus, self::Announcement,
            self::LostFoundClaim => true,
        };
    }
}
