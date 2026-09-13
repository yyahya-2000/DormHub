<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BuildingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioning sits in the path, as §3.3.6 fixes it; the `api/v1` prefix is
| applied in bootstrap/app.php, so the paths below are relative to it.
|
| This file covers one vertical slice: the role model (FR-07), authentication
| and sessions (FR-08), and the audit log the second of those writes to
| (FR-33). The remaining routes of §3.3.6 belong to later increments and are
| deliberately absent rather than stubbed.
|
*/

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::get('buildings/{building}', [BuildingController::class, 'show'])
        ->name('buildings.show');

    Route::get('buildings/{building}/users', [BuildingController::class, 'people'])
        ->name('buildings.users');

    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->name('audit-logs.index');
});
