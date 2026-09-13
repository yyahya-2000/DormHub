<?php

use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\LoginLockedException;
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
    })->create();
