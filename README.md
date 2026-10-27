# Hive

An operations platform for an events agency: people, crew, time and money for every event in one place, with an assistant that reads but never decides.

Hive is an independent portfolio project built in Laravel 12 and Livewire 3. The agency in it, **Lumen & Co Live**, is fictional, as are every client, supplier, person and figure.

## Try the demo

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan serve
```

Open http://127.0.0.1:8000. The sign-in page lists nine people at the agency. Pick one and you are signed in with their details filled in. Every demo password is `password`.

It is **Friday 23 October 2026** in the demo. Northwind's partner summit is live in London, Arclight launches tonight in Manchester, and Kofi, a freelance AV technician, is booked on both.

| Persona | Role | Email | Two-factor | Starts on |
|---|---|---|---|---|
| Priya Raman | Operations director | priya@lumenco.test | Yes, code shown on screen | Dashboard |
| Hannah Clarke | Producer | hannah@lumenco.test | No | Rota |
| Marcus Bell | Producer | marcus@lumenco.test | No | Approvals |
| Grace Adeyemi | Finance | grace@lumenco.test | Yes | Supplier invoices |
| Leah Fitzgerald | HR | leah@lumenco.test | Yes | Directory |
| Tomasz Nowak | Head of department | tomasz@lumenco.test | No | Rota |
| Jamie Ross | Employee | jamie@lumenco.test | No | My work |
| Kofi Mensah | Freelancer | kofi@freelance.test | No | My work |
| Sam Okafor | Super admin | sam@lumenco.test | Yes | Users and roles |

Super admin, operations director, finance and HR must use two-factor authentication. In demo mode the challenge screen shows the current code from the persona's authenticator, with a button to use it.

The band at the top of every page switches persona without signing out, so changes carry across. **Reset demo** puts back everything kept in your session. Events, bookings, time and finance are in the database, so changes to them are shared by every visitor until `hive:demo-refresh` restores them, every hour.

### The walkthrough

1. **Priya**: the dashboard shows one clash, Meridian Bank's forum at risk, and pipeline value by stage.
2. **Hannah**: on the rota, Kofi's clash is outlined in red. Select it, choose a free AV technician (nearest first) and move Friday to them. Both people are notified.
3. **Hannah**: in Approvals, Kofi's week has a 14-hour shift and a short-rest flag. Approve it and the crew cost appears as Actual on the Halcyon budget, at the day rate in force on each date.
4. **Grace**: Brightline's invoice is £1,250 over its PO, past the tolerance. Approving needs a reason; Northwind's margin moves from 25.1% to 24.9% and its health from On track to Watch.
5. **Kofi**, at phone width: accept the Lisbon booking request, add time, submit the week.
6. **Ask Hive**: "Which events are at risk this month and why?" As Priya you get a cited answer; as Kofi, a refusal.
7. **Sam**: the audit log shows everything just done, with values before and after.

Also worth a look: as Hannah on the Lisbon event's crew tab, add a crew call for 5 Nov and book Mei Lin, who is already confirmed elsewhere that day; Hive asks why, the clash goes red on the rota, and resolving it moves the day to someone free and tells both. The call sheet PDF is on the same tab. As Hannah, **Add enquiry** on the events list, then take it to Confirmed: the stage rules ask for a client, dates and a budget, then the signed contract, and confirming adds the standard task list for its type. Drag an enquiry across the pipeline board (the stage rules still apply), the event budget tab with euro costs for Lisbon, Leah's view of Ola Brennan's expired insurance, and the access matrix in action as Kofi.

## Where the demo data comes from

Everything you see starts from JSON in [`database/demo`](database/demo): people, events, crew calls, assignments, leave, timesheets, budgets, purchase orders, invoices, milestones, notifications and seeded history. The figures reconcile: budgets are the sum of their lines, crew actuals are approved time multiplied by the effective day rate, and foreign costs carry their original amount, rate and sterling equivalent.

Sign-in, roles, permissions, invitations, two-factor, the audit log, people, the directory, events, resourcing, time and finance are in the database, seeded from that JSON. The modules still on JSON are read through one layer (`app/Demo`), scoped by the same permissions. In the later build phases each module moves to Eloquent models behind the same Actions.

## What works

| Area | Status |
|---|---|
| Sign-in, two-factor (mandatory for four roles), invitations, roles and permissions, deactivation, audit log | Built and tested (phase 0) |
| Layout, light and dark themes, toasts, modals, empty states, status pills, global search | Built |
| Dashboards for every role, with "Needs attention" ranked by urgency | Built on demo data |
| Events: add and edit with phases and team, stage rules with history, task lists from templates per event type, private event files with signed links; list, pipeline board, calendar | Built on the database and tested (phase 2) |
| Resourcing: crew calls, booking with clash and working time checks, booking requests freelancers accept or decline, holds and bulk confirm, unavailability, basic leave requests, rota (week or month), clashes and the resolve flow, availability search, call sheet PDFs, weekly rota digest | Built on the database and tested (phase 3) |
| Time: weekly timesheets (add, change, remove, submit), entry-level approval by each event's producer, return with comment, working time flags, payroll locks with logged unlocks, Friday and Monday reminders; shared approvals engine with an all-modules inbox and delegation | Built on the database and tested (phase 4) |
| Finance: budgets with versions against an approved baseline, revenue, purchase orders with approval limits and PDFs, supplier invoices with three-way matching and tolerance, freelancer invoices (upload and self-billing), expenses with receipts and mileage, billing milestones, exchange rates, CSV exports, the reconciliation rule | Built on the database and tested (phase 5) |
| People and directory: add and edit staff and freelancers, skills, effective-dated day rates, encrypted restricted fields, document uploads with signed links, expiry reminders; clients, suppliers and venues with contacts | Built on the database and tested (phase 1) |
| Leave | Requests and approval built (phase 3); balances and the full module are phase 5 |
| Ask Hive: Claude through the official SDK with policy-scoped read-only tools, verified citations, queued answers, logging and rate limits; timesheet summaries, budget risk narratives, wrap report drafts and crew suggestions | Built and tested (phase 7); a deterministic local engine runs without an API key |
| Onboarding, skills admin, reports, settings, integrations | Later phases (each has a page saying which) |

## Ask Hive with Claude

Set `ANTHROPIC_API_KEY` and Ask Hive uses Claude (`claude-opus-5-5` by default; `ANTHROPIC_MODEL` and `ASK_HIVE_EFFORT` change it). Without a key it uses the local engine, which answers the demo questions deterministically. Answers are queued, so run a worker (`php artisan queue:work`, or a worker on Laravel Cloud) or set `QUEUE_CONNECTION=sync`. To check a deploy:

```bash
php artisan hive:ask "Which events are at risk this month and why?" --as=priya@lumenco.test
```

The model sees only the question, the user's name and role, and what the read-only tools return for that user. Every request is logged in `ai_requests` with the tools it called and the tokens used.

## Development

```bash
./vendor/bin/pint                 # format
./vendor/bin/phpstan analyse      # Larastan, level 6
php artisan test                  # Pest
```

CI runs Pint, Larastan, Pest against MySQL 8, `composer audit` and `npm audit` on every pull request. See [`CLAUDE.md`](CLAUDE.md) for conventions, [`DESIGN.md`](DESIGN.md) for the design system and [`docs/decisions.md`](docs/decisions.md) for choices made where the brief was open.

## Licences

Fonts: Atkinson Hyperlegible Next and Mono, SIL Open Font License (see `resources/fonts`). `resources/js/vendor/charts.js` is from tasteskill, MIT. The design skills in `.claude/skills` keep their own licences.
