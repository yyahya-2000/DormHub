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
    | Housing register (FR-01 … FR-03)
    |---------------------------------------------------------------------------
    |
    | A dormitory of several hundred rooms and as many residents is more than
    | one screen, so the room register and the roll of a building are read a
    | page at a time. `per_page` on the request overrides the default, up to the
    | ceiling below.
    |
    */

    'housing' => [

        'page_size' => (int) env('HOUSING_PAGE_SIZE', 20),

        'max_page_size' => (int) env('HOUSING_MAX_PAGE_SIZE', 100),

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
    | Announcements (FR-09, FR-11, FR-12)
    |---------------------------------------------------------------------------
    |
    | The validity period of an announcement is not here: it is a column, set
    | per notice by the person publishing it, because «until Friday» is a
    | property of the shutdown and not of the deployment. What is here is what
    | the system as a whole decides.
    |
    */

    'announcements' => [

        // Page size of the feed. A dormitory posts a few notices a week, so
        // the first page is normally the whole of what a resident wants; the
        // figure matters for the archive, which grows without bound.
        'page_size' => (int) env('ANNOUNCEMENTS_PAGE_SIZE', 20),

        // NFR-01, in seconds: «all other pages ≤ 3 s». The budget the feed is
        // spot-measured against in the test suite (§4.7.3 states this clause
        // is checked by spot measurement rather than by a load run). It is
        // configuration for the same reason NFR-02's budget is — so that the
        // requirement and the assertion cannot drift apart.
        'feed_budget_seconds' => (float) env('ANNOUNCEMENTS_FEED_BUDGET_SECONDS', 3),

    ],

    /*
    |---------------------------------------------------------------------------
    | Maintenance requests (FR-36 … FR-40)
    |---------------------------------------------------------------------------
    |
    | Three of the five requirements of this module state a number, and all
    | three numbers are here rather than in a service. FR-39 says the
    | confirmation window is «configurable»; FR-40 says in so many words that
    | «the overdue threshold is configuration, not code». FR-36's ceiling of
    | three photographs is the requirement's own figure and is stated here as
    | well, because the validator and the database CHECK both read it and a
    | literal in two places is a literal that will one day differ.
    |
    | What is deliberately *not* here: the categories, the urgencies and the
    | statuses. Those are the vocabulary FR-36 and FR-38 fix, they are enums
    | and CHECK constraints, and a deployment that could add a status through
    | configuration would be a deployment whose transition table describes
    | nothing.
    |
    */

    'maintenance' => [

        /*
        | FR-39: «a request not confirmed within the window closes
        | automatically». §3.5.2 draws seven days and calls the interval
        | configuration in the same breath, so seven is the default and not
        | the rule. The worked example of §2.4.3 runs on three, which is the
        | test that proves the value is read rather than compiled in.
        */
        'confirmation_window_days' => (int) env('MAINTENANCE_CONFIRMATION_WINDOW_DAYS', 7),

        /*
        | FR-40, fourth criterion. Days since submission after which an open
        | request is flagged overdue in the queue and in the warden's nightly
        | digest. A request whose planned completion date has already passed is
        | overdue whatever this says — that is a promise broken rather than a
        | standard missed — so the figure governs the requests nobody has
        | triaged at all, which is the backlog it exists to surface.
        */
        'overdue_after_days' => (int) env('MAINTENANCE_OVERDUE_AFTER_DAYS', 7),

        /*
        | FR-36: «up to three photographs». Read by the form request and by the
        | CHECK constraint through the migration, so the ceiling is stated once
        | and enforced twice.
        */
        'max_photos' => (int) env('MAINTENANCE_MAX_PHOTOS', 3),

        // The largest photograph the form accepts, in kilobytes. A phone
        // photograph of a leaking pipe is a few hundred; the ceiling is there
        // so that a client uploading an original from a camera is refused with
        // a sentence rather than with a timeout.
        'max_photo_kilobytes' => (int) env('MAINTENANCE_MAX_PHOTO_KILOBYTES', 5120),

        /*
        | Where the photographs go. The default is the deployment's default
        | disk, which the Compose environment points at the S3-compatible
        | store; the database holds paths and never the files themselves.
        */
        'photo_disk' => env('MAINTENANCE_PHOTO_DISK', env('FILESYSTEM_DISK', 'local')),

        // Directory inside that disk. A path rather than a bucket of its own,
        // so that a deployment with one bucket needs no further configuration.
        'photo_directory' => env('MAINTENANCE_PHOTO_DIRECTORY', 'maintenance'),

        /*
        | FR-40. The page the queue and the archive behind it are read in, and
        | the default when the client names none. A dormitory over an academic
        | year accumulates thousands of requests, and a client that asked for
        | all of them at once would get a timeout instead of an answer. A
        | client may ask for a different page, up to
        | `MaintenanceQueue::MAX_PAGE_SIZE`.
        */
        'queue_page_size' => (int) env('MAINTENANCE_QUEUE_PAGE_SIZE', 20),

    ],

    /*
    |---------------------------------------------------------------------------
    | Lost and found (FR-24, FR-25, FR-26)
    |---------------------------------------------------------------------------
    |
    | Four settings, and what is missing from them is the more interesting
    | half.
    |
    | There is **no moderation switch**. FR-24's fourth criterion — «publication
    | passes through no staff approval step» — is a property of the module and
    | not of the deployment: a flag that could switch a review queue on would
    | be a flag that makes the acceptance criterion false on some stands, and
    | §2.5.4 gives the reason the queue is refused everywhere rather than made
    | optional.
    |
    | There is **no retention period**. FR-27 puts the six-month clock of Civil
    | Code art. 228 cl. 1 outside the MVP as an automated control, so nothing
    | in this increment counts days and a number here would be one nothing
    | reads. The two dates it will one day be counted from are in the table all
    | the same, because those cannot be retrofitted (§2.5.4).
    |
    */

    'lost_found' => [

        /*
        | Where the photographs go. The lost-and-found module gets its own
        | disk and directory rather than sharing the maintenance module's,
        | because the two hold different things for different lengths of time:
        | a photograph of a burst pipe belongs to a repair record and a
        | photograph of somebody's umbrella belongs to a feed that turns over
        | in weeks. Sharing one directory would make «delete the finds of last
        | term» a query rather than a prefix.
        */
        'photo_disk' => env('LOST_FOUND_PHOTO_DISK', env('FILESYSTEM_DISK', 'local')),

        'photo_directory' => env('LOST_FOUND_PHOTO_DIRECTORY', 'lost-found'),

        /*
        | FR-24: «the photograph is optional», and there is at most one. The
        | ceiling is a size and not a count — a count of one is the column.
        | The figure is the maintenance module's, because it is a limit on what
        | a telephone camera produces rather than on what the picture is of.
        */
        'max_photo_kilobytes' => (int) env('LOST_FOUND_MAX_PHOTO_KILOBYTES', 5120),

        /*
        | FR-25. The page the feed is read in. A dormitory loses a few things a
        | week and the first page is normally the whole of what anybody wants;
        | the figure matters once a term's worth of unclaimed entries has piled
        | up behind it.
        */
        'feed_page_size' => (int) env('LOST_FOUND_FEED_PAGE_SIZE', 20),

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
        | The page a list of guest requests and the visitor register are read
        | in. The register of a dormitory over an academic year is tens of
        | thousands of rows, and a client that asked for all of them at once
        | would get a timeout instead of an answer. `per_page` on the request
        | overrides it, up to a hundred.
        */
        'page_size' => (int) env('GUESTS_PAGE_SIZE', 20),

        'max_page_size' => (int) env('GUESTS_MAX_PAGE_SIZE', 100),

    ],

];
