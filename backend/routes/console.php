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
