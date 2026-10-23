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
