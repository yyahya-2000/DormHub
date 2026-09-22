<?php

use App\Exceptions\BedAlreadyOccupiedException;
use App\Exceptions\BedNotAssignableException;
use App\Exceptions\CapacityExceededException;
use App\Exceptions\ConfirmationWindowClosedException;
use App\Exceptions\EntryNotPermittedException;
use App\Exceptions\GuestQuotaExceededException;
use App\Exceptions\IllegalTransitionException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\LoginLockedException;
use App\Exceptions\NoAcceptedClaimException;
use App\Exceptions\PhotoStorageFailedException;
use App\Exceptions\RegistryDeletionBlockedException;
use App\Exceptions\ResidentAlreadyAccommodatedException;
use App\Exceptions\VisitAlreadyClosedException;
use App\Http\Middleware\RequirePasswordChange;
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
        $middleware->alias([
            'password.changed' => RequirePasswordChange::class,
        ]);

        /*
         * There is no sign-in page to send a guest to. Left at its default the
         * auth middleware builds one with route('login') for any request that
         * does not ask for JSON, and a request without an Accept header — curl,
         * a scanner, anything but the client — got 500 where it should have got
         * 401. Returning null makes it raise the authentication exception, which
         * the handler below renders as JSON.
         */
        $middleware->redirectGuestsTo(fn () => null);

        /*
         * Behind the production reverse proxy every request otherwise appears
         * to come from the proxy, which collapses the per-address sign-in limit
         * of FR-08 into one shared ceiling and writes the proxy's address into
         * the audit log. Unset in development, where there is no proxy and
         * X-Forwarded-For would be whatever the client chose to send.
         */
        $proxies = env('TRUSTED_PROXIES');

        if ($proxies !== null && $proxies !== '') {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : explode(',', $proxies),
            );
        }
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

        /*
         * §3.5.4: an illegal transition is HTTP 409. The caller had the right
         * to ask and the body was well formed; the request is simply no longer
         * in the state the move starts from, which is usually because somebody
         * else moved it first. The body names both ends so the client can say
         * «already refused» rather than «something went wrong».
         */
        $exceptions->render(fn (IllegalTransitionException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 409));

        /*
         * FR-39, and the one refusal of the maintenance module a transition
         * table cannot express. The reopening was offered after the
         * confirmation window ran out; the state machine still admits
         * `completed → accepted`, because that is the move FR-39 is about, and
         * what has run out is the clock rather than the state.
         *
         * 409 for the same reason the illegal transition above is: the body is
         * well formed and the caller is the right person, and the same call a
         * day earlier would have succeeded. The body carries the window and
         * the date it closed so the client can say which.
         */
        $exceptions->render(fn (ConfirmationWindowClosedException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 409));

        /*
         * FR-26's «only», and the one refusal of the lost-and-found module a
         * transition table cannot express. An entry was offered for closure
         * with no claim on it accepted — the entry is `claimed` and
         * `claimed → resolved` is a move the table admits, correctly, because
         * that is the move FR-26 is about. What is missing is a row in another
         * table, and a transition table that knew about those would be a
         * transition table with a query in it.
         *
         * 409 for the same reason the illegal transition above is: the body is
         * well formed and the caller is the person holding the object, and the
         * same call after an acceptance would succeed. The body carries how
         * many claims are still waiting for an answer, so the client can say
         * which.
         */
        $exceptions->render(fn (NoAcceptedClaimException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 409));

        /*
         * FR-36 and FR-24's photographs, and the acceptance finding of
         * 15.09.2026: a file the object store refused used to leave the route
         * answering 201 with nothing attached.
         *
         * 503 rather than 422 or 500. The body was well formed, the caller was
         * entitled to send it and the application did what it was asked; what
         * failed is the store behind it, and the same call succeeds unchanged
         * once the store is back. The record is not written — the refusal
         * happens before the service is called — so there is nothing for the
         * client to undo before retrying.
         */
        $exceptions->render(fn (PhotoStorageFailedException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 503));

        /*
         * §3.3.4 maps the quota breach to 422, and the body carries which of
         * the two ceilings was hit and the count that hit it: a manager told
         * only «quota exceeded» cannot tell a resident's third guest from the
         * dormitory's sixtieth.
         */
        $exceptions->render(fn (GuestQuotaExceededException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 422));

        /*
         * FR-18, FR-19, and §2.4.2's second scenario in the shape the terminal
         * reads it.
         *
         * 422: the entry asked for is not one the rules admit as it stands.
         * `reason_code` is the discriminator — the officer's screen has to
         * tell «outside the permitted interval», which an officer's decision
         * may set aside, from «this code belongs to another dormitory», which
         * it may not — and `override_available` says which of the two this is
         * without anybody parsing a sentence.
         *
         * The refusal is already in the audit log by the time this runs: the
         * service records it, outside any transaction, because a refusal
         * written inside the transaction it refuses is carried off by the
         * rollback.
         */
        $exceptions->render(fn (EntryNotPermittedException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 422));

        /*
         * FR-21, second criterion. An exit offered for a visit that already
         * carries one. 409, and the message says what to do instead — the
         * register is append-only and a mistake is put right by a correcting
         * entry, not by an overwrite.
         */
        $exceptions->render(fn (VisitAlreadyClosedException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ] + $exception->context(), 409));
    })->create();
