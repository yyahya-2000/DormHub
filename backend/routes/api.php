<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BedController;
use App\Http\Controllers\Api\V1\BuildingController;
use App\Http\Controllers\Api\V1\CheckpointController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\GuestRequestController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationSettingController;
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
| the personal account itself: notifications and their switches (FR-34), and
| consent to the processing of personal data (FR-35).
|
| The fifth is the guest module of increment 1: the request (FR-16), the duty
| officer's decision (FR-17), the security post (FR-18, FR-19), the control of
| the departure deadline (FR-20, which has no route — it is a scheduled sweep)
| and the visitor register (FR-21).
|
| The sixth is the announcement module of increment 2: publication (FR-09), the
| feed (FR-11) and the acknowledgement of reading (FR-12). FR-10, pinning an
| important announcement, is Could priority and outside the MVP — there is no
| route for it and no column behind one.
|
| The remaining routes of §3.3.6 — lost-and-found and maintenance — belong to
| later increments and are deliberately absent rather than stubbed: the OpenAPI
| document beside this file is the input for client generation, and a generated
| client should not carry methods that answer 404.
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
 * FR-42, the resident's end of it. Unauthenticated by necessity — the account
 * has no password yet — and behind a per-address limiter of its own, because a
 * one-time code is a secret and guessing at it is the same kind of attempt.
 *
 * A limiter of its own and not the sign-in one. Both are keyed by address, and
 * a dormitory is one address: while they shared a counter, a few guesses at a
 * code locked everybody behind that address out of signing in.
 */
