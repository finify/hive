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

## 15. Phase 1: people and the directory are in the database

People, departments, skills, day rates, restricted details, documents, clients, suppliers, venues and contacts are Eloquent models with migrations. `DemoDirectorySeeder` loads them from the same JSON, keeping its ids so the modules still on JSON (events, bookings, timesheets, finance) point at the right people. `Workspace` reads these tables and returns the same array shapes as before, so no other screen changed.

## 16. Who sets day rates

The matrix gives HR view-only access to rates, but the phase 1 brief has HR create a freelancer with a rate. HR (`people.manage`) sets the **starting** rate when adding a freelancer; every later rate is a new effective-dated rate added by finance (`rates.edit`). Adding a rate ends the current one for the same role the day before.

## 17. Supplier insurance is a document

Suppliers' insurance is stored as a `documents` row on the supplier, like people's. Changing the expiry date on the supplier form records a new insurance document rather than editing the old one, so the history stays.

## 18. Document files

Uploads go to a private disk (`DOCUMENTS_DISK`, local by default, S3-compatible in production), accept PDF, JPG, PNG and Word up to 10 MB, and are opened through a signed link that lasts five minutes. The policy is checked again when the link is opened, and every opening is logged. HR and the person see files; producers and finance see a document's status only.

## 19. Expiry reminders

`SendDocumentExpiryReminders` runs daily at 07:00 UK time and tells the person and HR 30 and 7 days before a document expires. `documents.reminded_days` makes each reminder go once. Reminders arrive in the bell and by email (email preferences arrive in phase 6).

## 20. Self-service profile edits

People can upload their own documents now. Editing their own pronouns, dietary and access needs is left for phase 6, with the rest of self-service.

## 21. Phase 2: events are in the database

Events, phases, team, stage history, tasks, task templates and event files are Eloquent models. `DemoEventsSeeder` loads them from `events.json` with the same ids, so bookings, budgets and timesheets (still JSON) point at the right events. `Event::toWorkspaceArray()` returns the shape the screens already read, so the list, pipeline, calendar, rota and Ask Hive did not change.

## 22. The stage only moves through ChangeEventStage

The details form never sets the stage. A new event starts as an enquiry; every move goes through `ChangeEventStage`, which checks the rules, writes an `event_stage_changes` row and the activity log. Enquiries may have no dates, client or fee; the rules ask for them before a proposal goes out.

## 23. Event codes

`EV-` plus the two-digit year and the next number for that year (`EV-26128`), counting soft-deleted events so a code is never reused.

## 24. Task templates

One standard list per event type, with due dates as offsets from the event's start and an assignee role. Confirming an event with no tasks adds its list; a producer can also add it to an empty event by hand. A task goes to the team member holding the item's role, or to the producer. The producer (or anyone who edits all events) manages tasks; the person a task is assigned to may also move its status. Conference has 30 items.

## 25. The signed contract is a file

Rule 1's "signed contract" is a `documents` row of type contract on the event, uploaded on the Files tab. Event files can be opened by anyone who can see the event; seeded files are records without a file.

## 26. Shared demo data is refreshed (superseded by 35)

Moving events into the database means changes made on the public demo are seen by every visitor, not kept in one session. `hive:demo-refresh` puts the seeded events, tasks and event files back, and is scheduled at 03:00 UK time while demo mode is on. It refuses to run in production. The rest of the walkthrough still lives in the session.

## 27. Phase 3: resourcing is in the database

Crew calls, assignments, unavailability and leave requests are Eloquent models, seeded from the JSON with the same ids, so timesheets and budgets (still JSON) keep pointing at the right bookings. `Workspace` returns the same shapes, so the rota, clashes list, availability search, dashboards and Ask Hive did not change.

## 28. Crew calls: derived status, phase by type

A crew call's status (open, partially filled, filled) is worked out from its confirmed and first-hold bookings rather than stored, so it can't disagree with them. Its phase is a phase type (build, live...) rather than a link to a phase row, because a call often spans phases and phases are edited freely. A call can't be cut below the number already booked, or removed while anyone is booked on it.

## 29. No assignment_days table yet

The data model suggests one row per person per day for fast clash checks. With a few hundred bookings, clash detection over each person's date ranges (indexed on person and dates) is quick, so the table is left until volumes need it; `ClashDetector` is the only place that would change.

