<?php

use App\Enums\ConsentDocument;
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

        // The same for `POST /auth/password`, counted separately. Sharing the
        // ceiling above meant a handful of guesses at a one-time code shut the
        // sign-in route for every device behind the same address, and a
        // dormitory is one address. The two roads are defended apart so that
        // an attack on one cannot close the other.
        'password_requests_per_minute' => (int) env('AUTH_PASSWORD_REQUESTS_PER_MINUTE', 10),

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

    /*
    |---------------------------------------------------------------------------
    | Notifications (FR-34, NFR-02)
    |---------------------------------------------------------------------------
    */

    'notifications' => [

        // Page size of the personal account's list of messages.
        'page_size' => (int) env('NOTIFICATIONS_PAGE_SIZE', 25),

        // NFR-02, in seconds: the budget between the event and the delivery
        // being **queued**. It is not a timeout the code enforces — nothing
        // here waits on anything — it is the figure the test measures against,
        // and it is configuration so that the requirement and the assertion
        // cannot drift apart.
        'enqueue_budget_seconds' => (int) env('NOTIFICATIONS_ENQUEUE_BUDGET_SECONDS', 5),

    ],

    /*
    |---------------------------------------------------------------------------
    | Consent to the processing of personal data (FR-35)
    |---------------------------------------------------------------------------
    |
    | Publishing a new consent text is two steps and no code: add the file
    | under the path below, then name it here. Everyone whose last consent
    | carries the old revision becomes pending again the moment the value
    | changes, which is what a changed text has to mean — a person agreed to
    | wording, not to a document code.
    |
    */

    'consent' => [

        'revisions' => [
            ConsentDocument::ResidentPersonalData->value => env('CONSENT_REVISION_RESIDENT', '2026-09-01'),
            ConsentDocument::GuestPersonalData->value => env('CONSENT_REVISION_GUEST', '2026-09-01'),
        ],

        // Where the revisions themselves live. Under version control, because
        // art. 9 part 3 of Federal Law No. 152-FZ puts on the operator the
        // burden of proving what was agreed to.
        'path' => resource_path('consent'),

    ],

    /*
    |---------------------------------------------------------------------------
    | Guest requests and the security post (FR-16 … FR-23)
    |---------------------------------------------------------------------------
    |
    | The visiting window, the control time and the lead time are **not** here.
    | They live on the BUILDING row, because NFR-09 makes them per-building and
    | Table 1.1 shows the sector does not agree on any of them; a value in this
    | file would be this deployment's regime imposed on every dormitory it
    | serves. What is here is what the system as a whole decides.
    |
    */

    'guests' => [

        /*
        | The daily ceilings §3.3.4 has `approve()` assert. No norm sets them —
        | the HSE rules of internal order fix the hours and say nothing about
        | numbers — so they are a house rule, and a house rule belongs in
        | configuration with a stated default rather than in a service as a
        | literal nobody can find.
        |
        | Per resident: how many guests one resident may have approved for one
        | day. Per building: how many the dormitory admits in a day at all,
        | which is the figure a security post can actually work through.
        */
        'daily_quota_per_resident' => (int) env('GUESTS_DAILY_QUOTA_PER_RESIDENT', 2),

        'daily_quota_per_building' => (int) env('GUESTS_DAILY_QUOTA_PER_BUILDING', 60),

        /*
        | FR-23, first criterion: «attaches the warning about the procedure in
        | force at the university».
        |
        | The text is configuration because the procedure is the university's
        | and not the program's, and because §2.7.4 leaves open a question the
        | legal service has to answer — whether a dormitory counts as an
        | accommodation facility under art. 20 part 3 of Federal Law
        | No. 109-FZ, which is the difference between one working day and
        | seven. Both deadlines are named and neither is asserted.
        */
        'foreign_document_warning' => env('GUESTS_FOREIGN_WARNING', <<<'TEXT'
            This guest presents a foreign document. A visit that ends the same day creates no place of
            stay: art. 2 cl. 4 of Federal Law No. 109-FZ requires premises the person regularly uses
            for sleep and rest. An overnight stay is a different matter — the arrival notification is
            the receiving party's duty under art. 20 part 2, within seven working days, or one working
            day if the dormitory falls within the class of accommodation facilities of art. 20 part 3.
            The system does not submit that notification. Before approving an interval that runs past
            midnight, obtain the mark of the officer responsible for migration registration.
            TEXT),

        /*
        | FR-21. The page a period export is read in. The register of a
        | dormitory over an academic year is tens of thousands of rows, and a
        | client that asked for all of them at once would get a timeout instead
        | of an answer.
        */
        'register_page_size' => (int) env('GUESTS_REGISTER_PAGE_SIZE', 100),

    ],

];
