# Hive architecture

Hive is one Laravel 12 application: Livewire screens on top of Actions, which hold every business rule, over Eloquent models. This page shows how the parts fit; `CLAUDE.md` says where each lives.

## Modules

```mermaid
flowchart LR
    subgraph Screens["Livewire screens (thin)"]
        Dash[Dashboards and reports]
        Ev[Events: tasks, crew, travel, budget]
        Res[Resourcing: rota, crew calls, clashes]
        Time[Timesheets and approvals]
        Fin[Finance: POs, invoices, expenses, billing]
        Ppl[People: directory, leave, onboarding, skills]
        Adm[Admin: users, settings, integrations, queue, retention, audit]
        Ask[Ask Hive panel]
    end

    subgraph Core["Rules"]
        Actions[Actions in app/Actions: authorise, change state, log, notify]
        Approvals[Approvals engine]
        PostCost[PostCost: the only writer of cost entries]
        LeaveBook[LeaveBook: leave years, balances, bank holidays]
        Reports[Reports in app/Reports]
    end

    subgraph Read["Reading"]
        Workspace[Workspace: memoised reads, shared in the cache per data version]
        Viewer[Viewer: what the signed-in user may see]
        Insights[Insights: dashboard aggregates]
    end

    subgraph AI["Ask Hive"]
        Engine[ClaudeEngine or LocalEngine]
        Tools[Tools, viewer-scoped: read, and propose for the user to confirm]
        Gateway[AnthropicGateway: the only class using the SDK]
    end

    subgraph Out["Integrations (behind interfaces, faked in tests)"]
        Entra[Entra ID sign-in]
        Xero[Xero bills and payments]
        Hooks[Signed webhooks]
        ICS[Calendar feeds]
        CSV[CSV import and exports]
    end

    Screens --> Actions
    Screens --> Viewer
    Actions --> Approvals
    Actions --> PostCost
    Actions --> LeaveBook
    Viewer --> Workspace
    Insights --> Workspace
    Dash --> Insights
    Dash --> Reports
    Reports --> Viewer
    Ask --> Engine --> Tools --> Viewer
    Engine --> Gateway
    Actions --> Hooks
    Adm --> Xero
    Adm --> CSV
    Workspace --> DB[(MySQL)]
    Actions --> DB
```

## A request

1. Middleware: sign-in (Fortify, or Microsoft through `SingleSignOnController`), two-factor for the roles that need it, route permissions (`can:` and `can_any:`).
2. The Livewire component reads through `Viewer` (scoped to the user) and `Workspace` (all records as arrays, memoised; table reads are shared between requests in one cache entry until the data changes).
3. Changes go to an Action, which authorises with `Gate::forUser($user)`, writes in a transaction, logs to the activity log, notifies people (`Workspace::notify`, respecting their preferences), and calls `Workspace::forget()`, which also moves the shared cache to a new version.
4. Slow or outward work is queued: Ask Hive answers, webhook deliveries, clash notices.

## Background work

The scheduler (`routes/console.php`) runs reminders, digests, the health check of event budgets, retention flags, the leave year and the Xero sync. A queue worker runs the queued jobs. Failed jobs appear on Admin › Queue.

## Data that never leaves

Restricted person fields are encrypted at rest, never cached, never sent to Ask Hive, and only included in the travel manifest and subject access export for people with `people.view_restricted`. Rates are removed by `Viewer` for anyone without `rates.view`. Webhook payloads carry codes, names, dates and statuses only.
