# Decisions

Choices made where the specification was open, newest last. Each says what was decided and why.

## 1. The demo reads stored JSON through one data layer

The demo screens read `database/demo/*.json` directly (agreed with the project owner) instead of seeding every module's tables up front. `App\Demo\Workspace` is the only place that knows the data is JSON; screens and Actions use its methods. Changes made during a walkthrough are held in the session by `DemoLedger`, so the demo is safe to share and resets on sign-out. Identity, roles, permissions, invitations, two-factor, the activity log and Ask Hive's request log are real database tables from Phase 0.

In each later phase the module's part of `Workspace` is replaced by Eloquent models and migrations with the same shapes, so the Actions, policies and screens keep their contracts.

## 2. A fixed demo date

The data is dated around Friday 23 October 2026 (`organisation.json → demo_today`). "Today" in the demo screens comes from that date, so the walkthrough (a live event, a clash this week, an overdue payment) stays meaningful whenever it is run.

## 3. `app/Demo` and `app/Support`

The specification's folder list has no home for pure calculators or the demo layer. Value objects and calculators (`Money`, `DateRange`, clash detection, rates, health, tolerance, working time) are in `app/Support`; the demo layer is in `app/Demo`. `app/Services` stays for integrations, as specified; Ask Hive's engine sits there because it will call the Anthropic API.

## 4. Permissions with an `_all` scope

Section 6 mixes permissions with ownership ("on events they produce"). Ownership is checked in policies; where a role sees everything, it holds an extra permission: `events.view_all`, `events.edit_all`, `crew.manage_all`, `budgets.view_all`, `rota.view_all`, `timesheets.view_all`. Producers have `budgets.view` but not `budgets.view_all`, so they see budgets only for their own events.

## 5. Health status thresholds

"Watch" is read literally: forecast above 90% of budget, or margin below the target (18% by default). Both are in `config/hive.php`. The demo budgets are set so most active events sit just under 90%; approving Brightline's over-tolerance invoice tips Northwind from On track to Watch, which the walkthrough uses.

Reconciled, closed and cancelled events show no health; their numbers are final.

## 6. Crew costs

Rule 7 says approving a freelancer's time creates an Actual cost. The section 2 glossary calls approved crew cost "committed" until invoiced. Rule 7 wins: approved time is Actual, at the day rate effective on the date worked. It draws down the crew line's remaining estimate, so the forecast moves only if crew overspends.

## 7. Clash resolution trims, it does not cancel

Resolving a clash moves only the clashing days: the original booking is trimmed to the days either side (split in two if the clash is in the middle) and the replacement is booked for the clashing days. Suggestions prefer people based in the event's country, then skill level, rating and day rate.

## 8. Invoices over tolerance need a reason

Rule 11 says such invoices "need approval". Approving one also needs a written reason, which goes to the activity log with the before and after amounts.

## 9. Expired mandatory documents block by default

Section 5.2 makes it configurable; `HIVE_EXPIRED_DOCUMENTS=block` is the default, `warn` only shows the warning.

## 10. Ask Hive in the demo uses a local engine

`App\Services\AskHive\LocalEngine` answers by calling the same read-only tools a model would, and writes answers from their results. No API key is needed, it is deterministic for tests, and it never guesses. The tools, scoping, citations, logging (`ai_requests`) and rate limits are the real ones. Phase 7 adds an engine that calls the Anthropic Messages API with `Tools::definitions()`, behind the same `AskHiveEngine` interface.

## 11. Typeface

The tasteskill draw dealt Geist; Impeccable's detector flags Geist as overused. Hive uses Atkinson Hyperlegible Next and Mono, which were drawn so that characters can't be mistaken for each other, which suits reading event codes and call times on a phone in a dark venue. Fonts are self-hosted rather than loaded from Google Fonts, to avoid sending visitors' IP addresses to a third party (a UK GDPR concern).

## 12. Charts

No chart package is on the approved list. `resources/js/vendor/charts.js` is a small dependency-free SVG chart file from tasteskill (MIT), vendored with its notice.

## 13. Larastan locally

In the build container Composer cannot download phpstan (GitHub release archives are blocked by its network policy), so `larastan/larastan` is in `composer.json` and runs in CI as normal. Locally it was run from a clone of phpstan and larastan at the locked versions. The code passes level 6 with no baseline and no ignores.

## 14. Planned modules

Navigation items for modules in later phases (freelancer invoices, exports, onboarding, skills, reports, settings, integrations) are shown to permitted roles and open a page that names the phase that delivers them, so the information architecture of section 4 is complete from the start.
