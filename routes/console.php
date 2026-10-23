<?php

use App\Jobs\SendDocumentExpiryReminders;
use Illuminate\Support\Facades\Schedule;

/*
| Section 9 of the specification. Times are UK local time. Jobs for later
| phases are added as their modules move to the database.
*/

Schedule::job(new SendDocumentExpiryReminders)->dailyAt('07:00')->timezone('Europe/London')->name('document-expiry-reminders');

// The demo's events are shared by every visitor; put them back each night.
Schedule::command('hive:demo-refresh')->dailyAt('03:00')->timezone('Europe/London')
    ->when(fn () => (bool) config('hive.demo.enabled') && ! app()->isProduction())
    ->name('demo-refresh');
