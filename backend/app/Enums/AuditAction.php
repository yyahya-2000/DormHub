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
    case BedCreated = 'bed.created';
    case BedCreationRefused = 'bed.creation_refused';

    // FR-03 and FR-05. Moving in, and moving out.
    case ResidencyAssigned = 'residency.assigned';
    case ResidencyAssignmentRefused = 'residency.assignment_refused';
    case ResidencyTerminated = 'residency.terminated';

    // FR-06. The card carries personal data, so reading it is recorded —
    // §3.9.6 counts that among the events, and FR-33 repeats it.
    case ResidentCardViewed = 'resident.card_viewed';
}
