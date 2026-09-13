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
    /**
     * FR-42. The one-time credential of a newly issued account.
     *
     * Not among FR-34's four occasions, and deliberately included all the same:
     * it is the notification the system already sends, and leaving it outside
     * the scheme would mean two dispatch paths where the requirement asks for
     * one.
     */
    case AccountIssued = 'account_issued';

    /** FR-17. The duty officer has approved or refused a guest request. */
    case RequestDecision = 'request_decision';

    /** FR-20. A visit is past the hour by which the guest was to have left. */
    case VisitOverdue = 'visit_overdue';

    /** FR-38. A maintenance request has moved to another state. */
    case MaintenanceStatus = 'maintenance_status';

    /** FR-35, FR-14. A document is waiting for the person to sign it. */
    case DocumentSignature = 'document_signature';

    /**
     * Whether the category may not be switched off.
     *
     * @see self for the ground the line is drawn on.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::AccountIssued, self::VisitOverdue, self::DocumentSignature => true,
            self::RequestDecision, self::MaintenanceStatus => false,
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
            self::AccountIssued => 'Account and credentials',
            self::RequestDecision => 'Decision on a guest request',
            self::VisitOverdue => 'Guest overdue at the checkpoint',
            self::MaintenanceStatus => 'Maintenance request status',
            self::DocumentSignature => 'Document awaiting signature',
        };
    }

    /**
     * What a person reads next to the switch, so that the choice is informed.
     */
    public function description(): string
    {
        return match ($this) {
            self::AccountIssued => 'The one-time code for a new account, and changes to the way you sign in.',
            self::RequestDecision => 'Your guest request has been approved or refused.',
            self::VisitOverdue => 'A guest you invited has not left by the hour the rules of internal order set.',
            self::MaintenanceStatus => 'A maintenance request you filed has moved to another state.',
            self::DocumentSignature => 'A document is waiting for your signature.',
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
