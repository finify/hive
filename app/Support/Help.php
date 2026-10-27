<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The staff guide: one page per part of Hive, written in resources/help, with
 * a one-line summary that appears as the tooltip on the sidebar and the "?"
 * next to each page title. A guide is listed for someone when they can use
 * at least one of its permissions (or it has none).
 */
final class Help
{
    /**
     * key => title, group, one-line summary, the routes it explains, and the
     * permissions that make it relevant.
     *
     * @var array<string, array{title: string, group: string, summary: string, routes: list<string>, can?: list<string>}>
     */
    public const GUIDES = [
        'getting-started' => ['group' => 'Start here', 'title' => 'Getting started', 'summary' => 'What Hive is for, how it is laid out, and the few rules everything follows.', 'routes' => []],
        'ask-hive' => ['group' => 'Start here', 'title' => 'Ask Hive', 'summary' => 'Ask questions about events, crew, time, leave and money in plain English, answered from records you can see.', 'routes' => [], 'can' => ['ai.use']],
        'security' => ['group' => 'Start here', 'title' => 'Signing in and two-factor', 'summary' => 'Signing in, Microsoft sign-in, two-factor codes and recovery codes.', 'routes' => ['security']],

        'dashboard' => ['group' => 'Home', 'title' => 'Dashboard', 'summary' => 'Your role’s key figures and a “Needs attention” list, most urgent first.', 'routes' => ['dashboard']],
        'my-work' => ['group' => 'Home', 'title' => 'My work', 'summary' => 'Your bookings, booking requests to answer, travel, documents and dates you can’t work.', 'routes' => ['my-work']],
        'notifications' => ['group' => 'Home', 'title' => 'Notifications and calendar', 'summary' => 'The bell, which notices also come by email, the daily digest, and your private calendar feed.', 'routes' => ['notifications', 'notification-settings']],

        'events' => ['group' => 'Events', 'title' => 'Events', 'summary' => 'Every event from enquiry to close: details, stages, tasks, files and the tabs on an event page.', 'routes' => ['events.index', 'events.show', 'events.create', 'events.edit'], 'can' => ['events.view']],
        'pipeline' => ['group' => 'Events', 'title' => 'Pipeline board', 'summary' => 'Events by stage, from enquiry to confirmed, with their value.', 'routes' => ['events.pipeline'], 'can' => ['events.view_all']],
        'calendar' => ['group' => 'Events', 'title' => 'Calendar', 'summary' => 'Events and their phases (travel, build, live, breakdown) by month.', 'routes' => ['events.calendar'], 'can' => ['events.view']],
        'travel' => ['group' => 'Events', 'title' => 'Travel and accommodation', 'summary' => 'Flights, trains, transfers and hotels per person on an event, the manifest, and how costs reach the budget.', 'routes' => [], 'can' => ['crew.manage', 'rota.view_all']],

        'rota' => ['group' => 'Resourcing', 'title' => 'Rota', 'summary' => 'Who is booked, held, on leave or unavailable, over two weeks or a month.', 'routes' => ['rota'], 'can' => ['rota.view']],
        'crew-calls' => ['group' => 'Resourcing', 'title' => 'Crew calls and booking', 'summary' => 'The roles an event needs, booking people onto them, holds, confirmations and call sheets.', 'routes' => ['crew-calls'], 'can' => ['crew.view', 'crew.respond']],
        'availability' => ['group' => 'Resourcing', 'title' => 'Availability', 'summary' => 'Find who is free for a role and dates, ranked by skill and rating.', 'routes' => ['availability'], 'can' => ['crew.manage']],
        'clashes' => ['group' => 'Resourcing', 'title' => 'Clashes', 'summary' => 'Double bookings, and how to resolve or accept them.', 'routes' => ['clashes'], 'can' => ['rota.view_all']],

        'timesheets' => ['group' => 'Time', 'title' => 'Timesheets', 'summary' => 'Recording your time each week, submitting it, and what happens if an entry is returned.', 'routes' => ['timesheets'], 'can' => ['timesheets.submit']],
        'approvals' => ['group' => 'Time', 'title' => 'Approvals', 'summary' => 'Everything waiting for your decision in one place, and covering for someone while they’re away.', 'routes' => ['approvals'], 'can' => ['timesheets.approve', 'supplier_invoices.approve', 'leave.approve', 'po.approve', 'expenses.approve', 'freelancer_invoices.approve']],
        'payroll-locks' => ['group' => 'Time', 'title' => 'Payroll locks', 'summary' => 'Locking time once payroll has run, and unlocking it with a reason.', 'routes' => ['period-locks'], 'can' => ['timesheets.lock']],

        'budgets' => ['group' => 'Finance', 'title' => 'Budgets and event finances', 'summary' => 'Budget lines, committed and actual cost, forecast, margin, health and budget versions.', 'routes' => ['budgets'], 'can' => ['budgets.view']],
        'purchase-orders' => ['group' => 'Finance', 'title' => 'Purchase orders', 'summary' => 'Raising, approving and sending POs, and the limits that decide who approves.', 'routes' => ['purchase-orders', 'purchase-orders.*'], 'can' => ['po.view']],
        'supplier-invoices' => ['group' => 'Finance', 'title' => 'Supplier invoices', 'summary' => 'Recording invoices, matching them to POs, tolerance, disputes and payment.', 'routes' => ['supplier-invoices'], 'can' => ['supplier_invoices.view']],
        'freelancer-invoices' => ['group' => 'Finance', 'title' => 'Freelancer invoices', 'summary' => 'Invoices for approved freelance time, uploaded or self-billed.', 'routes' => ['freelancer-invoices'], 'can' => ['freelancer_invoices.view', 'freelancer_invoices.submit']],
        'expenses' => ['group' => 'Finance', 'title' => 'Expenses', 'summary' => 'Claiming expenses and mileage with receipts, and how they’re approved.', 'routes' => ['expenses'], 'can' => ['expenses.submit', 'expenses.view_all', 'expenses.approve']],
        'billing' => ['group' => 'Finance', 'title' => 'Client billing and exports', 'summary' => 'Billing milestones, marking them invoiced and paid, and the finance CSV exports.', 'routes' => ['billing', 'exports'], 'can' => ['billing.view']],

        'people' => ['group' => 'People', 'title' => 'People and the directory', 'summary' => 'Staff and freelancer records, profiles, rates, restricted details and who sees what.', 'routes' => ['people.index', 'people.show', 'people.create', 'people.edit'], 'can' => ['people.view', 'people.view_basic']],
        'leave' => ['group' => 'People', 'title' => 'Leave', 'summary' => 'Asking for leave, balances and carry-over, time off in lieu, bank holidays and recording absence.', 'routes' => ['leave'], 'can' => ['leave.request', 'leave.view_calendar']],
        'onboarding' => ['group' => 'People', 'title' => 'Onboarding and offboarding', 'summary' => 'Checklists for starters and leavers, with an owner and due date for each item.', 'routes' => ['onboarding'], 'can' => ['people.manage', 'leave.approve', 'rates.edit', 'users.manage']],
        'documents' => ['group' => 'People', 'title' => 'Documents', 'summary' => 'Insurance, certificates and contracts, expiry reminders, and why an expired one blocks bookings.', 'routes' => ['documents'], 'can' => ['documents.view', 'documents.view_status']],
        'skills' => ['group' => 'People', 'title' => 'Skills', 'summary' => 'The skills catalogue, levels 1 to 5, and verification.', 'routes' => ['skills'], 'can' => ['people.view_basic']],
        'directory' => ['group' => 'People', 'title' => 'Clients, suppliers and venues', 'summary' => 'The organisations Hive works with, their contacts, ratings and venue notes.', 'routes' => ['clients', 'clients.*', 'suppliers', 'suppliers.*', 'venues', 'venues.*'], 'can' => ['directory.view']],

        'reports' => ['group' => 'Reports', 'title' => 'Reports', 'summary' => 'The eight reports, their filters, what each figure means, and downloading CSV.', 'routes' => ['reports', 'reports.*'], 'can' => ['reports.view']],

        'users' => ['group' => 'Admin', 'title' => 'Users and roles', 'summary' => 'Inviting people, roles and what each can do, and deactivating accounts.', 'routes' => ['admin.users'], 'can' => ['users.manage']],
        'settings' => ['group' => 'Admin', 'title' => 'Settings', 'summary' => 'Approval limits, thresholds, working time, leave, Ask Hive and retention periods.', 'routes' => ['settings'], 'can' => ['settings.view']],
        'integrations' => ['group' => 'Admin', 'title' => 'Integrations and import', 'summary' => 'Microsoft sign-in, Xero, webhooks, calendar feeds and importing from CSV.', 'routes' => ['integrations', 'import'], 'can' => ['settings.view', 'people.manage', 'directory.manage']],
        'queue' => ['group' => 'Admin', 'title' => 'Queue', 'summary' => 'Background jobs that failed, and when to retry or clear them.', 'routes' => ['queue'], 'can' => ['settings.view']],
        'data-protection' => ['group' => 'Admin', 'title' => 'Data protection', 'summary' => 'Subject access exports, anonymising leavers and reviewing records past retention.', 'routes' => ['retention'], 'can' => ['people.manage']],
        'audit' => ['group' => 'Admin', 'title' => 'Audit log', 'summary' => 'Who changed what and when, filtering it and exporting it.', 'routes' => ['admin.audit'], 'can' => ['audit.view']],
    ];

    /**
     * Guides relevant to this user, keyed, in order.
     *
     * @return array<string, array{title: string, group: string, summary: string, routes: list<string>, can?: list<string>}>
     */
    public static function for(User $user): array
    {
        return array_filter(self::GUIDES, fn (array $g) => ($g['can'] ?? []) === [] || $user->canAny($g['can']));
    }

    /**
     * The guide that explains a route, if any.
     */
    public static function forRoute(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        foreach (self::GUIDES as $key => $guide) {
            foreach ($guide['routes'] as $pattern) {
                if (Str::is($pattern, $route)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public static function body(string $key): string
    {
        $path = resource_path("help/{$key}.md");

        return is_file($path)
            ? Str::markdown((string) file_get_contents($path), ['html_input' => 'escape', 'allow_unsafe_links' => false])
            : '';
    }

    /**
     * Plain text of a guide, for search.
     */
    public static function text(string $key): string
    {
        $path = resource_path("help/{$key}.md");

        return is_file($path) ? mb_strtolower((string) file_get_contents($path)) : '';
    }
}
