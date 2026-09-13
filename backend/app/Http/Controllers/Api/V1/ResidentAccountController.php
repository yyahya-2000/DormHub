<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SetPasswordRequest;
use App\Http\Requests\Api\V1\StoreResidentAccountRequest;
use App\Http\Resources\UserResource;
use App\Models\Building;
use App\Services\PasswordSetup;
use App\Services\ResidentAccountIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * FR-42. Two routes, at the two ends of one credential: the warden or manager
 * creates the account, and the resident — with the code that reached their own
 * contact — puts a password on it.
 *
 * The answer to the first is a `UserResource`, which is written out field by
 * field and has no password among them. That is what makes the third criterion
 * checkable rather than merely intended: there is no branch in which a secret
 * could be added to this response, because the resource does not know one.
 */
final class ResidentAccountController extends Controller
{
    public function store(
        StoreResidentAccountRequest $request,
        Building $building,
        ResidentAccountIssuer $accounts,
    ): JsonResponse {
        $resident = $accounts->issue(
            actor: $request->user(),
            building: $building,
            attributes: $request->payload(),
            ipAddress: $request->ip(),
        );

        return UserResource::make($resident)
            ->response()
            ->setStatusCode(201);
    }

    public function setPassword(SetPasswordRequest $request, PasswordSetup $passwords): Response
    {
        $passwords->complete(
            email: $request->email(),
            token: $request->token(),
            password: $request->password(),
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }
}