## 30. Booking over a clash

Section 5.5 asks for a reason before a clashing booking is made, and phase 3's acceptance test expects that booking to show as a clash until it is resolved. Both hold: the reason is always required, and where it goes depends on the booking. A confirmed booking over confirmed bookings only stores it as `clash_override_reason`, the recorded override of rule 1, and the overlap stands as accepted. Anything else (a hold, or an overlap with leave or unavailability) stores it as `clash_note`; the clash stays red on the rota until it is resolved or someone chooses "Keep both". The producer of the other event is told by the `DetectClashes` job.

## 31. Freelancers are asked; employees are booked

Booking a freelancer sends a request with the event, dates, location, role and day rate; it is a hold until they accept, and accepting confirms it once the event is confirmed. Employees are booked directly and told. "Confirm first holds" on a confirmed event confirms every first hold it can, and lists the ones it can't (leave, an expired document, an unanswered request).

## 32. Notifications belong to the person

Notices (booking requests, releases, clashes, approvals) are stored notifications on the `Person` and emailed to them, so freelancers without a Hive sign-in still get the email and keep the history if they get one. The bell shows the person's notices, the sign-in's own (document reminders) and the seeded demo notices.

## 33. Working time warnings on bookings

Each booked day counts as a shift from the crew call's call time to its wrap time, with a scheduled 20-minute break. Warnings: less than 11 hours' rest between shifts, a shift over 12 hours, and an average above 48 hours a week over a 17-week reference period (counting booked weeks only; a single long week does not trigger it). Thresholds are in `config/hive.php`. Nobody in the demo has opted out of the 48-hour limit, so there is no opt-out field yet; it arrives with settings in phase 6. The "no break on a shift over 6 hours" check needs recorded breaks, so it applies to timesheets, not bookings. These are warnings, not legal advice, and never block.

## 34. Call sheets

One PDF per event per day with anyone booked: venue address with access and power notes, first call and last wrap, crew by call time with role and where to report, and the event team as contacts with their work phones. Crew phone numbers are left off. Producers and crew managers can open and email them; people booked that day can open them. Emailing attaches the PDF and leaves a notice in the person's history. The PDF uses DejaVu Sans because dompdf can't read the self-hosted woff2 fonts.

## 35. The shared demo resets every hour (time added in 44)

Events and bookings are shared by everyone using the public demo, and the walkthrough depends on Kofi's clash being there. `hive:demo-refresh` now restores events, resourcing and the notices they sent every hour while demo mode is on; the demo band says so. It still refuses to run in production.

## 36. Leave in phase 3 is basic

People ask for leave from the leave page; days count weekdays that aren't bank holidays, and their line manager or HR decides (the existing approval flow). Overlapping requests and dates with no working days are refused, and leave that hasn't started can be cancelled. Half days, balances that move and the full module are phase 5.

## 37. The weekly rota digest

Monday at 08:00, producers hear about clashes and unfilled crew calls on their events over the next four weeks; heads of department about clashes for their department's people and every unfilled call. Nothing is sent to someone with nothing to act on.

## 38. S3 for documents

`league/flysystem-aws-s3-v3` is added (approved by the project owner) so `DOCUMENTS_DISK=s3` can store documents in an S3-compatible bucket in production. Local and test environments keep the private local disk.

## 39. Phase 4: time and costs are in the database

Timesheets, entries, payroll locks and cost entries are Eloquent models, seeded from the JSON with the same ids. Approved crew time now posts a cost entry every visitor sees, which is what makes the phase 4 test hold: each event shows the crew cost as Actual. Supplier invoice approvals still apply their cost movements to the session until invoices move in phase 5; `Workspace::costEntries()` lays those over the database rows. A cost entry posted from approved time is marked `draws_down_remaining`, so it comes out of the crew line's remaining estimate (decision 6) and the forecast only moves if crew overspends.

## 40. The approvals engine

