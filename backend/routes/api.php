<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BedController;
use App\Http\Controllers\Api\V1\BuildingController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationSettingController;
use App\Http\Controllers\Api\V1\ResidencyController;
use App\Http\Controllers\Api\V1\ResidentAccountController;
use App\Http\Controllers\Api\V1\ResidentCardController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\StaffController;
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
| The remaining routes of §3.3.6 — guest requests, the checkpoint,
| announcements, lost-and-found and maintenance — belong to later increments
| and are deliberately absent rather than stubbed: the OpenAPI document beside
| this file is the input for client generation, and a generated client should
| not carry methods that answer 404.
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
});
