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

The band at the top of every page switches persona without signing out, so changes carry across. **Reset demo** puts everything back. Changes are kept in your session only, so other visitors never see them.

### The walkthrough

1. **Priya**: the dashboard shows one clash, Meridian Bank's forum at risk, and pipeline value by stage.
2. **Hannah**: on the rota, Kofi's clash is outlined in red. Select it, choose a free AV technician (nearest first) and move Friday to them. Both people are notified.
3. **Hannah**: in Approvals, Kofi's week has a 14-hour shift and a short-rest flag. Approve it and the crew cost appears as Actual on the Halcyon budget, at the day rate in force on each date.
4. **Grace**: Brightline's invoice is £1,250 over its PO, past the tolerance. Approving needs a reason; Northwind's margin moves from 25.1% to 24.9% and its health from On track to Watch.
5. **Kofi**, at phone width: accept the Lisbon booking request, add time, submit the week.
6. **Ask Hive**: "Which events are at risk this month and why?" As Priya you get a cited answer; as Kofi, a refusal.
7. **Sam**: the audit log shows everything just done, with values before and after.

Also worth a look: drag an enquiry across the pipeline board (the stage rules still apply), the event budget tab with euro costs for Lisbon, Leah's view of Ola Brennan's expired insurance, and the access matrix in action as Kofi.

## Where the demo data comes from

Everything you see is read from JSON in [`database/demo`](database/demo): people, events, crew calls, assignments, leave, timesheets, budgets, purchase orders, invoices, milestones, notifications and seeded history. The figures reconcile: budgets are the sum of their lines, crew actuals are approved time multiplied by the effective day rate, and foreign costs carry their original amount, rate and sterling equivalent.

Sign-in, roles, permissions, invitations, two-factor and the audit log are real database features. The demo screens read JSON through one layer (`app/Demo`), scoped by the same permissions. In the later build phases each module moves to Eloquent models behind the same Actions.

## What works

| Area | Status |
|---|---|
| Sign-in, two-factor (mandatory for four roles), invitations, roles and permissions, deactivation, audit log | Built and tested (phase 0) |
| Layout, light and dark themes, toasts, modals, empty states, status pills, global search | Built |
| Dashboards for every role, with "Needs attention" ranked by urgency | Built on demo data |
| Events list, event page with tabs, pipeline board with drag and drop and stage rules, calendar | Built on demo data |
| Rota (2 weeks or a month), clashes, resolve flow with suggestions, availability search, crew calls | Built on demo data |
| Timesheets, approvals inbox with working time flags, return with comment, period lock | Built on demo data |
| Budgets, cost lines, purchase orders, supplier invoices with tolerance, expenses, client billing | Built on demo data |
| Directory, profiles with restricted rates and personal fields, leave, documents | Built on demo data |
| Ask Hive with policy-scoped read-only tools, citations, logging and rate limits | Built with a local engine; the Anthropic engine is phase 7 |
| Freelancer invoices, exports, onboarding, skills admin, reports, settings, integrations | Later phases (each has a page saying which) |

## Development

```bash
./vendor/bin/pint                 # format
./vendor/bin/phpstan analyse      # Larastan, level 6
php artisan test                  # Pest
```

CI runs Pint, Larastan, Pest against MySQL 8, `composer audit` and `npm audit` on every pull request. See [`CLAUDE.md`](CLAUDE.md) for conventions, [`DESIGN.md`](DESIGN.md) for the design system and [`docs/decisions.md`](docs/decisions.md) for choices made where the brief was open.

## Licences

Fonts: Atkinson Hyperlegible Next and Mono, SIL Open Font License (see `resources/fonts`). `resources/js/vendor/charts.js` is from tasteskill, MIT. The design skills in `.claude/skills` keep their own licences.
