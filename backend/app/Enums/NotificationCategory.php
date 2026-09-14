<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of event a person can be told about (FR-34).
 *
 * FR-34 names four occasions — a decision on a request, an overdue visit, a
 * change in the state of a maintenance request, a document awaiting signature
 * — of which the last leaves the MVP with FR-35, and the module has since
 * added two others. The enumeration exists so that neither the dispatch nor
 * the personal account has to name a notification class: a message says which
 * category it is, the stored row carries the word, and the client draws the
 * icon and the caption from it.
 *
 * **Nothing here decides whether a message is delivered.** The per-category
 * switch of FR-34's second criterion is withdrawn from the MVP, and so is the
 * consent of FR-35 that used to silence part of this list. A category is a
 * label; every message reaches the person it is addressed to.
 */
enum NotificationCategory: string
{
    /** FR-17. The duty officer has approved or refused a guest request. */
    case RequestDecision = 'request_decision';

    /** FR-20. A visit is past the hour by which the guest was to have left. */
    case VisitOverdue = 'visit_overdue';

    /** FR-38. A maintenance request has moved to another state. */
    case MaintenanceStatus = 'maintenance_status';

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
}
