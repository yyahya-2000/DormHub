<?php

use App\Identity\DatabaseIdentityProvider;

return [

    /*
    |---------------------------------------------------------------------------
    | Authentication and sessions (FR-08)
    |---------------------------------------------------------------------------
    |
    | The acceptance criterion — five failed attempts block the login for
    | fifteen minutes — is a setting, not a literal buried in a service. A
    | building that needs a stricter regime changes the value rather than the
    | code, which is the same argument NFR-09 makes for the visiting window.
    |
    */

    'auth' => [

        // Failed attempts tolerated before the login is blocked. Counted per
        // account, so that the block belongs to the account and does not fall
        // away when the attacker changes address.
        'max_attempts' => (int) env('AUTH_MAX_ATTEMPTS', 5),

        // Failed attempts tolerated from one network address, counted across
        // every login tried from it. Deliberately higher than the per-account
        // limit: a computer room or a university NAT is one address for many
        // people, and a few mistyped passwords must not close it. Its purpose
        // is the other attack — a dictionary of logins, which fills no account
        // counter at all.
        'max_attempts_per_address' => (int) env('AUTH_MAX_ATTEMPTS_PER_ADDRESS', 20),

        // Length of the block, in minutes, counted from the attempt that
        // reached the limit. The same span serves both counters.
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),

        // Requests per minute the sign-in route admits from one address,
        // regardless of whether they carry a correct password. This is the
        // cheap defence in front of the counters above, and its refusal is a
        // different event: see App\Enums\ThrottleReason.
        'login_requests_per_minute' => (int) env('AUTH_LOGIN_REQUESTS_PER_MINUTE', 10),

        // Lifetime of an issued API token, in minutes. NULL means the token
        // does not expire on its own and is revoked by signing out.
        'token_ttl_minutes' => env('AUTH_TOKEN_TTL_MINUTES') !== null
            ? (int) env('AUTH_TOKEN_TTL_MINUTES')
            : 720,

        // Name given to the token issued at sign-in.
        'token_name' => env('AUTH_TOKEN_NAME', 'api'),

        /*
        | The identity provider is an extension point, not a fixed choice
        | (FR-08, third criterion). The default implementation verifies the
        | password stored in `users`; a university SSO is a second class
        | behind the same interface, bound here without touching the service
        | that consumes it.
        */
        'identity_provider' => env(
            'AUTH_IDENTITY_PROVIDER',
            DatabaseIdentityProvider::class,
        ),

    ],

    /*
    |---------------------------------------------------------------------------
    | Audit log (FR-33, NFR-14)
    |---------------------------------------------------------------------------
    */

    'audit' => [

        // The database role the running application connects as. UPDATE and
        // DELETE on the append-only logs are revoked from it by migration
        // (§4.4.4), which is why the migration itself must connect as a
        // different role.
        'application_db_role' => env('DB_APPLICATION_ROLE', 'app_rw'),

        // Page size of the administrator's view of the log.
        'page_size' => (int) env('AUDIT_PAGE_SIZE', 50),

    ],

];
