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
