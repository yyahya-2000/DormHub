<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BedController;
use App\Http\Controllers\Api\V1\BuildingController;
use App\Http\Controllers\Api\V1\CheckpointController;
use App\Http\Controllers\Api\V1\GuestRequestController;
use App\Http\Controllers\Api\V1\LostFoundClaimController;
use App\Http\Controllers\Api\V1\LostFoundController;
use App\Http\Controllers\Api\V1\MaintenanceQueueController;
use App\Http\Controllers\Api\V1\MaintenanceRequestController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ResidencyController;
use App\Http\Controllers\Api\V1\ResidentAccountController;
use App\Http\Controllers\Api\V1\ResidentCardController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\VisitRegisterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioning sits in the path, as §3.3.6 fixes it; the `api/v1` prefix is
| applied in bootstrap/app.php, so the paths below are relative to it.
|
| This file covers three slices. The first is the role model (FR-07),
| authentication and sessions (FR-08) and the audit log the second of those
| writes to (FR-33). The second is the housing register: dormitories (FR-01),
| rooms and places (FR-02), moving in (FR-03), moving out (FR-05) and the
| resident card (FR-06). The third follows revision 2 of the role model: the
| warden appoints the staff of his own building (FR-41), and he or the manager
| beneath him issues an account to an incoming resident (FR-42). The fourth is
| the personal account itself: the notifications of FR-34.
|
| The fifth is the guest module of increment 1: the request (FR-16), the
| decision on it (FR-17), the security post (FR-18, FR-19), the control of
| the departure deadline (FR-20, which has no route — it is a scheduled sweep)
| and the visitor register (FR-21).
|
| The sixth is the announcement module of increment 2: publication (FR-09) and
| the feed (FR-11). FR-10, pinning an important announcement, is Could priority
| and outside the MVP — there is no route for it and no column behind one, and
| FR-12, the acknowledgement of reading, has been withdrawn from the MVP
| together with the table it rested on.
|
| The seventh is the maintenance module of increment 3: the resident files a
| defect (FR-36), the warden triages it (FR-37), the request moves along the
| graph FR-38 fixes, the reporter confirms it or says it is not fixed (FR-39),
| and the dormitory's queue is read (FR-40).
|
| The eighth is the lost-and-found module of increment 4: the resident who
| found something publishes it (FR-24), the dormitory reads the list without
| ever being shown who published an entry (FR-25), and a claim is filed,
| answered, referred and decided (FR-26). FR-27, the control of the six-month
| retention period of Civil Code art. 228 cl. 1, is Could priority and outside
| the MVP — there is no route for it and no scheduled pass behind one, though
| the two dates it will be counted from are in the table.
|
*/

/*
 * The named limiter rather than `throttle:10,1`: the anonymous form answers
 * with the framework's own body, which the contract does not describe. The
 * limit itself, and the shape of its refusal, are defined in AppServiceProvider.
 */
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

/*
 * `password.changed` closes the whole group to an account that still owes the
 * change of FR-42, and opens three routes of it by name. The group and not the
 * individual routes: a route added below is then refused until it is put on
 * that list deliberately.
 */
