<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The audited events of this slice. §3.9.6 fixes the minimum event set for the
 * whole system; what appears here is the part the implemented endpoints can
 * actually raise — sign-in and its failures, the lockout, sign-out, and the
 * reading of a card that carries personal data, including the reading of the
 * log itself.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case LoginLocked = 'auth.login_locked';
    case LoginBlocked = 'auth.login_blocked';
    case LogoutSucceeded = 'auth.logout_succeeded';

    // §3.9.6 counts a refusal among the events. It is raised nowhere in a
    // service, because a refused request never reaches one: the record is
    // written from the exception handler, for every 403 alike.
    case AccessDenied = 'access.denied';

    case BuildingViewed = 'building.viewed';
    case BuildingUsersViewed = 'building.users_viewed';
    case AuditLogViewed = 'audit_log.viewed';

    // FR-01. Every change to the register of dormitories, and the refusal to
    // delete one that still holds rooms — a refusal is an event worth keeping,
    // because it records an attempt.
    case BuildingCreated = 'building.created';
    case BuildingUpdated = 'building.updated';
    case BuildingArchived = 'building.archived';
    case BuildingDeleted = 'building.deleted';
    case BuildingDeletionBlocked = 'building.deletion_blocked';

    // FR-02. The register of rooms and beds.
    case BuildingRoomsViewed = 'building.rooms_viewed';
    case RoomCreated = 'room.created';
    case RoomUpdated = 'room.updated';
    case RoomUpdateRefused = 'room.update_refused';
    case BedCreated = 'bed.created';
    case BedCreationRefused = 'bed.creation_refused';

    // FR-03 and FR-05. Moving in, and moving out.
    case ResidencyAssigned = 'residency.assigned';
    case ResidencyAssignmentRefused = 'residency.assignment_refused';
    case ResidencyTerminated = 'residency.terminated';

    // FR-06. The card carries personal data, so reading it is recorded —
    // §3.9.6 counts that among the events, and FR-33 repeats it.
    case ResidentCardViewed = 'resident.card_viewed';

    // FR-41. Who gave whom which role in which building, and who took it back.
    // A change to the role model is a change to what everybody afterwards may
    // do, so it belongs in the log for the same reason §3.9.6 puts refusals
    // there: the record is the only way to reconstruct why an account could
    // act at all.
    case StaffAppointed = 'staff.appointed';
    case StaffRevoked = 'staff.revoked';

    // FR-42. An account handed to an incoming resident, and the moment that
    // resident replaced the one-time credential with a password of their own.
    case ResidentAccountIssued = 'resident.account_issued';
    case PasswordSet = 'auth.password_set';

    // FR-42, the credential rather than the account.
    //
    // Two events and not one, because they commit at different moments and
    // can fail independently. The account is written inside a transaction; the
    // credential is handed to the queue after that transaction has committed,
    // since a worker that reached the row before the commit would find no such
    // user. The first version of this recorded «delivered to: email» inside
    // the transaction, which meant a queue that refused the job left the log
    // asserting a delivery that never happened — the one claim an audit log
    // may not make. This entry is written after the dispatch returns, and
    // never before.
    //
    // `reissue` tells the first code from a replacement, so the log shows how
    // many codes an account was sent and by whom.
    case ResidentCredentialIssued = 'resident.credential_issued';

    // FR-35. Consent to the processing of personal data, given and withdrawn.
    //
    // These two are in the log for a reason none of the others share. Art. 19
    // part 2 cl. 8 of Federal Law No. 152-FZ requires the operator to keep a
    // registration and accounting of the actions performed with personal data,
    // and art. 9 part 3 puts on the operator the burden of proving that
    // consent was given. The consent record itself is the primary evidence;
    // the log entry is what says who gave it, from where and at what moment,
    // and — for the withdrawal — from which point the processing that rested
    // on it had to stop.
    case ConsentGranted = 'consent.granted';
    case ConsentWithdrawn = 'consent.withdrawn';

    // FR-16, FR-17. The request, and every decision taken on it. §3.9.6 puts
    // «every decision on a guest request» in the minimum event set without
    // qualification, so the refusal is recorded as fully as the approval and
    // the automatic rejection of an undecided request is recorded too — that
    // one has no human actor at all, which is exactly why it needs a row.
    case GuestRequestSubmitted = 'guest_request.submitted';
    case GuestRequestApproved = 'guest_request.approved';
    case GuestRequestRejected = 'guest_request.rejected';
    case GuestRequestCancelled = 'guest_request.cancelled';
    case GuestRequestExpired = 'guest_request.expired';
    case GuestRequestDecisionRefused = 'guest_request.decision_refused';

    // FR-18. A lookup at the post reads a guest's name and document; art. 19
    // part 2 cl. 8 of Federal Law No. 152-FZ asks the operator to keep a
    // registration and accounting of the actions performed with personal data,
    // and a lookup is one of them even though it changes nothing.
    case GuestVerifiedAtCheckpoint = 'checkpoint.guest_verified';

    // FR-19. The two facts the paper journal of clause 2.1.2 exists to hold.
    case GuestEntryRecorded = 'checkpoint.entry_recorded';
    case GuestExitRecorded = 'checkpoint.exit_recorded';

    // §3.9.6 names the refusal of entry explicitly, beside the entry itself.
    // A guest turned away at the desk leaves no visit row, so without this the
    // evening would have no record of them at all — which is the one thing the
    // register may not do.
    case GuestEntryRefused = 'checkpoint.entry_refused';

    // FR-19, the exception written down as an event. An entry outside the
    // permitted interval, admitted on the responsible officer's decision with
    // a stated reason (§2.4.2, second scenario).
    case GuestAdmittedOnDecision = 'checkpoint.admitted_on_decision';

    // FR-20. One event per overdue visit, raised by the quarter-hourly sweep
    // and never twice for the same visit.
    case GuestVisitOverdue = 'guest_visit.overdue';

    // FR-21. The register is read, exported, and corrected — and a correction
    // is a new row here rather than an edit there, because the visit itself is
    // immutable (NFR-14).
    case VisitRegisterViewed = 'visit_register.viewed';
    case VisitRegisterExported = 'visit_register.exported';
    case GuestVisitCorrected = 'guest_visit.corrected';

    // NFR-06. The document number is stored encrypted and shown masked; asking
    // for it in full is a separate act and is recorded as one.
    case GuestDocumentNumberViewed = 'guest_request.document_viewed';

    /*
     * FR-37 … FR-40. The maintenance module, and a deliberately short list.
     *
     * **Not every transition is here, and that is the point of there being two
     * logs.** §3.4.1's sixth decision puts every status change into
     * `maintenance_work_logs` with its actor, its moment and its comment, and
     * that table is append-only by the same migration device as this one. A
     * second copy of the same nine rows in `audit_logs` would bury the log in
     * entries that say nothing the primary record does not — which is the
     * argument that keeps `announcement_acks` out of it too.
     *
     * What is here is what the work log cannot answer. A commitment made to a
     * resident and a refusal given to one are decisions §3.9.6 counts; a
     * request that closed because nobody answered, and one a resident disputed
     * by reopening, are the two outcomes the module exists to make visible;
     * and an export is a disclosure of a list, which is the same kind of event
     * as reading the readers of an announcement.
     */
    case MaintenanceRequestAccepted = 'maintenance_request.accepted';
    case MaintenanceRequestRejected = 'maintenance_request.rejected';
    case MaintenanceRequestReopened = 'maintenance_request.reopened';
    case MaintenanceRequestAutoClosed = 'maintenance_request.auto_closed';
    case MaintenanceRequestOverdue = 'maintenance_request.overdue';

    // FR-39, the refusal. A reopening offered after the confirmation window
    // has run out, recorded outside the transaction it refuses — a refusal
    // written inside one is carried away by the rollback.
    case MaintenanceReopeningRefused = 'maintenance_request.reopening_refused';

    // FR-40, third criterion: the queue exported over a period.
    case MaintenanceQueueExported = 'maintenance_queue.exported';

    // FR-09. What was announced, to which dormitory, by whom and until when.
    // A mandatory announcement is the ground a disciplinary conversation later
    // stands on (SN-11), and «the notice was posted on the ninth» has to be
    // answerable from something other than the row a warden could edit.
    case AnnouncementPublished = 'announcement.published';

    // FR-12, and the one act of this module that is recorded here rather than
    // in a table of its own.
    //
    // The acknowledgement itself is **not** in the audit log: the
    // `announcement_acks` row already carries the person, the announcement and
    // the moment, which is the whole of what FR-12 asks to be recorded, and a
    // second copy of it per resident per notice would bury the log in rows
    // that say nothing the primary record does not.
    //
    // Reading the readers is a different act. It discloses a named list of
    // residents who have not complied with an instruction — personal data
    // assembled for a purpose — and §3.9.6 counts such a reading among the
    // events for the same reason it counts `resident.card_viewed`.
    case AnnouncementReadersViewed = 'announcement.readers_viewed';

    /*
     |--------------------------------------------------------------------------
     | The lost-and-found module (increment 4): FR-24, FR-25, FR-26
     |--------------------------------------------------------------------------
     |
     | The module is peer-to-peer and its decisions are taken by residents
     | rather than by staff (§2.5.4), which is exactly why they are recorded.
     | Every other decision in this system is taken by somebody holding a role,
     | and a role is itself a record; here the person who decides who gets an
     | object back is another resident, and the audit log is the only place the
     | sequence survives.
     |
     | The publication carries `custody` and `declared_on` in its payload,
     | because those two say which of §2.5.4's paths the find took and whether
     | a declaration to the authorities was ever recorded against it. Neither
     | can be reconstructed from the row afterwards if somebody edits it.
     */

    // FR-24. A find or a loss entered the feed, with no approval step between
    // (§2.5.4).
    case LostFoundItemPublished = 'lost_found_item.published';

    // FR-26. «That is mine», with the identifying marks the claimant gave.
    case LostFoundClaimFiled = 'lost_found_claim.filed';

    // FR-26. The holder of the object says the marks match.
    case LostFoundClaimAccepted = 'lost_found_claim.accepted';

    // FR-26. The holder says they do not, and the entry goes back into the
    // feed if nothing else is outstanding on it.
    case LostFoundClaimDeclined = 'lost_found_claim.declined';

    // FR-26. The claimant did not accept the refusal and has put it to the
    // warden — the first of §2.5.4's two occasions for a member of staff.
    case LostFoundClaimReferred = 'lost_found_claim.referred';

    // FR-26, first criterion: «a warden's decision on a referred claim». The
    // one decision in the module a member of staff takes over the head of the
    // person holding the object, which is why it is recorded as an act of its
    // own rather than as another acceptance.
    case LostFoundClaimDecidedByStaff = 'lost_found_claim.decided_by_staff';

    // FR-26, first criterion: the object changed hands and the entry left the
    // public list.
    case LostFoundItemResolved = 'lost_found_item.resolved';
}