`App\Services\Approvals\ApprovalEngine` is the shared component of section 5.9. Each submission opens one `approvals` row per approver per step; rules per type (`config/hive.php`, `approvals.rules`) say who approves and how: single, sequential (each step opens when the one before is approved) or any-of (the first decision settles it). Approvers are people, not sign-ins, because producers and managers are people first; a decision is recorded with who made it. Timesheet entries are single (the event's producer, or the line manager for internal codes and for a producer's own time on their event); leave is any-of (line manager or HR). Purchase orders, supplier invoices and expenses join in phase 5; until then supplier invoices still appear in the inbox through their own gate. It lives in `app/Services` with Ask Hive because it is shared infrastructure rather than a calculator.

## 41. Nobody approves their own

Rule 5 is enforced twice: the engine leaves the requester out of the approvers when it opens a request, and refuses a decision from the requester. A rejection closes every other open approval on the item; resubmitting opens fresh ones.

## 42. Delegation

An approver can hand their approvals to a colleague for some dates. The colleague must have a Hive sign-in and approve something themselves (so they can reach the inbox), can't be the approver, and the dates can't overlap other cover. Covered items appear in the delegate's inbox marked "Covering for"; the decision is recorded as the delegate's. Delegation never lets anyone approve their own submission.

## 43. Payroll locks

Finance locks a date range after payroll (the demo has July to September locked). Inside an active lock, time can't be added, changed, removed or returned (rule 9); entries still waiting can be approved, since approving doesn't change the hours. Fully approved weeks inside the period read Locked. Unlocking needs a written reason and is logged; the weeks go back to Approved.

## 44. Hourly pay

Section 5.6 lets freelancers be paid by the day or by the hour. Everyone in the demo is on a day rate, so entries count as a day (six hours or more) or half a day, and cost is days times the rate on that date. Hourly rates arrive with freelancer invoices in phase 5 if needed. The hourly demo refresh now also restores timesheets, approvals, cover and locks.

## 45. Reminders

Friday 14:00: anyone confirmed on an event this week whose timesheet is missing or still a draft. Monday 09:00: every approver with items waiting more than three days (configurable), with the count and the oldest date. Both arrive in the bell and by email.

## 46. One inbox

"My approvals" opens on Everything: timesheet entries, leave and supplier invoices waiting for the user, oldest first, with a tab per type to act on them. Cover is set from the same page.

## 47. Phase 5: finance is in the database

Budgets (with versions), budget lines, revenue lines, purchase orders, supplier and freelancer invoices, expenses, billing milestones and exchange rates are Eloquent models, seeded from the JSON with the same ids. The session overlay for invoice approvals is gone: every approval now changes the shared data. Every event's financial summary was checked against the figures before the move and is identical.

## 48. How costs move

`PostCost` is the one place cost entries are written. An approved PO commits its net amount and draws down its line's remaining estimate, so the forecast moves only if the PO is bigger than what was left. An approved supplier invoice releases what it covers of the PO's commitment and posts the actual, so the forecast moves by the difference (rule 12). Approved time and expenses post actuals that draw down the remaining estimate. Cancelling a PO releases its commitment and gives the estimate back. Costs not on any budget line still count in the event's totals. Committed and actual entries are append-only: a release is a negative committed entry, never an edit.

## 49. Budget versions

A budget's lines are live; each version keeps a snapshot of the lines it was approved with. The first change to an approved budget opens the next version as a Draft (shown as a revision with its difference from the baseline); the operations director approves it, and the old baseline is kept as Superseded. A line's remaining estimate moves with its budget and never goes below zero; a line with spend against it can't be removed.

## 50. Purchase order limits

Limits are by level in `config/hive.php` (`finance.po_limits`), against the net amount in sterling: the event's producer up to £5,000, the operations director up to £50,000. Above that the brief asks for two approvers, but the matrix gives PO approval only to producers and the operations director, so the second signature is the head of finance (the Finance department's head). A PO goes to the first level that covers it and has someone other than the person who raised it, so a producer's own PO goes to the operations director (rules 5 and 10).

## 51. Supplier invoices

An invoice is Matched when its PO's goods are received and it is within tolerance of what is left to invoice on the PO; otherwise it stays Received and the reason shows. Tolerance stays two-way (decision 8), so a partial invoice needs a note too. Finance or the operations director approves; the event's producer may approve one against a PO within tolerance. An invoice without a PO needs a reason, a budget line and finance's approval.

## 52. Freelancer invoices

