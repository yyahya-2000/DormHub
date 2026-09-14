<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Citizenship;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\StoreResidentAccountRequest;
use App\Http\Resources\IssuedAccountResource;
use App\Models\Building;
use App\Services\PasswordSetup;
use App\Services\ResidentAccountIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * FR-42. Two routes, at the two ends of one password: the warden or manager
 * creates the account and is shown the generated password once, and the
 * resident — signed in with it — replaces it with one of their own.
 *
 * The password exists in readable form in exactly one response body, the one
 * `store()` returns. `IssuedAccountResource` is the only resource in the
 * project with a password field; `UserResource`, which every other route
 * answers with, has none and therefore cannot leak one.
 */
final class ResidentAccountController extends Controller
{
    public function store(
        StoreResidentAccountRequest $request,
        Building $building,
        ResidentAccountIssuer $accounts,
    ): JsonResponse {
        $issued = $accounts->issue(
            actor: $request->user(),
            building: $building,
            attributes: $request->payload(),
            ipAddress: $request->ip(),
        );

        return IssuedAccountResource::make($issued)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The closed list `citizenship` is validated against, with the names
     * beside the codes, so that the form draws a dropdown instead of trusting
     * whatever somebody types.
     */
    public function citizenships(): JsonResponse
    {
        return response()->json(['data' => Citizenship::options()]);
    }

    /**
     * FR-42: the resident replaces the password the office gave them.
     *
     * Behind the session rather than behind a one-time code: the account has a
     * password from the moment it is created, so the person changing it is the
     * person signed in with it. The old password is asked for anyway — a
     * stolen token must not be enough to take the account over.
     */
    public function changePassword(ChangePasswordRequest $request, PasswordSetup $passwords): Response
    {
        $passwords->change(
            user: $request->user(),
            password: $request->password(),
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }
}
