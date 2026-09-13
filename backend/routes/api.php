<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BedController;
use App\Http\Controllers\Api\V1\BuildingController;
use App\Http\Controllers\Api\V1\ResidencyController;
use App\Http\Controllers\Api\V1\ResidentCardController;
use App\Http\Controllers\Api\V1\RoomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioning sits in the path, as §3.3.6 fixes it; the `api/v1` prefix is
| applied in bootstrap/app.php, so the paths below are relative to it.
|
| This file covers two slices. The first is the role model (FR-07),
| authentication and sessions (FR-08) and the audit log the second of those
| writes to (FR-33). The second is the housing register: dormitories (FR-01),
| rooms and places (FR-02), moving in (FR-03), moving out (FR-05) and the
| resident card (FR-06).
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
});
