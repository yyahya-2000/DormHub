<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AppointStaffRequest;
use App\Http\Requests\Api\V1\RevokeStaffRequest;
use App\Http\Resources\RoleGrantResource;
use App\Models\Building;
use App\Models\User;
use App\Services\StaffRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * FR-41. The staff of a building as a sub-resource of that building, because
 * an appointment exists only inside one dormitory: there is no route by which
 * a role is granted without naming the scope it holds in.
 *
 * Authorisation is settled by the form request that reached each method, so
 * neither action contains a check of its own (§3.3.3).
 */
final class StaffController extends Controller
{
    public function store(
        AppointStaffRequest $request,
        Building $building,
        StaffRegistry $staff,
    ): JsonResponse {
        $grant = $staff->appoint(
            actor: $request->user(),
            subject: $request->subject(),
            code: $request->role(),
            building: $building,
            ipAddress: $request->ip(),
        );

        return RoleGrantResource::make($grant)
            ->response()
            // A repeated appointment is not a new one. The row already stood,
            // no second audit entry was written, and 200 says so.
            ->setStatusCode($grant->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(
        RevokeStaffRequest $request,
        Building $building,
        User $user,
        StaffRegistry $staff,
    ): Response {
        $revoked = $staff->revoke(
            actor: $request->user(),
            subject: $user,
            code: $request->role(),
            building: $building,
            ipAddress: $request->ip(),
        );

        abort_unless($revoked, 404, 'This account holds no such role in this dormitory.');

        return response()->noContent();
    }
}
