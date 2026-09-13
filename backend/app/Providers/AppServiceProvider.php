<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\IdentityProvider;
use App\Services\AuditLogReader;
use App\Services\AuditRecorder;
use App\Services\AuthenticationService;
use App\Services\LoginThrottle;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
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
         * rather than from a literal inside the throttle.
         */
        $this->app->bind(LoginThrottle::class, fn ($app) => new LoginThrottle(
            cache: $app->make(Cache::class),
            maxAttempts: (int) config('dormitory.auth.max_attempts'),
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
    }

    public function boot(): void
    {
        // A write of an attribute that no model declares fillable is an error
        // rather than a silent omission, outside production.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
