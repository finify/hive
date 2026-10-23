<?php

use App\Models\LeaveRequest;
use App\Models\TimesheetEntry;
use App\Services\Approvals\Rules\LeaveRequestRule;
use App\Services\Approvals\Rules\TimesheetEntryRule;

return [

    /*
    |--------------------------------------------------------------------------
    | Display
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC and shown in this timezone. Event dates are
    | plain date columns and are never shifted.
    |
    */

    'display_timezone' => env('HIVE_DISPLAY_TIMEZONE', 'Europe/London'),

    'base_currency' => 'GBP',

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    'invitation_days' => 7,

    // Roles that must have two-factor authentication confirmed before using Hive.
    'two_factor_required_roles' => ['super-admin', 'operations-director', 'finance', 'hr'],

    // Turned off only for the demo walkthrough so personas can switch quickly.
    'enforce_two_factor' => (bool) env('HIVE_ENFORCE_TWO_FACTOR', true),

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the sign-in page lists the demo personas with their
    | credentials prefilled and the screens read fictional data from the JSON
    | files in database/demo. Never enable this in production.
    |
    */

    'demo' => [
        'enabled' => (bool) env('HIVE_DEMO', false),
        'path' => database_path('demo'),
        'password' => 'password',
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | What happens when someone with an expired mandatory document (insurance,
    | right to work record) is booked: 'warn' shows a warning, 'block' also
    | stops the booking being confirmed.
    |
    */

    'documents' => [
        'expired_mandatory' => env('HIVE_EXPIRED_DOCUMENTS', 'block'),
        'reminder_days' => [30, 7],
    ],

    /*
    |--------------------------------------------------------------------------
    | Finance thresholds
    |--------------------------------------------------------------------------
    */

    'health' => [
        // Forecast above this share of budget moves an event to Watch.
        'watch_forecast_ratio' => 0.90,
        // Margin below this percentage moves an event to Watch.
        'target_margin_percent' => 18,
    ],

    'invoice_tolerance' => [
        'percent' => 5,
        'amount_minor' => 25_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Working time reference values (UK Working Time Regulations)
    |--------------------------------------------------------------------------
    */

    'working_time' => [
        'min_rest_hours' => 11,
        'long_shift_hours' => 12,
        'break_after_hours' => 6,
        'break_minutes' => 20,
        'weekly_average_hours' => 48,
        'reference_weeks' => 17,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ask Hive
    |--------------------------------------------------------------------------
    */

    'ai' => [
        'enabled' => (bool) env('ASK_HIVE_ENABLED', true),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'hourly_limit' => (int) env('ASK_HIVE_HOURLY_LIMIT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals engine
    |--------------------------------------------------------------------------
    |
    | Who approves each kind of thing (section 5.9). Purchase orders, supplier
    | invoices and expenses join in phase 5. Approvers with items older than
    | remind_after_days are reminded on Monday mornings.
    |
    */

    'approvals' => [
        'rules' => [
            TimesheetEntry::class => TimesheetEntryRule::class,
            LeaveRequest::class => LeaveRequestRule::class,
        ],
        'remind_after_days' => 3,
    ],

];
