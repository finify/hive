<?php

/*
|--------------------------------------------------------------------------
| Roles and permissions
|--------------------------------------------------------------------------
|
| The source of truth for section 6 of the specification. Permissions are
| granular; ownership rules (on events they produce, their department, their
| own records) are enforced by policies on top of these permissions.
|
| Run `php artisan db:seed --class=RolesAndPermissionsSeeder` after editing.
|
*/

$all = [
    // Events
    'events.view', 'events.view_all', 'events.create', 'events.edit', 'events.edit_all', 'events.change_stage', 'events.reconcile',
    // Resourcing
    'crew.view', 'crew.manage', 'crew.manage_all', 'crew.respond', 'rota.view', 'rota.view_all',
    // Rates
    'rates.view', 'rates.edit',
    // Time
    'timesheets.submit', 'timesheets.view_all', 'timesheets.approve', 'timesheets.lock',
    // Finance
    'budgets.view', 'budgets.view_all', 'budgets.edit', 'budgets.approve',
    'po.view', 'po.create', 'po.approve',
    'supplier_invoices.view', 'supplier_invoices.edit', 'supplier_invoices.approve',
    'freelancer_invoices.view', 'freelancer_invoices.submit', 'freelancer_invoices.approve',
    'expenses.submit', 'expenses.view_all', 'expenses.approve',
    'billing.view',
    // People
    'people.view', 'people.view_basic', 'people.manage', 'people.view_restricted',
    'leave.request', 'leave.view_calendar', 'leave.view_all', 'leave.approve', 'leave.manage',
    'documents.view', 'documents.manage', 'documents.view_status',
    // Directory
    'directory.view', 'directory.manage',
    // Platform
    'reports.view', 'ai.use', 'settings.view', 'settings.manage', 'users.manage', 'audit.view',
];

return [

    'permissions' => $all,

    'roles' => [

        'super-admin' => $all,

        'operations-director' => [
            'events.view', 'events.view_all', 'events.create', 'events.edit', 'events.edit_all', 'events.change_stage', 'events.reconcile',
            'crew.view', 'crew.manage', 'crew.manage_all', 'rota.view', 'rota.view_all',
            'rates.view',
            'timesheets.view_all',
            'budgets.view', 'budgets.view_all', 'budgets.edit', 'budgets.approve',
            'po.view', 'po.approve',
            'supplier_invoices.view', 'supplier_invoices.approve',
            'freelancer_invoices.view',
            'expenses.view_all', 'expenses.approve',
            'billing.view',
            'people.view', 'people.view_basic',
            'leave.view_calendar', 'leave.view_all',
            'directory.view', 'directory.manage',
            'reports.view', 'ai.use', 'settings.view', 'audit.view',
        ],

        'head-of-department' => [
            'events.view', 'events.view_all',
            'crew.view', 'crew.manage', 'rota.view', 'rota.view_all',
            'timesheets.submit', 'timesheets.approve',
            'expenses.submit',
            'people.view_basic',
            'leave.request', 'leave.view_calendar', 'leave.approve',
            'directory.view',
            'reports.view', 'ai.use',
        ],

        'producer' => [
            'events.view', 'events.view_all', 'events.create', 'events.edit', 'events.change_stage',
            'crew.view', 'crew.manage', 'rota.view', 'rota.view_all',
            'timesheets.submit', 'timesheets.approve',
            'budgets.view', 'budgets.edit',
            'po.view', 'po.create', 'po.approve',
            'supplier_invoices.view', 'supplier_invoices.approve',
            'freelancer_invoices.view',
            'expenses.submit', 'expenses.approve',
            'people.view_basic',
            'leave.request', 'leave.view_calendar',
            'documents.view_status',
            'directory.view', 'directory.manage',
            'reports.view', 'ai.use',
        ],

        'finance' => [
            'events.view', 'events.view_all', 'events.reconcile',
            'crew.view', 'rota.view', 'rota.view_all',
            'rates.view', 'rates.edit',
            'timesheets.view_all', 'timesheets.lock',
            'budgets.view', 'budgets.view_all', 'budgets.edit',
            'po.view', 'po.create',
            'supplier_invoices.view', 'supplier_invoices.edit', 'supplier_invoices.approve',
            'freelancer_invoices.view', 'freelancer_invoices.approve',
            'expenses.view_all', 'expenses.approve',
            'billing.view',
            'people.view', 'people.view_basic',
            'documents.view_status',
            'directory.view', 'directory.manage',
            'reports.view', 'ai.use', 'settings.view', 'audit.view',
        ],

        'hr' => [
            'events.view', 'events.view_all',
            'crew.view', 'rota.view', 'rota.view_all',
            'rates.view',
            'timesheets.view_all',
            'people.view', 'people.view_basic', 'people.manage', 'people.view_restricted',
            'leave.request', 'leave.view_calendar', 'leave.view_all', 'leave.approve', 'leave.manage',
            'documents.view', 'documents.manage', 'documents.view_status',
            'directory.view',
            'reports.view', 'ai.use', 'settings.view', 'users.manage', 'audit.view',
        ],

        'employee' => [
            'events.view',
            'crew.view', 'crew.respond', 'rota.view',
            'timesheets.submit',
            'expenses.submit',
            'people.view_basic',
            'leave.request', 'leave.view_calendar',
            'directory.view',
            'ai.use',
        ],

        'freelancer' => [
            'events.view',
            'crew.respond', 'rota.view',
            'timesheets.submit',
            'freelancer_invoices.submit',
            'expenses.submit',
            'ai.use',
        ],

        'client-viewer' => [
            'events.view',
        ],

    ],

];
