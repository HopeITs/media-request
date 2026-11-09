<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deadline reminders, once a working day in the morning (needs the server's cron: see DEPLOY.md).
Illuminate\Support\Facades\Schedule::command('media:remind')->dailyAt('08:30')->timezone('Asia/Damascus')->withoutOverlapping();

// A result waiting half a working day for the media manager's check: every half hour in working hours.
Illuminate\Support\Facades\Schedule::command('media:review-alert')->everyThirtyMinutes()->weekdays()->between('09:00', '17:30')
    ->timezone('Asia/Damascus')->withoutOverlapping();
