<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\IdentityProvider;
use App\Enums\ThrottleReason;
use App\Files\PhotoStore;
use App\Guests\GuestQuota;
use App\Http\Controllers\Api\V1\LostFoundController;
use App\LostFound\LostFoundClaimStateMachine;
use App\LostFound\LostFoundItemStateMachine;
use App\Maintenance\MaintenanceRequestStateMachine;
use App\Models\MaintenanceRequest;
use App\Services\AuditLogReader;
use App\Services\AuditRecorder;
use App\Services\AuthenticationService;
use App\Services\LoginThrottle;
use App\Services\LostFoundFeed;
use App\Services\LostFoundService;
use App\Services\MaintenanceQueue;
use App\Services\MaintenanceService;
use App\Services\Notifier;
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

        /*
         * FR-17, the quota §3.3.4 has `approve()` assert. No norm fixes the
         * numbers, so they are a house rule and arrive from configuration for
         * the same reason «five attempts, fifteen minutes» does: a literal
         * inside the checker would be this deployment's rule imposed on every
         * dormitory the code is ever installed in.
         */
        $this->app->bind(GuestQuota::class, fn ($app) => new GuestQuota(
            perResident: (int) config('dormitory.guests.daily_quota_per_resident'),
            perBuilding: (int) config('dormitory.guests.daily_quota_per_building'),
        ));

        /*
         * FR-39 and FR-40, the two numbers those requirements make
         * configuration in so many words: «reopening within the configurable
         * window», «the overdue threshold is configuration, not code». They
         * are bound here for the same reason «five attempts, fifteen minutes»
         * is — a literal inside the service would be this deployment's rule
         * imposed on every dormitory the code is ever installed in, and the
         * test that proves the rule is read rather than compiled in would have
         * nothing to move.
         */
        $this->app->bind(MaintenanceService::class, fn ($app) => new MaintenanceService(
            states: $app->make(MaintenanceRequestStateMachine::class),
            audit: $app->make(AuditRecorder::class),
            notifier: $app->make(Notifier::class),
            confirmationWindowDays: (int) config('dormitory.maintenance.confirmation_window_days'),
        ));

        $this->app->bind(MaintenanceQueue::class, fn ($app) => new MaintenanceQueue(
            overdueAfterDays: (int) config('dormitory.maintenance.overdue_after_days'),
            pageSize: (int) config('dormitory.maintenance.queue_page_size'),
        ));

        /*
         * FR-36's photographs. The disk is configuration because the
         * deployment's object store is (§3.2.2) and because the test suite
         * pins a fake one; the ceiling is not, because the CHECK constraint
         * behind it cannot be — `MaintenanceRequest::MAX_PHOTOS` is the one
         * figure the rule, the constraint and this adapter read.
         */
        $this->app->bind(PhotoStore::class, fn ($app) => new PhotoStore(
            disk: (string) config('dormitory.maintenance.photo_disk'),
            directory: (string) config('dormitory.maintenance.photo_directory'),
            maximum: MaintenanceRequest::MAX_PHOTOS,
        ));

        /*
         * FR-24 … FR-26. The service takes no configuration at all, and the
         * emptiness is worth a line: every number the other modules read from
         * `config/dormitory.php` — a confirmation window, an overdue
         * threshold, a daily quota — is a house rule, and this module has
         * none. The one period it will one day count is the six months of
         * Civil Code art. 228 cl. 1, which is a statute rather than a setting
         * and which FR-27 puts outside the MVP.
         */
        $this->app->bind(LostFoundService::class, fn ($app) => new LostFoundService(
            items: $app->make(LostFoundItemStateMachine::class),
            claims: $app->make(LostFoundClaimStateMachine::class),
            audit: $app->make(AuditRecorder::class),
            notifier: $app->make(Notifier::class),
        ));

        $this->app->bind(LostFoundFeed::class, fn ($app) => new LostFoundFeed(
            pageSize: (int) config('dormitory.lost_found.feed_page_size'),
        ));

        /*
         * FR-24's photograph, on a disk of its own.
         *
         * A contextual binding rather than a second class: `PhotoStore` is
         * written to be configured, and the two modules differ only in where
         * they put the file and how large a file they take. It is contextual
         * and not a plain binding because the maintenance module already holds
         * the default one, and it names a controller because a contextual
         * binding reaches constructor injection — which is why
         * `LostFoundController` takes its store through a constructor while
         * `MaintenanceRequestController` takes its through a method parameter.
         *
         * `maximum: 1` is FR-24's «the photograph» in the singular, applied
         * where a caller that is not a form — a seeder, a later import — runs
         * into it as well.
         */
        $this->app->when(LostFoundController::class)
            ->needs(PhotoStore::class)
            ->give(fn ($app) => new PhotoStore(
                disk: (string) config('dormitory.lost_found.photo_disk'),
                directory: (string) config('dormitory.lost_found.photo_directory'),
                maximum: 1,
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