Uploaded by the freelancer, or self-billed by Hive from approved time when the freelancer has a current self-billing agreement document (a new document type). Either way the expected amount is approved event time in the period at the rate in force each day; a difference needs a note to approve. Invoices don't post costs: the crew cost was posted when the time was approved, so paying an invoice can't double-count it.

## 53. Expenses

Claims need a receipt, except mileage, paid at 45p a mile (configurable). They go to the event's producer (or the line manager), then finance. Approved, they post an actual to the budget line for their category, or contingency if the budget has none. Foreign amounts use finance's latest rate on the day of the claim.

## 54. Exports and rates

CSV exports of approved supplier invoices, approved freelancer time (for the payment run), approved expenses and billing, each logged. The freelancer export carries day rates, so it needs `rates.view` (rule 17). Cells that would start a spreadsheet formula are quoted. Exchange rates are entered by finance with a start date; costs keep the rate they were posted at.

## 55. Billing

Milestones can be added by amount or as a share of the event's revenue, marked invoiced with the accounting system's reference, and marked paid. The invoice itself is raised in the accounting system, as the brief says. Overdue is as recorded; flagging it automatically needs the accounting integration (later phase).

## 56. Ask Hive engine and client

Ask Hive calls Claude through the official Anthropic PHP SDK (`anthropic-ai/sdk`, approved for this phase), on `claude-opus-5-5` with adaptive thinking and a configurable effort (`ASK_HIVE_EFFORT`, default medium). Server-side fallbacks are on, so a request the model declines can be retried on its default fallback model. The tool definitions and the standing instructions are prompt-cached. The engine is chosen by `ASK_HIVE_ENGINE`: `claude` when an `ANTHROPIC_API_KEY` is set, otherwise `local`, the deterministic engine the tests and a keyless demo use. The SDK talks to the API only through `AnthropicGateway`, so the tool loop is tested against a scripted model that records every byte it would have been sent.

## 57. What the model sees

Only the question, the user's name and role, today's date, fixed instructions, and what the read-only tools return for that user. Permissions are not described to the model; the tools enforce them and answer `"allowed": false`, which the model is told to explain without hinting at the hidden data. An answer is marked refused when every tool it tried was denied, or when the model declines. Tool results are labelled as data, never instructions, because they include text people typed (event names, notes).

## 58. Citations

Sources are not taken on the model's word. After the answer, Hive links the event codes and people the answer names *and* the tools actually returned, plus the screen behind each tool used (Clashes, Approvals, Leave...). A code the model invents gets no link.

## 59. Queued answers

Asking logs the request as queued and dispatches `AnswerAskHiveQuestion`; the panel polls every 1.5 seconds until it is answered or failed. Rate limiting happens when the question is asked, so queued requests count. A failed call is stored with a message the panel shows ("busy" for 429 and 529), and the detail goes to the error log. Laravel Cloud needs a queue worker (or `QUEUE_CONNECTION=sync`). A job reads the database without the walkthrough's session overlay, which no longer matters now that events, resourcing, time and finance are in the database. `php artisan hive:ask "…" --as=email` answers at once, to check a deploy.

## 60. The four workflows

Timesheet approval summary (Approvals, timesheets tab), budget risk narrative (event budget tab), wrap report draft (event page from Live onwards) and crew call suggestions (crew tab). Each is a fixed question the user sees in the panel, with extra instructions to the model, answered with the same tools. The button appears only for people who may run it, and the Action checks again. Two tools were added for them: `get_event_wrap_facts` (money only with budget access) and `list_crew_calls`, plus `list_pending_timesheet_entries`, which never includes cost. Drafts can be copied; nothing is saved, booked or approved.

## 61. Leave years, balances and carry-over

The leave year starts on 1 January (`HIVE_LEAVE_YEAR_START`, month-day). Annual leave counts against the year its first day falls in. A balance is allowance plus carry-over, less leave taken (approved, started) and booked (approved, future); "available" also takes off requests waiting for approval, and a request can't exceed it. The seeded `days_taken` becomes `days_taken_before` (leave before Hive recorded it) less the approved leave Hive already holds, so the figures don't double count. `hive:leave-year` runs on the first day of the year and carries up to five unused days (configurable); carried days don't expire, which the brief didn't ask for. Half days are not supported yet; allowances can be half days.

