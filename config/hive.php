<?php

use App\Models\Expense;
use App\Models\FreelancerInvoice;
use App\Models\LeaveRequest;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\TimesheetEntry;
use App\Services\Approvals\Rules\ExpenseRule;
use App\Services\Approvals\Rules\FreelancerInvoiceRule;
use App\Services\Approvals\Rules\LeaveRequestRule;
use App\Services\Approvals\Rules\PurchaseOrderRule;
use App\Services\Approvals\Rules\SupplierInvoiceRule;
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
        // "claude" calls the Anthropic API; "local" is the deterministic engine
        // used by the tests and by the demo when there is no API key.
        'engine' => env('ASK_HIVE_ENGINE') ?: (env('ANTHROPIC_API_KEY') ? 'claude' : 'local'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        // Thinking depth: low, medium, high, xhigh or max.
        'effort' => env('ASK_HIVE_EFFORT', 'medium'),
        'max_tokens' => (int) env('ASK_HIVE_MAX_TOKENS', 8000),
        'timeout' => (float) env('ASK_HIVE_TIMEOUT', 120),
        'hourly_limit' => (int) env('ASK_HIVE_HOURLY_LIMIT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave
    |--------------------------------------------------------------------------
    |
    | The leave year starts on year_start (month-day). Up to carry_over_max
    | unused days of annual leave move into the next year. TOIL is earned
    | from approved event time on the days listed in toil.accrue_on, at
    | toil.hours_per_day hours to a day, by employees only.
    |
    */

    'leave' => [
        'year_start' => env('HIVE_LEAVE_YEAR_START', '01-01'),
        'default_allowance' => 25,
        'carry_over_max' => 5,
        'toil' => [
            'accrue_on' => ['weekend', 'bank_holiday'],
            'hours_per_day' => 7.5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (UK GDPR, section 5.14)
    |--------------------------------------------------------------------------
    |
    | How long records are kept before FlagRecordsPastRetention puts them up
    | for review on the first of each month. Financial records six years.
    |
    */

    'retention' => [
        'timesheets_years' => 6,
        'expenses_years' => 6,
        'leavers_years' => 6,
        'expired_documents_years' => 2,
        'ai_requests_months' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations (section 5.13)
    |--------------------------------------------------------------------------
    |
    | Each one is off until its credentials are set. Xero uses a custom
    | connection (client credentials); approved supplier invoices are pushed
    | as bills and their payment status pulled back. Entra ID signs staff in
    | with OpenID Connect; Hive still decides who has an account.
    |
    */

    'integrations' => [
        'xero' => [
            'client_id' => env('XERO_CLIENT_ID'),
            'client_secret' => env('XERO_CLIENT_SECRET'),
            'tenant_id' => env('XERO_TENANT_ID'),
            // Cost category => Xero account code.
            'accounts' => [
                'venue' => '5000', 'production' => '5010', 'av' => '5020', 'creative' => '5030', 'print' => '5040', 'catering' => '5050',
                'crew' => '5100', 'travel' => '5200', 'accommodation' => '5210', 'freight' => '5300', 'registration' => '5400',
                'contingency' => '5900', 'agency-fee' => '5950',
            ],
            'default_account' => '5900',
        ],
        'entra' => [
            'tenant_id' => env('ENTRA_TENANT_ID'),
            'client_id' => env('ENTRA_CLIENT_ID'),
            'client_secret' => env('ENTRA_CLIENT_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals engine
    |--------------------------------------------------------------------------
    |
    | Who approves each kind of thing (section 5.9). Approvers with items
    | older than remind_after_days are reminded on Monday mornings.
    |
    */

    'approvals' => [
        'rules' => [
            TimesheetEntry::class => TimesheetEntryRule::class,
            LeaveRequest::class => LeaveRequestRule::class,
            PurchaseOrder::class => PurchaseOrderRule::class,
            SupplierInvoice::class => SupplierInvoiceRule::class,
            Expense::class => ExpenseRule::class,
            FreelancerInvoice::class => FreelancerInvoiceRule::class,
        ],
        'remind_after_days' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Finance
    |--------------------------------------------------------------------------
    |
    | PO approval limits by level, in pence, against the net amount in GBP.
    | Above the operations director's limit a PO needs the operations director
    | and then the head of finance. Mileage is paid per mile (HMRC's 45p by
    | default). Self-billing lets Hive raise a freelancer's invoice from
    | approved time when they have a self-billing agreement on file.
    |
    */

    'finance' => [
        'po_limits' => [
            'producer' => (int) env('HIVE_PO_LIMIT_PRODUCER', 500_000),
            'operations-director' => (int) env('HIVE_PO_LIMIT_OPERATIONS', 5_000_000),
        ],
        'mileage_rate_minor' => (int) env('HIVE_MILEAGE_RATE', 45),
        'self_billing' => (bool) env('HIVE_SELF_BILLING', true),
        'currencies' => ['GBP', 'EUR', 'USD'],
    ],

];
