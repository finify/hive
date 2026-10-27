<?php

use App\Jobs\FlagRecordsPastRetention;
use App\Jobs\RemindApproversOfPendingItems;
use App\Jobs\RemindMissingTimesheets;
use App\Jobs\SendDailyDigest;
use App\Jobs\SendDocumentExpiryReminders;
use App\Jobs\SendWeeklyRotaDigest;
use Illuminate\Support\Facades\Schedule;

/*
| Section 9 of the specification. Times are UK local time. Jobs for later
| phases are added as their modules move to the database.
*/

Schedule::job(new RemindMissingTimesheets)->weeklyOn(5, '14:00')->timezone('Europe/London')->name('remind-missing-timesheets');
Schedule::job(new RemindApproversOfPendingItems)->weeklyOn(1, '09:00')->timezone('Europe/London')->name('remind-approvers');
Schedule::job(new SendWeeklyRotaDigest)->weeklyOn(1, '08:00')->timezone('Europe/London')->name('weekly-rota-digest');
Schedule::job(new SendDailyDigest)->dailyAt('08:00')->timezone('Europe/London')->name('daily-digest');
Schedule::job(new SendDocumentExpiryReminders)->dailyAt('07:00')->timezone('Europe/London')->name('document-expiry-reminders');

// Bills to Xero and payments back, once it is connected.
Schedule::command('hive:accounting-sync')->hourlyAt(17)->withoutOverlapping()->name('accounting-sync')
    ->when(fn () => filled(config('hive.integrations.xero.client_id')));

// UK GDPR: records past retention go up for review.
Schedule::job(new FlagRecordsPastRetention)->monthlyOn(1, '06:00')->timezone('Europe/London')->name('flag-retention');

// Opens the new leave year with carry-over, on the day it starts.
[$leaveMonth, $leaveDay] = array_map('intval', explode('-', (string) config('hive.leave.year_start')));
Schedule::command('hive:leave-year')->yearlyOn($leaveMonth, $leaveDay, '01:00')->timezone('Europe/London')->name('leave-year');

// The demo's events and bookings are shared by every visitor; put them back each hour.
Schedule::command('hive:demo-refresh')->hourly()
    ->when(fn () => (bool) config('hive.demo.enabled') && ! app()->isProduction())
    ->name('demo-refresh');
