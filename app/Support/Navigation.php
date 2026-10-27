<?php

namespace App\Support;

use App\Models\User;

/**
 * The sidebar, grouped as in section 4 of the specification. An item is shown
 * only when the user holds one of its permissions. Items whose module lands in
 * a later build phase link to a page that says which phase.
 */
final class Navigation
{
    /**
     * @return list<array{label: string, items: list<array{label: string, icon: string, route: string, params: array<string, string>, active: string, planned: bool}>}>
     */
    public static function for(User $user): array
    {
        $groups = [];

        foreach (self::definition() as $group => $items) {
            $visible = [];

            foreach ($items as $item) {
                $permissions = $item['can'] ?? [];

                if ($permissions !== [] && ! $user->canAny($permissions)) {
                    continue;
                }

                $visible[] = [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'route' => $item['route'] ?? 'planned',
                    'params' => isset($item['route']) ? [] : ['module' => $item['planned']],
                    'active' => $item['active'] ?? ($item['route'] ?? 'planned'),
                    'planned' => ! isset($item['route']),
                ];
            }

            if ($visible !== []) {
                $groups[] = ['label' => $group, 'items' => $visible];
            }
        }

        return $groups;
    }

    /**
     * Modules that arrive in a later build phase.
     *
     * @return array<string, array{title: string, phase: int, summary: string}>
     */
    public static function planned(): array
    {
        return [
            'skills' => ['title' => 'Skills', 'phase' => 6, 'summary' => 'The skills catalogue, with levels from 1 to 5 and who verified them.'],
            'reports' => ['title' => 'Reports', 'phase' => 6, 'summary' => 'Event profitability, utilisation, freelancer and supplier spend, pipeline and win rate, leave, timesheet compliance and working time flags, each exportable to CSV.'],
            'settings' => ['title' => 'Settings', 'phase' => 8, 'summary' => 'Organisation details, approval limits, thresholds, leave years and Ask Hive controls.'],
            'integrations' => ['title' => 'Integrations', 'phase' => 8, 'summary' => 'Microsoft Entra ID sign-in, Xero, Teams or Slack, calendar feeds and outbound webhooks.'],
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private static function definition(): array
    {
        return [
            'Home' => [
                ['label' => 'Dashboard', 'icon' => 'grid-1x2', 'route' => 'dashboard'],
                ['label' => 'My work', 'icon' => 'person-workspace', 'route' => 'my-work'],
                ['label' => 'Notifications', 'icon' => 'bell', 'route' => 'notifications'],
            ],
            'Events' => [
                ['label' => 'Events', 'icon' => 'calendar-event', 'route' => 'events.index', 'active' => 'events.index', 'can' => ['events.view']],
                ['label' => 'Pipeline board', 'icon' => 'kanban', 'route' => 'events.pipeline', 'can' => ['events.view_all']],
                ['label' => 'Calendar', 'icon' => 'calendar3', 'route' => 'events.calendar', 'can' => ['events.view']],
            ],
            'Resourcing' => [
                ['label' => 'Rota', 'icon' => 'bar-chart-steps', 'route' => 'rota', 'can' => ['rota.view']],
                ['label' => 'Crew calls', 'icon' => 'megaphone', 'route' => 'crew-calls', 'can' => ['crew.view']],
                ['label' => 'Availability', 'icon' => 'person-check', 'route' => 'availability', 'can' => ['crew.manage']],
                ['label' => 'Clashes', 'icon' => 'exclamation-diamond', 'route' => 'clashes', 'can' => ['rota.view_all']],
            ],
            'Time' => [
                ['label' => 'My timesheets', 'icon' => 'clock', 'route' => 'timesheets', 'can' => ['timesheets.submit']],
                ['label' => 'Approvals', 'icon' => 'check2-square', 'route' => 'approvals', 'can' => ['timesheets.approve', 'supplier_invoices.approve', 'leave.approve', 'po.approve', 'expenses.approve', 'freelancer_invoices.approve']],
                ['label' => 'Payroll locks', 'icon' => 'lock', 'route' => 'period-locks', 'can' => ['timesheets.lock']],
            ],
            'Finance' => [
                ['label' => 'Budgets', 'icon' => 'pie-chart', 'route' => 'budgets', 'can' => ['budgets.view']],
                ['label' => 'Purchase orders', 'icon' => 'receipt', 'route' => 'purchase-orders', 'can' => ['po.view']],
                ['label' => 'Supplier invoices', 'icon' => 'file-earmark-text', 'route' => 'supplier-invoices', 'can' => ['supplier_invoices.view']],
                ['label' => 'Freelancer invoices', 'icon' => 'file-earmark-person', 'route' => 'freelancer-invoices', 'can' => ['freelancer_invoices.view', 'freelancer_invoices.submit']],
                ['label' => 'Expenses', 'icon' => 'wallet2', 'route' => 'expenses', 'can' => ['expenses.view_all', 'expenses.submit', 'expenses.approve']],
                ['label' => 'Client billing', 'icon' => 'cash-stack', 'route' => 'billing', 'can' => ['billing.view']],
                ['label' => 'Exports', 'icon' => 'download', 'route' => 'exports', 'can' => ['billing.view']],
            ],
            'People' => [
                ['label' => 'Directory', 'icon' => 'people', 'route' => 'people.index', 'active' => 'people.*', 'can' => ['people.view', 'people.view_basic']],
                ['label' => 'Leave', 'icon' => 'sun', 'route' => 'leave', 'can' => ['leave.view_calendar', 'leave.request']],
                ['label' => 'Onboarding', 'icon' => 'list-check', 'route' => 'onboarding', 'can' => ['people.manage', 'leave.approve', 'rates.edit', 'users.manage']],
                ['label' => 'Documents', 'icon' => 'folder2', 'route' => 'documents', 'can' => ['documents.view', 'documents.view_status']],
                ['label' => 'Skills', 'icon' => 'stars', 'planned' => 'skills', 'can' => ['people.view_basic']],
            ],
            'Directory' => [
                ['label' => 'Clients', 'icon' => 'building', 'route' => 'clients', 'can' => ['directory.view']],
                ['label' => 'Suppliers', 'icon' => 'truck', 'route' => 'suppliers', 'can' => ['directory.view']],
                ['label' => 'Venues', 'icon' => 'geo-alt', 'route' => 'venues', 'can' => ['directory.view']],
            ],
            'Reports' => [
                ['label' => 'Reports', 'icon' => 'graph-up', 'planned' => 'reports', 'can' => ['reports.view']],
            ],
            'Admin' => [
                ['label' => 'Users and roles', 'icon' => 'shield-lock', 'route' => 'admin.users', 'can' => ['users.manage']],
                ['label' => 'Settings', 'icon' => 'gear', 'planned' => 'settings', 'can' => ['settings.view']],
                ['label' => 'Integrations', 'icon' => 'plug', 'planned' => 'integrations', 'can' => ['settings.manage']],
                ['label' => 'Audit log', 'icon' => 'journal-text', 'route' => 'admin.audit', 'can' => ['audit.view']],
            ],
        ];
    }
}
