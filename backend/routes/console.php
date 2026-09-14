<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * FR-05. A termination recorded for a future date comes due at midnight, and
 * the register has to say so without waiting for somebody to open a screen.
 * Five past midnight rather than midnight itself, so that a date comparison
 * never runs on the boundary it is comparing against, and without overlapping,
 * because a slow night must not start a second pass over the same rows.
 */
Schedule::command('housing:settle-residencies')
    ->dailyAt('00:05')
    ->withoutOverlapping();

/*
 * FR-42. A one-time code lives an hour and leaves a hashed row behind it in
 * `password_reset_tokens` — spent or not. Nothing was sweeping that table: the
 * framework ships the command and the schedule simply never named it, so every
 * account ever issued kept a row for the life of the deployment. A hash of an
 * expired secret is not a secret, but it is a list of addresses that hold an
 * account, which is the same thing the sign-in route is careful never to
 * answer.
 *
 * Ten past midnight, behind the residency pass, so that the two nightly jobs do
 * not contend for the same connection.
 */
Schedule::command('auth:clear-resets')
    ->dailyAt('00:10')
    ->withoutOverlapping();

/*
 * FR-20. The control time is `BUILDING.curfew_at` and not a constant, so this
 * cannot be a cron entry at 23:00: a single nightly run would bake this
 * university's hour into the deployment and leave the column editable but
 * inert, breaking NFR-09 for a dormitory that closes at 22:00. The sweep runs
 * quarter-hourly instead and asks each building whether its own hour has come.
 *
 * Overlapping is refused for the usual reason and one more: two passes over
 * the same visit would race on `overdue_notified_at`. They would not both send
 * — the column is written once and the database refuses to overwrite it — but
 * the second pass would spend its run discovering that.
 */
Schedule::command('guests:sweep-overdue-visits')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/*
 * FR-17, fourth criterion, and §3.5.4's `Approved → Expired`. Quarter-hourly
 * as well, because «not processed by the start of the visit» is a moment
 * during the day: a nightly pass would tell a resident at midnight that nobody
 * had decided at two in the afternoon.
 *
 * Five minutes past the quarter, behind the sweep above, so that the two guest
 * jobs do not contend for the same connection — the same courtesy the two
 * nightly jobs show each other.
 */
Schedule::command('guests:expire-stale-requests')
    ->cron('5,20,35,50 * * * *')
    ->withoutOverlapping();

/*
 * FR-39, second criterion. The confirmation window is measured in days, so a
 * quarter-hourly pass — the shape the guest module's two sweeps take — would
 * buy nothing and cost a full scan of the completed requests of every
 * dormitory ninety-six times a day. The guest sweeps run that often because
 * `BUILDING.curfew_at` is an hour of the day and the schedule must know none;
 * nothing here is measured against an hour.
 *
 * Twenty past midnight, behind the two jobs already there, so that the nightly
 * work does not contend for the same connection.
 */
Schedule::command('maintenance:auto-close-confirmed-work')
    ->dailyAt('00:20')
    ->withoutOverlapping();

/*
 * FR-40, fourth criterion, and §3.5.2's nightly digest. Half past midnight,
 * behind the closure above and deliberately after it: a request closed by that
 * pass is no longer open, so it must not appear in the same night's list of
 * overdue work. Run the other way round, the warden would be told to chase a
 * request the system closed five minutes later.
 */
Schedule::command('maintenance:scan-overdue')
    ->dailyAt('00:30')
    ->withoutOverlapping();
