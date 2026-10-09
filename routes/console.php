<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:release-holds')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('calendars:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('gdpr:process-deletions')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('billing:run')->hourly()->withoutOverlapping();
Schedule::command('accounts:end-pauses')->hourly()->withoutOverlapping();
Schedule::command('whatsapp:sync-templates')->hourly()->withoutOverlapping();
Schedule::command('pricelists:purge-files')->hourly()->withoutOverlapping();
Schedule::command('loyalty:maintain')->dailyAt('06:00')->withoutOverlapping();
