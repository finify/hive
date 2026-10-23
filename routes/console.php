<?php

use App\Jobs\SendDocumentExpiryReminders;
use App\Jobs\SendWeeklyRotaDigest;
use Illuminate\Support\Facades\Schedule;

/*
| Section 9 of the specification. Times are UK local time. Jobs for later
| phases are added as their modules move to the database.
*/

Schedule::job(new SendWeeklyRotaDigest)->weeklyOn(1, '08:00')->timezone('Europe/London')->name('weekly-rota-digest');
Schedule::job(new SendDocumentExpiryReminders)->dailyAt('07:00')->timezone('Europe/London')->name('document-expiry-reminders');

// The demo's events and bookings are shared by every visitor; put them back each hour.
Schedule::command('hive:demo-refresh')->hourly()
    ->when(fn () => (bool) config('hive.demo.enabled') && ! app()->isProduction())
    ->name('demo-refresh');
