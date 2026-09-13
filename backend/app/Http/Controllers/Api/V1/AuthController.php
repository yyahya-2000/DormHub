<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\IssuedTokenResource;
use App\Http\Resources\UserResource;
use App\Services\AuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * FR-08 at the protocol boundary. Each action takes a validated request, calls
 * one service method and returns a resource; the attempt limit, the block and
 * the audit record live in the service, and nothing here branches on a
 * business rule (§3.3.3).
 */
final class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticationService $authentication): IssuedTokenResource
    {
        return new IssuedTokenResource(
            $authentication->signIn($request->credentials(), $request->ip())
        );
    }

    public function logout(Request $request, AuthenticationService $authentication): Response
    {
        $authentication->signOut($request->user(), $request->ip());

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roleGrants.role'));
    }
}
