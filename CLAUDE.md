# CLAUDE.md

Guidance for Claude Code working in this repository. The full product brief is the Hive build specification (sections 0–16); read it before starting a new phase. Decisions taken where the brief was open are in `docs/decisions.md`.

## What this is

Hive: an operations platform for a fictional events agency, Lumen & Co Live. People, resourcing, time, finance and HR in one Laravel app, with Ask Hive on top. Portfolio project; every company, person and figure is fictional.

## Stack

- PHP 8.3, Laravel 12, Livewire 3 (class components) with Alpine (bundled with Livewire)
- Bootstrap 5.3 + Sass via Vite, Bootstrap Icons, Atkinson Hyperlegible Next/Mono (self-hosted, OFL)
- Fortify (sign-in, two-factor, password reset; no registration), Spatie permission, Spatie activity log
- Pest 3 (+ Laravel and Livewire plugins), Larastan level 6, Pint
- SQLite locally, MySQL 8 in CI and production

## Commands

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate:fresh --seed
php artisan serve                       # http://127.0.0.1:8000, personas on the sign-in page

./vendor/bin/pint                       # format (CI runs --test)
./vendor/bin/phpstan analyse            # Larastan level 6, no baseline, no ignores
php artisan test                        # Pest, ~280 tests
npm run build                           # after any change to resources/scss or resources/js
php artisan db:seed --class=RolesAndPermissionsSeeder   # after editing config/hive-permissions.php
```

Run Pint, Larastan and the tests before calling a phase done.

## Where things live

| Path | What |
|---|---|
| `app/Actions/{Module}` | Single-purpose actions with `handle()`. All business rules live here. They authorise, change state, write the activity log, notify. |
| `app/Livewire/{Module}` | Thin components: read through the Workspace/Viewer, call Actions, show toasts. |
| `app/Demo` | The demo data layer: `DemoData` (reads `database/demo/*.json`), `DemoLedger` (session overlay of changes made in the walkthrough), `Workspace` (data + changes, derived views), `Viewer` (what the signed-in user may see), `Insights` (dashboard aggregates), `Rows` (JSON row validation). |
| `app/Support` | Value objects and pure calculators: `Money`, `DateRange`, `Display`, `Navigation`, `Resourcing\ClashDetector`, `Resourcing\ShiftPattern`, `Finance\RateBook`, `Finance\HealthCalculator`, `Finance\InvoiceTolerance`, `Time\WorkingTimeRules`. |
| `app/Policies` | Gates for demo records (timesheet entries, assignments, invoices, events, leave) and `UserPolicy`. |
| `app/Services/Approvals` | The shared approvals engine and a rule per approvable type (`config/hive.php` → `approvals.rules`). Open approvals when something is submitted (`request`), decide through `decide`, read `inbox`. |
| `app/Services/AskHive` | `Tools` (read-only, viewer-scoped), `AskHiveEngine` interface, `LocalEngine` (deterministic, for the demo and tests). |
| `config/hive.php` | Thresholds, demo mode, two-factor roles, AI settings. |
| `config/hive-permissions.php` | Section 6 as data: every permission and every role's grants. |
| `database/demo/*.json` | The fictional agency. Edit freely; ids must stay consistent across files. People, skills, departments, documents, clients, suppliers and venues are loaded by `DemoDirectorySeeder`, events (phases, team, stage history, tasks, files, task templates) by `DemoEventsSeeder`, crew calls, assignments, unavailability and leave requests by `DemoResourcingSeeder`, timesheets, payroll locks and their approvals by `DemoTimeSeeder` (after the users), and budgets, POs, invoices, expenses, billing, rates and cost entries by `DemoFinanceSeeder` (after time); the rest is still read directly. |
| `app/Models` | Phase 1: `Person` (+ `PersonRestricted`, encrypted), `DayRate`, `Skill`, `Department`, `Document`, `Client`, `Supplier`, `Venue`, `Contact`. Phase 2: `Event`, `EventPhase`, `EventTeamMember`, `EventStageChange`, `Task`, `TaskTemplate`, `TaskTemplateItem`. Phase 3: `CrewCall`, `Assignment`, `Unavailability`, `LeaveRequest`. Phase 4: `Timesheet`, `TimesheetEntry`, `PeriodLock`, `CostEntry`, `Approval`, `ApprovalDelegation`. Phase 5: `Budget` (versions), `BudgetLine`, `RevenueLine`, `PurchaseOrder`, `PurchaseOrderLine`, `SupplierInvoice`, `FreelancerInvoice`, `Expense`, `BillingMilestone`, `ExchangeRate`. Each top-level model has `toWorkspaceArray()` for the Workspace. |
| `app/Jobs`, `app/Console/Commands` | Scheduled work (`routes/console.php`): `SendDocumentExpiryReminders` daily at 07:00 UK time, `SendWeeklyRotaDigest` Monday 08:00, `RemindApproversOfPendingItems` Monday 09:00, `RemindMissingTimesheets` Friday 14:00, `hive:demo-refresh` hourly while demo mode is on (events and bookings are shared in the database). `DetectClashes` is queued after a booking over a clash. |
| `resources/scss/app.scss` | The whole theme: tokens, light and dark. See `DESIGN.md`. |
| `PRODUCT.md`, `DESIGN.md` | Product context and design system, read by the design skills. |

## Conventions

- **Money** is `int` minor units plus a currency, always through `App\Support\Money`. Never floats. Totals are sums of the lines shown.
- **Authorise in Actions** with `Gate::forUser($user)->authorize(...)`; Livewire components never decide permissions themselves.
- **Scope every list** through `Viewer` (`events()`, `people()`, `presentPerson()`, `canSeeBudget()`...). Rates and restricted fields are removed before they reach a view or Ask Hive.
- **Log every state change** with `activity($log)->causedBy($user)->withProperties(['ref' => ..., 'old' => [...], 'attributes' => [...]])->event(...)->log(...)`.
- **Enums** for every status, each with `label()` and `tone()`; show status with `<x-status :value="..." />`, text always present.
- **British English** in all copy; dates as `23 Oct 2026` via `App\Support\Display`; 24-hour times; UTC stored, Europe/London shown.
- **Blade gotcha**: never put an inline `@php(...)` above a `@php ... @endphp` block in the same view (Blade swallows the content between them). Avoid `@elseif` straight after a nested `@endif` (Livewire's morph markers break it).
- After an Action changes the ledger or a model the Workspace reads, call `$workspace->forget()` so memoised reads refresh.
- Event stages change only through `ChangeEventStage`; `SaveEvent` never sets the stage.
- Cost entries are written only by `App\Actions\Finance\PostCost` (commit, release, actual). Never edit one; post a reversing entry.
- Approvals go through `ApprovalEngine`: `request()` on submit, `decide()` on approve or return, `withdraw()` on cancel. Never set an approvable's status without it.
- Notify people with `$workspace->notify($personId, ...)`: it stores a `HiveNotice` on the `Person` and emails them.
- Livewire gotcha: don't name a public property after a `$wire` method (`call`, `set`, `get`, `refresh`...); `wire:model="call.x"` silently never syncs.
- SQLite stores `date` casts with a time, so query them with `whereDate`.
- No new Composer or npm packages without asking (spec section 0.8).

## Demo mode

`HIVE_DEMO=true` shows persona cards on the sign-in page, the demo band (switch persona, reset), and the current TOTP code on the two-factor screen for demo personas. Demo users come from `DemoUserSeeder` (local, staging and testing only; it refuses to run in production). Changes made during the walkthrough live in the session (`DemoLedger`) and are cleared on sign-out or "Reset demo". Never enable demo mode in production.

## Design skills installed in `.claude/skills`

From the "five free plugins that make Claude good at design" list:

- `design-taste-frontend` (tasteskill): app-screen art direction. Dealt the *Achromatic ledger* style. `node .claude/skills/design-taste-frontend/scripts/lint.mjs --app <files>` and `inspect.mjs --app <page>` (set `CHROME_PATH`; in a root container use a wrapper that adds `--no-sandbox`).
- `impeccable`: design system, critique and detector. `PRODUCT.md` and `DESIGN.md` are its context. Run `.claude/skills/impeccable/scripts/impeccable detect --json resources/views resources/scss` after UI changes; it should report `[]`.
- `frontend-design`: Anthropic's design guidance, used for the two-pass plan in `DESIGN.md`.
- `img2threejs`: installed for completeness; Hive has no 3D surfaces.
- Playwright: used to drive the app in a real browser (the demo walkthrough and phone-width checks). Chromium is at `/opt/pw-browsers`.
- `awesome-design-md`: Linear's DESIGN.md is kept in `docs/design-reference/` as a structural reference only.

## Build phases

See the README for status. Phases 0 to 5 are complete; screens for later phases run on the demo data layer, and each phase replaces its part of `app/Demo` with Eloquent models without changing the Actions' contracts.
