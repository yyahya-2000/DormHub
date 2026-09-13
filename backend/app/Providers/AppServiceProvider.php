<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\IdentityProvider;
use App\Enums\ThrottleReason;
use App\Services\AuditLogReader;
use App\Services\AuditRecorder;
use App\Services\AuthenticationService;
use App\Services\LoginThrottle;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The identity provider is chosen in configuration, which is what
         * makes FR-08's third criterion true: a university SSO enters as a
         * second class behind the same interface, and nothing that consumes
         * the interface changes.
         */
        $this->app->bind(
            IdentityProvider::class,
            fn ($app) => $app->make((string) config('dormitory.auth.identity_provider'))
        );

        /*
         * «Five attempts, fifteen minutes» arrives from config/dormitory.php
         * rather than from a literal inside the throttle, and so does the
         * separate ceiling the throttle keeps per address.
         */
        $this->app->bind(LoginThrottle::class, fn ($app) => new LoginThrottle(
            cache: $app->make(Cache::class),
            maxAttempts: (int) config('dormitory.auth.max_attempts'),
            maxAttemptsPerAddress: (int) config('dormitory.auth.max_attempts_per_address'),
            lockoutSeconds: (int) config('dormitory.auth.lockout_minutes') * 60,
        ));

        $this->app->bind(AuthenticationService::class, fn ($app) => new AuthenticationService(
            identityProvider: $app->make(IdentityProvider::class),
            throttle: $app->make(LoginThrottle::class),
            audit: $app->make(AuditRecorder::class),
            tokenName: (string) config('dormitory.auth.token_name'),
            tokenTtlMinutes: config('dormitory.auth.token_ttl_minutes'),
        ));

        $this->app->bind(AuditLogReader::class, fn ($app) => new AuditLogReader(
            audit: $app->make(AuditRecorder::class),
            pageSize: (int) config('dormitory.audit.page_size'),
        ));

        $this->registerRouteRateLimiters();
    }

    public function boot(): void
    {
        // A write of an attribute that no model declares fillable is an error
        // rather than a silent omission, outside production.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    /**
     * The two per-address request limits the unauthenticated routes carry.
     *
     * The framework's own refusal for a named limiter is a bare «Too Many
     * Attempts.», and with APP_DEBUG on it arrives with a stack trace attached
     * — a body that matches nothing in api/openapi.yaml, although the contract
     * is what the client is generated from. Each limiter is therefore given a
     * response of its own, shaped like every other 429 of this API: a message,
     * the seconds to wait, and the reason that tells it apart from the block
     * that follows failed attempts.
     *
     * **Why there are two (acceptance of 14.09.2026).** `POST /auth/password`
     * used to share the sign-in limiter, on the argument that guessing a
     * one-time code is the same kind of attempt as guessing a password. The
     * argument holds; sharing a *counter* does not follow from it. Both routes
     * are keyed by network address, and a dormitory is one address: six
     * attempts at a code closed the sign-in route for everybody behind the same
     * NAT, which is a denial of service anybody can trigger from a phone.
     * Separate counters keep the defence and drop the collateral — an attack on
     * one road no longer bars the other.
     *
     * The definitions are attached to the rate limiter as it is resolved rather
     * than declared once during boot. The limiter holds the cache store it was
     * built with, so anything that has to change the store — the test suite
     * pinning the array driver, for one — drops the instance and asks for a
     * new one; a definition registered on the old instance would be gone with
     * it, and the route would answer 500 instead of 429.
     */
    private function registerRouteRateLimiters(): void
    {
        $this->app->afterResolving(RateLimiter::class, function (RateLimiter $limiter): void {
            $limiter->for('login', $this->loginLimit(...));
            $limiter->for('password-setup', $this->passwordSetupLimit(...));
        });
    }

    private function loginLimit(Request $request): Limit
    {
        return $this->perAddress(
            $request,
            (int) config('dormitory.auth.login_requests_per_minute'),
            'Too many sign-in requests from this address. Try again shortly.',
        );
    }

    /**
     * The same shape, a counter of its own, and a message that names the route
     * it actually refused — the shared limiter used to answer an attempt to set
     * a password with a sentence about signing in.
     */
    private function passwordSetupLimit(Request $request): Limit
    {
        return $this->perAddress(
            $request,
            (int) config('dormitory.auth.password_requests_per_minute'),
            'Too many attempts to set a password from this address. Try again shortly.',
        );
    }

    private function perAddress(Request $request, int $perMinute, string $message): Limit
    {
        return Limit::perMinute($perMinute)
            ->by($request->ip() ?? 'unknown')
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => $message,
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                'reason' => ThrottleReason::RateLimited->value,
            ], 429, $headers));
    }
}