## 62. Bank holidays and TOIL

Bank holidays live in the database by region: England and Wales (the default), Scotland and Northern Ireland, seeded for 2026 and 2027 from the published lists. Each person has a region on their profile; working days and TOIL use it. HR can add or remove days. TOIL is earned only by employees and fixed-term staff (freelancers are paid for the day) when approved time on an event falls on a weekend or a bank holiday (`hive.leave.toil.accrue_on`). It counts days as entered, or hours over 7.5, to the nearest half day, at most one day per day worked, once per entry. TOIL is a running balance, not tied to a leave year.

## 63. Recording absence

Sickness and other absence not asked for in advance is recorded by HR or the person's line manager, as approved leave without the approvals engine. The type stays restricted: everyone else sees "Absent" (rule 13). The leave report shows sickness days to HR (`leave.manage`) only.

## 64. Onboarding and offboarding

Templates per employment type and kind, with items owned by HR, IT, the line manager or finance and due a number of days from the start or leaving date. A checklist copies its template when started, so later template changes don't rewrite open checklists. HR can tick anything; IT items need `users.manage`, finance items `rates.edit`, line manager items the person's line manager. A checklist closes when every item is done and reopens if one is unticked. Templates are seeded, not yet editable in the app (Settings, phase 8). Liam Walsh has a leaving date of 27 Nov 2026 in the demo so offboarding has an example.

## 65. Travel and accommodation