Route::middleware(['auth:sanctum', 'password.changed'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    /*
     * FR-42, the resident's end of it. Behind the session, because the account
     * is created with a password the office hands over on paper and the person
     * changing it signs in with it first. The limiter of its own stays: the old
     * password is checked here, and guessing at it is the same kind of attempt
     * as guessing at a sign-in — but keyed separately, so that a dormitory
     * behind one address does not lock itself out of the sign-in route.
     */
    Route::post('auth/password', [ResidentAccountController::class, 'changePassword'])
        ->middleware('throttle:password-setup')
        ->name('auth.password.change');

    /*
     * FR-01. Create, edit and delete are the administrator's; the delete is
     * refused with a stated reason while rooms are attached.
     */
    Route::get('buildings', [BuildingController::class, 'index'])
        ->name('buildings.index');

    Route::post('buildings', [BuildingController::class, 'store'])
        ->name('buildings.store');

    Route::get('buildings/{building}', [BuildingController::class, 'show'])
        ->name('buildings.show');

    Route::patch('buildings/{building}', [BuildingController::class, 'update'])
        ->name('buildings.update');

    Route::delete('buildings/{building}', [BuildingController::class, 'destroy'])
        ->name('buildings.destroy');

    Route::get('buildings/{building}/users', [BuildingController::class, 'people'])
        ->name('buildings.users');

    /*
     * FR-41. The staff of a building, as a sub-resource of that building:
     * there is no route by which a role is granted without naming the
     * dormitory it holds in. The warden appoints the manager and the security
     * officer here; the manager appoints nobody, and the administrator and
     * warden roles are not on offer to anyone.
     */
    Route::post('buildings/{building}/staff', [StaffController::class, 'store'])
        ->name('buildings.staff.store');

    Route::delete('buildings/{building}/staff/{user}/{role}', [StaffController::class, 'destroy'])
        ->name('buildings.staff.destroy');

    /*
     * FR-42. The account of an incoming resident. The generated password is in
     * this answer and in no other: the office prints it and hands it over.
     */
    Route::post('buildings/{building}/residents', [ResidentAccountController::class, 'store'])
        ->name('buildings.residents.store');

    /*
     * The closed list the `citizenship` field is validated against, so that the
     * form draws a dropdown from the same source the rule decides on.
     */
    Route::get('citizenships', [ResidentAccountController::class, 'citizenships'])
        ->name('citizenships.index');

    /*
     * FR-02. The register of rooms and places, kept per building.
     */
    Route::get('buildings/{building}/rooms', [RoomController::class, 'index'])
        ->name('buildings.rooms.index');

    Route::post('buildings/{building}/rooms', [RoomController::class, 'store'])
        ->name('buildings.rooms.store');

    /*
     * FR-02. The same register added up by floor, which is the screen the
     * housing stock is entered through: rooms and free places per floor, in one
     * aggregate rather than in a room-by-room walk done by the client.
     */
    Route::get('buildings/{building}/floors', [RoomController::class, 'floors'])
        ->name('buildings.floors');

    Route::get('rooms/{room}', [RoomController::class, 'show'])
        ->name('rooms.show');

    Route::patch('rooms/{room}', [RoomController::class, 'update'])
        ->name('rooms.update');

    Route::post('rooms/{room}/beds', [BedController::class, 'store'])
        ->name('rooms.beds.store');

    /*
     * FR-03 and FR-05. Moving in, and moving out; the row survives the second.
     */
    Route::post('residencies', [ResidencyController::class, 'store'])
        ->name('residencies.store');

    Route::post('residencies/{residency}/termination', [ResidencyController::class, 'terminate'])
        ->name('residencies.terminate');

    /*
     * FR-06. The card, decided on the person rather than on the role.
     */
    Route::get('residents/{resident}', [ResidentCardController::class, 'show'])
        ->name('residents.show');

    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->name('audit-logs.index');

    /*
     * FR-34. The personal account's own messages: one page of them, and the
     * mark that turns an unread message into a read one. Both routes are
     * scoped to the account the token names — there is no parameter through
     * which one person could ask about another's, which is why neither carries
     * a policy.
     */
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');

    /*
     |--------------------------------------------------------------------------
     | Announcements (increment 2): FR-09, FR-11
     |--------------------------------------------------------------------------
     |
     | Three routes, and two arrangements in them are decisions rather than
     | defaults.
     |
     | **The feed carries no building parameter.** The audience is computed from
     | the grants of the token, so there is no way to phrase a request for
     | another dormitory's feed — the boundary is the absence of a parameter and
     | not a policy somebody has to remember to call. A route
     | `GET /buildings/{building}/announcements` would have been the symmetrical
     | thing to write and would have put the horizontal boundary back in the
     | hands of a check.
     |
     | **The archive is a flag on the feed and not a route of its own.** FR-09's
     | «moves to the archive» is the other side of the `expires_at` comparison
     | the feed already makes; a second route would be a second query with the
     | same chance of disagreeing with the first.
     |
     | FR-12 — the acknowledgement and the report on who has read — has been
     | withdrawn from the MVP, and its two routes with it.
     */

    /*
     * FR-11. The caller's own feed: addressed to their dormitory or to every
     * dormitory, published, not yet expired, newest first.
     */
    Route::get('announcements', [AnnouncementController::class, 'index'])
        ->name('announcements.index');

    /*
     * FR-09. The warden or the manager of their own dormitory; the
     * administrator, who picks any dormitory by `building_id` and alone may
     * leave it out to address all of them.
     */
    Route::post('announcements', [AnnouncementController::class, 'store'])
        ->name('announcements.store');

    /*
     * The categories the publishing form offers before it lets the author type
     * one of their own, shaped as `GET /citizenships` is. The column itself is
     * a free label, so this is a catalogue and not the set of admissible
     * values.
     */
    Route::get('announcement-categories', [AnnouncementController::class, 'categories'])
        ->name('announcement-categories.index');

    /*
     |--------------------------------------------------------------------------
     | The guest module (increment 1): FR-16 … FR-21, FR-23
     |--------------------------------------------------------------------------
     |
     | The scenario of §3.5.1 read as a list of routes. A resident submits; the
     | warden or the manager of that dormitory decides; the security post finds
     | the guest, records the entry and later the exit; the warden exports the
     | register.
     |
     | Three arrangements below are decisions rather than defaults.
     |
     | The decision is a **sub-resource of the request** and not a PATCH of its
     | status. `POST …/approve` and `POST …/reject` name the act; a client that
     | could PUT a status could put any status, and the transition table of
     | §3.5.4 would be enforcing what the client already assumed.
     |
     | The checkpoint routes are **not** sub-resources of a building. The post
     | works with a code and a person, not with a dormitory identifier it would
     | have to be trusted to supply correctly; the building travels in the body
     | of `verify` — where it is what the officer's capability is checked
     | against — and is read off the request itself everywhere after that.
     |
     | `verify` is a **POST that changes nothing**. A surname and a visit code
     | are personal data of somebody standing at the desk, and a GET would put
     | them in every access log between the terminal and the application.
     */

    /*
     * FR-16, FR-17. The queue, and the resident's own list, behind one route:
     * with `building_id` it is the dormitory's queue and needs the capability
     * that reads it, without it the caller's own requests and needs nothing.
     */
    Route::get('guest-requests', [GuestRequestController::class, 'index'])
        ->name('guest-requests.index');

    Route::post('guest-requests', [GuestRequestController::class, 'store'])
        ->name('guest-requests.store');

    Route::get('guest-requests/{guestRequest}', [GuestRequestController::class, 'show'])
        ->name('guest-requests.show');

    /*
     * FR-17. The warden or the manager of this building, and nobody else — not
     * the administrator, who reads every queue and decides in none of them
     * (see `Permission`).
     */
    Route::post('guest-requests/{guestRequest}/approve', [GuestRequestController::class, 'approve'])
        ->name('guest-requests.approve');

    Route::post('guest-requests/{guestRequest}/reject', [GuestRequestController::class, 'reject'])
        ->name('guest-requests.reject');

    /*
     * The author withdraws their own. Staff who want a visit stopped refuse
     * it, which leaves a reason and an author on the row.
     */
    Route::post('guest-requests/{guestRequest}/cancellation', [GuestRequestController::class, 'cancel'])
        ->name('guest-requests.cancel');

    /*
     * FR-18 and FR-19, in the order the desk uses them: find the guest,
     * record the entry, record the exit.
     */
    Route::post('checkpoint/verify', [CheckpointController::class, 'verify'])
        ->name('checkpoint.verify');

    Route::post('checkpoint/check-in', [CheckpointController::class, 'checkIn'])
        ->name('checkpoint.check-in');

    Route::post('checkpoint/check-out', [CheckpointController::class, 'checkOut'])
        ->name('checkpoint.check-out');

    /*
     |--------------------------------------------------------------------------
     | The maintenance module (increment 3): FR-36 … FR-40
     |--------------------------------------------------------------------------
     |
     | The scenario of §3.5.2 read as a list of routes, and three arrangements
     | in them are decisions rather than defaults.
     |
     | **Every transition is a sub-resource of the request and not a PATCH of
     | its status.** `POST …/accept`, `…/reject`, `…/completion` name the act.
     | A client that could PUT a status could put any status, and the
     | transition table of FR-38 would be enforcing what the client had already
     | assumed. It is the same argument the guest module's `approve` and
     | `reject` rest on.
     |
     | **The resident's list and the warden's queue are two routes**, which is
     | where this module parts company with the guest one. There, one route
     | serves both because the two lists are the same rows read with a
     | different scope. Here they are not: the queue carries an age, an overdue
     | flag and the archive behind it, and none of that means anything on «my
     | own three requests». `GET /maintenance-requests` therefore takes no
     | parameter by which one resident could name another, and the queue is a
     | sub-resource of the building, decided on the building object.
     |
     | **`confirmation` and `reopening` are the reporter's routes and carry a
     | policy of their own.** §3.5.2: «the request is closed by the person who
     | reported it, not by the person who fixed it». The warden's routes and
     | the reporter's routes ask different authorisation questions, so they
     | arrive through different form requests; a single class choosing between
     | the two per route is the arrangement a later edit gets wrong silently.
     */

    /*
     * FR-36. The caller's own requests, and the filing of a new one.
     */
    Route::get('maintenance-requests', [MaintenanceRequestController::class, 'index'])
        ->name('maintenance-requests.index');

    Route::post('maintenance-requests', [MaintenanceRequestController::class, 'store'])
        ->name('maintenance-requests.store');

    Route::get('maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show'])
        ->name('maintenance-requests.show');

    /*
     * FR-36's photographs, read back one at a time. The schemas of the
     * contract have always said the client asks for a link when it is about to
     * show the image; until the acceptance of 15.09.2026 there was nothing to
     * ask, and an attached photograph could be read by nobody.
     *
     * Under the policy of the request itself and not one of its own: whoever
     * may read the card may see the picture on it, and a request of another
     * dormitory is a 403. The index is the position in `photo_paths`, so there
     * is no path in the URL for a client to edit.
     */
    Route::get('maintenance-requests/{maintenanceRequest}/photos/{index}', [MaintenanceRequestController::class, 'photo'])
        ->whereNumber('index')
        ->name('maintenance-requests.photo');

    /*
     * FR-37, FR-38. The warden or the manager of this dormitory, and nobody
     * else — not the administrator, and never the resident who filed it (see
     * `Permission`).
     */
    Route::post('maintenance-requests/{maintenanceRequest}/accept', [MaintenanceRequestController::class, 'accept'])
        ->name('maintenance-requests.accept');

    Route::post('maintenance-requests/{maintenanceRequest}/reject', [MaintenanceRequestController::class, 'reject'])
        ->name('maintenance-requests.reject');

    Route::post('maintenance-requests/{maintenanceRequest}/start', [MaintenanceRequestController::class, 'start'])
        ->name('maintenance-requests.start');

    Route::post('maintenance-requests/{maintenanceRequest}/completion', [MaintenanceRequestController::class, 'complete'])
        ->name('maintenance-requests.complete');

    /*
     * FR-39. The reporter's own two answers, and the only two ways out of
     * «completed» that a person can take.
     */
    Route::post('maintenance-requests/{maintenanceRequest}/confirmation', [MaintenanceRequestController::class, 'confirm'])
        ->name('maintenance-requests.confirm');

    Route::post('maintenance-requests/{maintenanceRequest}/reopening', [MaintenanceRequestController::class, 'reopen'])
        ->name('maintenance-requests.reopen');

    /*
     * FR-40. The open queue of one dormitory and the archive behind it.
     */
    Route::get('buildings/{building}/maintenance-queue', [MaintenanceQueueController::class, 'index'])
        ->name('buildings.maintenance-queue');

    /*
     |--------------------------------------------------------------------------
     | The lost-and-found module (increment 4): FR-24, FR-25, FR-26
     |--------------------------------------------------------------------------
     |
     | The scenario of §3.5.3 read as a list of routes, and four arrangements
     | in them are decisions rather than defaults.
     |
     | **There is no moderation route and no state for one to move an entry out
     | of.** FR-24's fourth criterion is «publication passes through no staff
     | approval step», and §2.5.4 gives the reason: routing every find through
     | a member of staff would put back the delay the module exists to remove.
     | `POST /lost-found` answers 201 and the entry is in the feed.
     |
     | **The feed carries no building parameter**, for the reason the
     | announcement feed does not: the dormitories are computed from the grants
     | of the token, so FR-25's «their own dormitory» is a missing parameter
     | rather than a policy somebody has to remember to call.
     |
     | **The claims of an entry are a sub-resource of it and the decisions on a
     | claim are not.** A claim is read and answered by three people — the
     | claimant, whoever is holding the object, the warden on a referral — and
     | each of the four acts below asks a different authorisation question. The
     | claim identifier is enough to find the entry, and a path that repeated
     | it would invite a client to send a pair that does not match.
     |
     | **`referral` is the claimant's route and `decision` is the warden's.**
     | §2.5.4 has the warden enter in two cases only, and this is the one a
     | route can express: a claim the two sides could not settle, put to the
     | warden by the person whose claim was refused. There is no route by which
     | a member of staff reaches an ordinary claim at all.
     */

    /*
     * FR-25. The finds of the caller's own dormitory, newest find first, with
     * the closed ones gone from the list (FR-26, third criterion).
     */
    Route::get('lost-found', [LostFoundController::class, 'index'])
        ->name('lost-found.index');

    /*
     * FR-24. The resident who found it, and — for an object handed in at the
     * post or deposited with the administration — the security officer, the
     * warden or the manager, on the same form.
     */
    Route::post('lost-found', [LostFoundController::class, 'store'])
        ->name('lost-found.store');

    /*
     * FR-25, second criterion. The card, which carries neither the name nor
     * the contacts of the person who published it.
     */
    Route::get('lost-found/{lostFoundItem}', [LostFoundController::class, 'show'])
        ->name('lost-found.show');

    /*
     * FR-24's photograph, read back. No index: the column holds one path, and
     * an entry published without a picture is a 404 rather than an empty
     * answer a client would have to tell apart from a link.
     */
    Route::get('lost-found/{lostFoundItem}/photo', [LostFoundController::class, 'photo'])
        ->name('lost-found.photo');

    /*
     * FR-26. «That is mine, and here is how I know.» A claim on one's own
     * entry is refused as a 422 naming the field, because what is wrong is the
     * object the request names and not the account that named it.
     */
    Route::post('lost-found/{lostFoundItem}/claims', [LostFoundClaimController::class, 'store'])
        ->name('lost-found.claims.store');

    /*
     * The claims of one entry: the person who has to answer them, and the
     * warden who may be asked to review one. Not the rest of the dormitory —
     * the card carries the count and never the marks.
     */
    Route::get('lost-found/{lostFoundItem}/claims', [LostFoundController::class, 'claims'])
        ->name('lost-found.claims.index');

    /*
     * FR-26, §2.4.4. The person holding the object decides — the finder on the
     * ordinary path, the staff of the dormitory for an object deposited with
     * them. No member of staff reaches an ordinary claim here.
     */
    Route::post('lost-found/claims/{lostFoundClaim}/accept', [LostFoundClaimController::class, 'accept'])
        ->name('lost-found.claims.accept');

    Route::post('lost-found/claims/{lostFoundClaim}/decline', [LostFoundClaimController::class, 'decline'])
        ->name('lost-found.claims.decline');

    /*
     * FR-26. The claimant's own route: a refusal they did not accept goes to
     * the warden, once.
     */
    Route::post('lost-found/claims/{lostFoundClaim}/referral', [LostFoundClaimController::class, 'refer'])
        ->name('lost-found.claims.refer');

    /*
     * FR-26, first criterion. The warden or the manager of this dormitory, on
     * a referred claim and on no other.
     */
    Route::post('lost-found/claims/{lostFoundClaim}/decision', [LostFoundClaimController::class, 'decide'])
        ->name('lost-found.claims.decide');

    /*
     * FR-26. The object changed hands: the entry closes with the time and
     * leaves the public list. Admitted only where a claim on the entry has
     * been accepted, which is the «only» of FR-26's first criterion.
     */
    Route::post('lost-found/{lostFoundItem}/resolve', [LostFoundController::class, 'resolve'])
        ->name('lost-found.resolve');

    /*
     * FR-21. The register of one dormitory over an arbitrary period, and the
     * correcting entry — which writes to the log and never to the visit.
     */
    Route::get('buildings/{building}/visit-register', [VisitRegisterController::class, 'index'])
        ->name('buildings.visit-register');

    Route::post('guest-visits/{guestVisit}/correction', [VisitRegisterController::class, 'correct'])
        ->name('guest-visits.correction');
});