Route::post('auth/password', [ResidentAccountController::class, 'setPassword'])
    ->middleware('throttle:password-setup')
    ->name('auth.password.set');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    /*
     * FR-01. Create, edit and archive are the administrator's; the delete is
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

    Route::post('buildings/{building}/archive', [BuildingController::class, 'archive'])
        ->name('buildings.archive');

    Route::delete('buildings/{building}', [BuildingController::class, 'destroy'])
        ->name('buildings.destroy');

    Route::get('buildings/{building}/users', [BuildingController::class, 'people'])
        ->name('buildings.users');

    /*
     * FR-41. The staff of a building, as a sub-resource of that building:
     * there is no route by which a role is granted without naming the
     * dormitory it holds in. The warden appoints the manager, the duty officer
     * and the security officer here; the manager appoints nobody, and the
     * administrator and warden roles are not on offer to anyone.
     */
    Route::post('buildings/{building}/staff', [StaffController::class, 'store'])
        ->name('buildings.staff.store');

    Route::delete('buildings/{building}/staff/{user}/{role}', [StaffController::class, 'destroy'])
        ->name('buildings.staff.destroy');

    /*
     * FR-42. The account of an incoming resident. The credential it produces
     * leaves by the route above the sanctum group, not in this response.
     */
    Route::post('buildings/{building}/residents', [ResidentAccountController::class, 'store'])
        ->name('buildings.residents.store');

    /*
     * FR-42, the way back out of a lost code. The same circle of accounts as
     * the route above, because sending a second code is the same act as
     * sending the first; the code goes to the address on the account, and the
     * person who asks for it never sees it.
     */
    Route::post('buildings/{building}/residents/{resident}/credential', [ResidentAccountController::class, 'reissueCredential'])
        ->name('buildings.residents.credential');

    /*
     * FR-02. The register of rooms and places, kept per building.
     */
    Route::get('buildings/{building}/rooms', [RoomController::class, 'index'])
        ->name('buildings.rooms.index');

    Route::post('buildings/{building}/rooms', [RoomController::class, 'store'])
        ->name('buildings.rooms.store');

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
     * FR-34. The personal account's own messages, and the switches that decide
     * which of them arrive at all. Every route below is scoped to the account
     * the token names: there is no parameter through which one person could
     * ask about another's, which is why none of them carries a policy.
     */
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');

    Route::get('notification-settings', [NotificationSettingController::class, 'index'])
        ->name('notification-settings.index');

    Route::put('notification-settings', [NotificationSettingController::class, 'update'])
        ->name('notification-settings.update');

    /*
     * FR-35. Consent, on routes of its own.
     *
     * That they are routes of their own is the requirement and not a matter of
     * arrangement: art. 9 part 1 of Federal Law No. 152-FZ has consent
     * «executed separately from other documents», so no other request in this
     * file carries a field that could record one, and this is the only way a
     * consent record comes into being.
     *
     * The guest's consent is taken at the security post and not here; the
     * mechanism is the same one, and the point it plugs into is
     * `ConsentRegistry::requireGranted()`, called by the checkpoint service of
     * increment 1 before an entry is written.
     */
    Route::get('consents/pending', [ConsentController::class, 'pending'])
        ->name('consents.pending');

    Route::get('consents', [ConsentController::class, 'index'])
        ->name('consents.index');

    Route::post('consents', [ConsentController::class, 'store'])
        ->name('consents.store');

    Route::post('consents/{document}/withdrawal', [ConsentController::class, 'withdraw'])
        ->name('consents.withdraw');

    /*
     |--------------------------------------------------------------------------
     | Announcements (increment 2): FR-09, FR-11, FR-12
     |--------------------------------------------------------------------------
     |
     | Four routes, and three arrangements in them are decisions rather than
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
     | **The readers report is a sub-resource of the announcement.** It returns
     | a named list of residents who have not complied with an instruction, and
     | §4.6.1 calls it the natural place for a horizontal leak. It carries a
     | policy of its own, the list is narrowed to the caller's own dormitory,
     | and both halves are tested.
     */

    /*
     * FR-11. The caller's own feed: addressed to their dormitory or to every
     * dormitory, published, not yet expired, newest first, unread marked.
     */
    Route::get('announcements', [AnnouncementController::class, 'index'])
        ->name('announcements.index');

    /*
     * FR-09. The warden or the manager of their own dormitory; the
     * administrator, who alone may leave `building_id` out and address all of
     * them.
     */
    Route::post('announcements', [AnnouncementController::class, 'store'])
        ->name('announcements.store');

    /*
     * FR-12. Idempotent: a repeat returns the acknowledgement already on
     * record rather than writing a second one.
     */
    Route::post('announcements/{announcement}/ack', [AnnouncementController::class, 'acknowledge'])
        ->name('announcements.ack');

    /*
     * FR-12, second criterion. The acknowledged share and the two named lists,
     * within the caller's own building.
     */
    Route::get('announcements/{announcement}/readers', [AnnouncementController::class, 'readers'])
        ->name('announcements.readers');

    /*
     |--------------------------------------------------------------------------
     | The guest module (increment 1): FR-16 … FR-21, FR-23
     |--------------------------------------------------------------------------
     |
     | The scenario of §3.5.1 read as a list of routes. A resident submits; the
     | duty officer of that dormitory decides; the security post finds the
     | guest, takes their consent, records the entry and later the exit; the
     | warden exports the register.
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
     * FR-17. The duty officer of this building, and nobody else — not the
     * warden, not the manager, not the administrator (see `Permission`).
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
     * NFR-06. The document number in full, to somebody who may, one request at
     * a time, and recorded as an event of its own.
     */
    Route::get('guest-requests/{guestRequest}/document-number', [GuestRequestController::class, 'documentNumber'])
        ->name('guest-requests.document-number');

    /*
     * FR-18, FR-19 and the guest's consent between them (FR-35, §2.7.1). The
     * order the desk uses them in is the order they are listed in, and the
     * application refuses any other: the entry asks for the consent first.
     */
    Route::post('checkpoint/verify', [CheckpointController::class, 'verify'])
        ->name('checkpoint.verify');

    Route::post('checkpoint/guest-consent', [CheckpointController::class, 'consent'])
        ->name('checkpoint.consent');

    Route::post('checkpoint/check-in', [CheckpointController::class, 'checkIn'])
        ->name('checkpoint.check-in');

    Route::post('checkpoint/check-out', [CheckpointController::class, 'checkOut'])
        ->name('checkpoint.check-out');

    /*
     * FR-21. The register of one dormitory over an arbitrary period, and the
     * correcting entry — which writes to the log and never to the visit.
     */
    Route::get('buildings/{building}/visit-register', [VisitRegisterController::class, 'index'])
        ->name('buildings.visit-register');

    Route::post('guest-visits/{guestVisit}/correction', [VisitRegisterController::class, 'correct'])
        ->name('guest-visits.correction');
});