Hive records travel booked elsewhere (it doesn't book with suppliers). Each leg or stay posts its cost as an actual on the Travel or Accommodation line, drawing down the remaining estimate; a change posts the difference, a currency change reverses and reposts, and a cancellation reverses it all. Cost entries are never edited (as section 3). Times are the local time at the place of departure, as on a ticket, and are stored as entered. People who run the event's crew manage travel; producers who can see the budget see costs. The manifest includes passport number, expiry, nationality and access needs only for `people.view_restricted`, and each export is logged with whether those fields were included.

## 66. Reports

Each report is a class in `app/Reports` that declares its permission, filters and columns and returns raw rows (pence, numbers, Y-m-d), which the page formats and the CSV keeps plain, with a total row where it adds up. Every row goes through the same viewer scopes as the screens: profitability only for events whose budget you can see, pipeline fees hidden from anyone without budget access, freelancer spend only with `rates.view`. Timesheet compliance counts a week as on time if submitted by noon on the following Monday, and an approval as on time within the reminder threshold (three days). Exports are logged.

## 67. Dashboards

Leadership figures need `budgets.approve` as well as company-wide events and reports, so finance sees its own row (committed and actual this month, invoices and POs) rather than the director's. HR sees starters and leavers in the next six weeks (so a leaver at the end of next month shows) and checklist progress.

## 68. Notification preferences and the digest

In-app notices are always on. Each person chooses, per type, whether they also get an email, and can switch to a daily digest at 08:00 UK time with everything of those types from the previous 24 hours. Preferences live on the person, so freelancers without a sign-in can still be configured later. Call sheets and purchase orders sent to suppliers are documents, not notices, and are always emailed. Event stage changes now notify the producer and the event team (section 5.10), apart from whoever made the change.

## 69. Hosting on Laravel Cloud, not Forge

The brief named Laravel Forge on DigitalOcean; the project runs on Laravel Cloud, which provides what phase 8 asked Forge for: separate staging and production environments, a managed queue worker and scheduler, zero-downtime deploys, database backups and logs. CI deploys staging through Cloud's deploy hook only after every check passes on `main`; production is a manual, reviewer-protected workflow. The runbook covers both.

## 70. Microsoft sign-in without a package

Entra ID uses OpenID Connect directly (no Socialite, per the no-new-packages rule): authorisation code with PKCE and a nonce, and the ID token's RS256 signature checked against the tenant's published keys, plus issuer, audience, tenant and expiry. Hive never creates accounts from it: the person must already have an active account, matched on their Entra object id or, the first time, their email. Hive's own two-factor step is skipped for these sign-ins because Entra's conditional access applies.

## 71. Webhooks, calendars, imports and Xero

Webhooks are signed the Standard Webhooks way (`webhook-id`, `webhook-timestamp`, `webhook-signature: v1,<base64 HMAC-SHA256>`), retry for about an hour, refuse private or local addresses, and carry no rates or restricted data. Calendar feeds use a secret URL per person; only its hash is stored, and a new one retires the old. CSV imports go through the same Actions as the forms, previewed in a rolled-back transaction and imported all or nothing. Xero uses a custom connection (client credentials): approved supplier invoices go as authorised bills coded by budget category (account codes in `config/hive.php`), and payments come back hourly. Teams and Slack channel notices (section 5.13) are not built; email and the bell cover them for now.

## 72. GDPR tools

Subject access exports are JSON plus documents in a ZIP, for HR, logged. Erasure anonymises rather than deletes: personal, restricted and contact fields, documents, skills and notices go, the sign-in is closed, and financial records stay under "Former staff member N". The audit log is kept as the record of processing; it may still name the person in old entries. Only leavers with no bookings left can be anonymised. Retention periods are settings; records past them are flagged monthly and nothing is deleted until HR decides.

## 73. Settings in the database

Section 5.15's thresholds and limits are stored in a `settings` table and applied over `config/hive.php` at boot, so changes need no deploy. Credentials stay in the environment. Organisation details, logo and brand colour, departments, leave types and checklist templates are still seeded data rather than editable screens.

## 74. Query budget and caching

Every list page stays within 15 queries (tests enforce it), the busiest dashboard within 25. The Workspace shares its table reads between requests in one cache entry per data version, bumped by `Workspace::forget()` and by saving or deleting any model it reads; people are cached without their encrypted fields. The trade-off: a mass update that skips both would be served stale for up to an hour, which is why Actions always call `forget()`.

## 75. The demo runs on today's date

The demo data is written around Friday 23 Oct 2026. On the live clock (`HIVE_DEMO_CLOCK=live`, the default) Hive uses today's UK date and moves every date in the demo data forward by whole weeks so that week becomes the current one: weekdays stay right (timesheet weeks start on Monday, weekends stay weekends) and the story stays "this week". Bank holidays are real dates and never move. The hourly `hive:demo-refresh` re-seeds with the current shift and moves the people, documents and day rates seeded once by the same number of days. Tests use the fixed clock so their dates are stable. Outside demo mode Hive always uses the real date (before this, `today()` read the demo date even with demo mode off).

## 76. Ask Hive proposes changes; people confirm them

Ask Hive can now make everyday changes (leave, time, booking answers, mileage, approvals, tasks, crew bookings, checklist items), but the model never changes anything. Each change is an `Operation` that wraps an existing Action, offered to the model as a `propose_` tool only if the user's role could use it. Proposing runs the real Action as the user inside a transaction that is rolled back, with notifications, mail and jobs held back, so a change Hive would refuse comes back to the model with the reason, and nothing is kept. A change that passes is shown in the panel with Confirm and Cancel. Confirm runs the Action again, as that user, through `ConfirmProposal`: once only, by its own user, within 30 minutes, logged in the `ai` audit log as well as by the Action itself. Payments, budgets, purchase orders, stage changes and deletions are deliberately left off: they carry more risk than a sentence should, and their pages give the context they need. Expenses other than mileage are left off because they need a receipt.

The panel is now a conversation: each request links to the one before (`previous_id`), and the last six turns, with what happened to their proposals, go to the model so follow-ups make sense. A request can only follow the same user's own requests.

## 77. Ask Hive answers from the staff guide

"How do I…" and "what does … mean" questions are answered from `resources/help`, the same guides as /help, through the `get_help` tool, so Ask Hive explains Hive the way the guide does rather than from the model's own idea of it, and the answer links the guide it used. Only the guides the user's role is shown are searchable. Matching is plain word scoring over title, summary and text (no embeddings, no new packages): the best guide goes to the model in full, with three related titles; a guide must match on its title or summary, or on two of the question's words, so a city named in an example doesn't count. The local engine answers with the guide's opening and its best-matching passage.

## 78. The Ask Hive conversation lives in the session

The panel's list of requests is kept in the user's session (one key per user, the last 30 turns), so moving between pages carries on the same conversation, and signing out or the session expiring ends it. Nothing new is stored: the questions and answers were already in `ai_requests` for the audit trail; the session only holds which of them make up the current conversation. The model still receives the last six turns (decision 76).
