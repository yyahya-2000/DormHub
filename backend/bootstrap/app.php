<?php

use App\Exceptions\BedAlreadyOccupiedException;
use App\Exceptions\BedNotAssignableException;
use App\Exceptions\CapacityExceededException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\InvalidPasswordTokenException;
use App\Exceptions\LoginLockedException;
use App\Exceptions\RegistryDeletionBlockedException;
use App\Exceptions\ResidentAlreadyAccommodatedException;
use App\Http\Resources\ResidencyResource;
use App\Services\AccessDenialRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * The application layer raises domain exceptions and knows no status
         * codes (§3.3.1). The translation into the protocol happens here, at
         * the one place that is allowed to know both.
         */

        $exceptions->render(fn (InvalidCredentialsException $exception) => response()->json([
            'message' => $exception->getMessage(),
            'attempts_left' => $exception->attemptsLeft,
        ], 401));

        /*
         * The 429 of FR-08. `reason` is the discriminator the contract
         * describes: the same status also answers the per-address request
         * limit of the sign-in route, and a client must be able to tell «your
         * account is blocked» from «you are calling too often» without reading
         * the message.
         */
        $exceptions->render(fn (LoginLockedException $exception) => response()->json([
            'message' => $exception->getMessage(),
            'retry_after' => $exception->secondsRemaining,
            'reason' => $exception->reason->value,
        ], 429, ['Retry-After' => (string) $exception->secondsRemaining]));

        /*
         * §3.9.6 counts a refusal among the events the log must hold, and a
         * refusal is the one event no service raises: the request never
         * reaches the service that would record it. This is the single place
         * every 403 of the application passes through, so the record is
         * written here rather than repeated in each authorisation check.
         *
         * The callback returns nothing, so the response itself is still the
         * framework's — recording is all that is added.
         */
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): void {
            if ($exception->getStatusCode() === 403) {
                app(AccessDenialRecorder::class)->record($request, $exception);
            }
        });

        /*
         * FR-42: the one-time credential did not resolve — no such address, or
         * a token that is spent, expired or invented. 422 rather than 401: the
         * caller is not claiming a session, they are presenting a code, and the
         * code is the thing the request got wrong. The message says nothing
         * about which of the four causes it was (see the exception).
         */
        $exceptions->render(fn (InvalidPasswordTokenException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ], 422));

        /*
         * FR-01, second criterion: the deletion is blocked and the reason is
         * stated. 409 rather than 403 — the caller had the right to ask, and
         * what stands in the way is the state of the register, not their role.
         */
        $exceptions->render(fn (RegistryDeletionBlockedException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 409));

        /*
         * FR-02, second criterion: the attempt to exceed the capacity of a
         * room is rejected **and the free remainder is shown**. The remainder
         * comes from the exception, so the number the client reads is the
         * number the register refused on.
         */
        $exceptions->render(fn (CapacityExceededException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 422));

        /*
         * FR-03, second criterion: on an attempt to place two residents in one
         * bed «the system displays the conflicting record». It travels in the
         * body, under `conflict`, shaped by the same resource the successful
         * answer uses.
         */
        $exceptions->render(fn (BedAlreadyOccupiedException $exception, Request $request) => response()->json([
            'message' => $exception->getMessage(),
            'bed_id' => $exception->bedId,
            'conflict' => $exception->conflicting === null
                ? null
                : ResidencyResource::make($exception->conflicting)->toArray($request),
        ], 409));

        /*
         * §3.4.4: a user occupies one bed at a time. The record in the way is
         * shown for the same reason.
         */
        $exceptions->render(fn (ResidentAlreadyAccommodatedException $exception, Request $request) => response()->json([
            'message' => $exception->getMessage(),
            'user_id' => $exception->userId,
            'conflict' => $exception->conflicting === null
                ? null
                : ResidencyResource::make($exception->conflicting)->toArray($request),
        ], 409));

        /*
         * A bed withdrawn from use, or a room out of service. Nobody holds it,
         * so this is not a conflict; the request is simply not one the register
         * can carry out.
         */
        $exceptions->render(fn (BedNotAssignableException $exception) => response()->json([
            'message' => $exception->getMessage(),
            'bed_id' => $exception->bedId,
        ], 422));
    })->create();
