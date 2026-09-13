<?php

use App\Exceptions\BedAlreadyOccupiedException;
use App\Exceptions\BedNotAssignableException;
use App\Exceptions\CapacityExceededException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\LoginLockedException;
use App\Exceptions\RegistryDeletionBlockedException;
use App\Exceptions\ResidentAlreadyAccommodatedException;
use App\Http\Resources\ResidencyResource;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        $exceptions->render(fn (LoginLockedException $exception) => response()->json([
            'message' => $exception->getMessage(),
            'retry_after' => $exception->secondsRemaining,
        ], 429, ['Retry-After' => (string) $exception->secondsRemaining]));

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
