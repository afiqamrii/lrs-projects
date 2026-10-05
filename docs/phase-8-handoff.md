# LRS handoff · Phase 8

Implemented on **5 October 2026 (Asia/Kuala_Lumpur)**. Scope ends after Phase 8. The full authorized request is [PHASE_8_BRIEF.md](PHASE_8_BRIEF.md); earlier Phase 5–7 contracts remain in [HANDOFF.md](HANDOFF.md). No Gmail, booking, payment, campaign, automatic vendor selection, negotiation or client-outcome automation was added.

## Outcome and complete flow

Admin approves separate, versioned defaults for vendor RFQs and client quotations. An active Agent/Admin previews and explicitly activates **one exact approved parent revision** in either bounded automatic mode or review-each-message mode. The preview includes actual To/CC, From/sender/Reply-To, original accepted or declared sending evidence, frozen message text, permitted placeholders, optional exact files, working calendar, projected dates, cumulative cap, expiry and stop conditions. Saving a company policy alone never starts reminders.

The existing workflow now continues from approved RFQ/client-quotation sending to **explicit follow-up activation → one due reminder → existing outbox verification/submission/reconciliation → next date from actual acceptance → meaningful response stops, unsafe evidence holds, or exhausted cap creates an internal attention task**. Staff retains commercial decisions. Provider acceptance is not delivery, reading, client acceptance or booking.

The Apple-inspired silver/white/graphite/blue screens include follow-up panels in RFQ/client-quotation review, full activation preview/history, individual reminder review, Admin policy history and an Attention queue with case owner, source evidence, next action and audited reassignment/resolution. Resolving a task does not reactivate automation.

## Defaults, validation and authorization

All automation starts disabled. No migration enables a policy or activates a plan. The optional FollowupPolicySeeder creates disabled starter versions only when an active Admin exists, and does not overwrite versions.

The editable example is first reminder after **two business days**, second after **three more**, maximum **two cumulative sends**, Monday–Friday, **09:00 inclusive to 17:00 exclusive**, no approved holidays, and maximum **15 minutes** since healthy incoming sync. These are examples needing company approval, not logistics rules. Policy timezone is the configured company timezone, currently Asia/Kuala_Lumpur.

Server validation rejects fractional/malformed interval or weekday inputs before integer normalization. It supports one to five intervals/sends, each interval one to thirty business days; selected distinct ISO weekdays 1–7; up to 366 explicit holiday dates; one valid same-day sending window; sync freshness 5–120 minutes; bounded subject/body and approval reason. Arbitrary or unknown placeholders and subject header newlines fail validation. Supported placeholders are:

`[reference]`, `[revision]`, `[recipient_name]`, `[company_name]`, `[deadline]`, `[stage_number]`.

Values come only from the frozen approved parent and company context. No AI writes a reminder. Original prices, terms and validity never change. Attachments default to none. An enabled policy may permit explicit selection of the original approved files; customer reminders can include only the exact approved customer PDF with its saved checksum, never vendor commercial files.

Admin alone versions company policy. Active Admin/Agent can operate shared cases and activate/pause/cancel/review reminders. No new roles or exclusive ownership permissions were invented. The actual approver, reason, time and digest are stored. Stale policy edits and changed activation digests fail rather than silently replacing evidence. Revoked staff authorization blocks submission.

Policy edits preserve existing frozen authorizations. Material per-plan changes require a new preview/approval and cancel incompatible unsubmitted stages. Disabling company policy pauses affected active/held plans. Re-enabling does not resume them.

## Calendar and counts

Due instants, policy evidence and send events use UTC storage; business-day calculations and display use the frozen company timezone. Business-day increments preserve local sending time, skip excluded weekdays and entered holidays, and roll before-opening/after-closing dates to the next permitted window. The calendar has a bounded 740-day search. A changed company timezone holds old plans until a new policy/activation is approved.

Initial scheduling starts from a known original provider acceptance/Sent Item event. Pending or uncertain originals cannot start a plan. A separately recorded manual send can participate only through explicit activation showing its declared recipients, evidence and actual local sending date; staff may correct the declared date in the preview. It must be a past date after exact approval. Manual evidence does not claim a provider thread or delivery.

Projected later dates are illustrative: each next stage is recomputed from the **actual previous reminder acceptance**, not the original due date. After downtime, one stage is eligible; the next waits its full business-day interval. No burst of overdue reminders occurs. A schedule cannot extend original RFQ or quotation validity; stages outside expiry remain ineligible.

One logical plan belongs to `rfq:{rfq_id}` or `client_quote:{quotation_id}`. **Cumulative counts survive policy changes, reactivation and parent revisions.** Sequence numbers also remain monotonic, including cancelled stages.

A count is reserved exactly once at the final potential-submission boundary, before provider send. Repeated jobs and rejected-submission retries reuse that reservation. Uncertain outcomes retain it; no automatic count refund or cap reset is offered. This conservative limit prevents an ambiguous send from bypassing the authorized maximum. Preparing/reviewing a draft alone does not count. At exhaustion the exact task is **“No response after approved follow-ups”**; it does not close the case or alter commercial decisions.

## Response, stop and hold rules

Incoming evidence still follows the Phase 5 encrypted-original, immutable-source, classification-history and attachment-import contracts.

- Exact vendor quote, question or decline stops that request's plan and routes work to staff. It does not approve vendor pricing.
- Exact client acceptance, decline, revision request or meaningful question stops the plan only with recorded human classification/revision review. A label alone cannot establish acceptance or create a booking.
- Out-of-office evidence holds for review. No automatic return-date deferral was invented.
- A related bounce stops sending to the approved contact and creates contact review; no alternative recipient is chosen.
- Potentially related unmatched/unreviewed replies, unfinished attachment import or unhealthy/stale incoming sync hold affected plans. Every selected current incoming folder must be healthy, with no retained page, live processing lease, incomplete cycle or unresolved errors, and a successful sync within the frozen threshold. Missing selected incoming folders also hold.
- Expired or superseded parents, material shipment/selection changes, held/closed cases, inactive required staff/contacts and invalid approval/file evidence stop release through existing parent eligibility checks.
- Disconnected/revoked mailboxes or changed sender identity hold; changed company timezone also holds.
- Uncertain/in-flight prior reminder submission holds the next stage until reconciliation.
- Explicit pause/cancel cancels unsubmitted stages. Once provider submission begins, LRS records late cancellation but cannot unsend the message.

Holds/stops never automatically resume. Staff must review evidence and deliberately approve a fresh activation if appropriate. The activation option to acknowledge **previously reviewed** responses requires a reason and cannot bypass unreviewed related messages or bounces. Task resolution alone has no sending effect. Attention ownership follows the current inquiry owner, falling back to the activating staff member; unassigned remains explicit.

## Persistence, dispatch and threading

The additive Phase 8 migration is `2026_10_04_202752_create_followup_tables.php`, applied to the existing application database as **batch 12**. It adds followup_policies, followup_plans, followup_authorizations, followup_stages and attention_tasks; the existing mail envelope gains a fourth approved source, and incoming messages gain exact client-quotation revision/human-review fields. Earlier business records and immutable snapshots remain.

PostgreSQL unique target keys, unique plan/stage sequence and a partial unique pending-stage index complement short transactional claims. Frozen policy/authorization/content/date/digest/lineage and nondecreasing counts have database protection. Runtime hash and current-eligibility checks are also required. Destructive rollback with activation evidence is rejected; use a forward migration. The empty-schema rollback restores Phase 7 envelope lineage and its three-source constraint before removing reminder columns.

`ManageFollowups`, `FollowupCalendar` and `FollowupEligibility` are the shared policy/claim/response contracts. They dispatch only after committed records exist. Company/inquiry/parent/plan locks precede final dispatch locks; provider calls happen outside database transactions. Immediately before potential submission, the worker rechecks exact authorization, current parent, recipient/sender/files, reply processing, sync, expiry, window and cap.

`MailRelease` / `MailOutbox` / `DispatchMail` reuse Phase 5 encrypted envelopes, unique action/source keys, provider draft verification and uncertain reconciliation. Reminders with a known supported original provider thread use Microsoft Graph createReply, then patch the exact approved subject/body/To/CC/sender/Reply-To and verify draft inventory before sending. They do not fall back to an unrelated draft after a thread error. Manually declared originals have no invented provider thread.

Laravel 13 scheduling uses a once-per-minute `lrs:followup-tick` with `withoutOverlapping(5)`; persisted uniqueness remains the final duplicate protection. Mail dispatch timeout is 300 seconds, lease 360 seconds and database queue retry_after 450 seconds. Submission retries remain separate from logical follow-up counts. The implementation follows installed-version [Laravel scheduling](https://laravel.com/framework/docs/13.x/scheduling) and [queue/after-commit/timeout guidance](https://laravel.com/framework/docs/13.x/queues). The adapter contract follows [Microsoft Graph createReply](https://learn.microsoft.com/en-us/graph/api/message-createreply?view=graph-rest-1.0).

## Local run instructions

Preserve the populated `lrs` database and existing .env; do not use migrate:fresh or reset samples. The prepared PostgreSQL server listens at 127.0.0.1:55432. Base installation and the portable database start instructions remain in [README](../README.md).

Web terminal:

~~~powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
~~~

Mail worker, in a separate terminal with the same directory/environment:

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan queue:work database --queue=mail --tries=1 --timeout=300
~~~

For extraction/optional AI as well, the existing combined worker is supported:

~~~powershell
php artisan queue:work database --queue=mail,extraction,ai,default --tries=2 --timeout=330
~~~

Scheduler, in another terminal:

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan schedule:work
~~~

Manual operational checks:

~~~powershell
php artisan lrs:mailbox-tick
php artisan lrs:followup-tick
php artisan schedule:list
~~~

Start at [Attention queue](http://127.0.0.1:8000/attention). Admin uses [Follow-up policies](http://127.0.0.1:8000/settings/followups) and [Outlook connection](http://127.0.0.1:8000/settings/mailbox). Staff opens Follow-ups from the exact approved RFQ or client quotation review.

Production later requires supervised workers and one configured scheduler per deployment/shared lock store. Restart workers after code/config releases. Follow-ups require explicitly approved company policies, exact per-parent activations, a configured mailbox and fresh healthy incoming sync; running the scheduler alone grants no send authorization.

## Isolated local fixture time

For a separate synthetic acceptance, temporarily set `MAILBOX_DEMO_ENABLED=true` locally, use Admin's explicitly labelled fixture connection, sync its selected folders with the mail worker and approve a policy/parent activation. This changes the fixture identity and invalidates old envelope approval; review a new parent if necessary. No real Microsoft call occurs.

Read the plan ID and next date from the exact activation. Replace PLAN_ID and the example instant below with that active synthetic plan and its approved date:

~~~powershell
php artisan lrs:followup-tick --plan=PLAN_ID --fixture-at=2026-10-07T09:00:00+08:00
php artisan queue:work database --queue=mail --stop-when-empty --tries=1 --timeout=300
php artisan lrs:followup-tick --plan=PLAN_ID
~~~

The fixture business clock is persisted only for one synthetic plan in local/testing with an enabled connected fixture mailbox; it cannot affect a real case or production. Time cannot move backwards. Sync freshness, queue leases and worker timeouts still use actual time. After an accepted reminder, Admin's **Import synthetic question** control adds an exact-thread fictional question; the normal worker/import/human-review path must hold and then stop the plan. A stopped plan is deliberately excluded from fixture advancement.

After acceptance, disable the company policy, disconnect the fixture connection and restore `MAILBOX_DEMO_ENABLED=false`. Preserve all evidence. The retained Phase 8 browser plan is stopped and is not a fresh active clock example.

## Recovery procedures

1. **Reply awaiting import/review:** inspect the task's original evidence. Run the mailbox worker and finish retained-page/attachment processing; perform explicit exact-revision classification review. A commercial question stops the plan. Do not relabel evidence just to release a reminder.
2. **Stale/unhealthy sync:** inspect current selected folders, last success and catch-up/error state. Use the existing explicit bounded folder recovery/resync controls. Keep automation held until healthy, then review a new activation.
3. **Uncertain send:** inspect dispatch events and provider/Sent Item reconciliation. Use Phase 5 recovery/reconciliation only. Never create another reminder or mark the uncertain one failed merely to bypass it. The reserved count is preserved.
4. **Expired/stale source or changed recipient/sender:** review/reconfirm the appropriate shipment/selection/quotation or contact/identity; create a new approved parent where necessary. Old approvals/messages remain history. Recheck cap before activation.
5. **Worker interruption:** let its bounded lease expire, inspect persisted draft/submission state, then use outbox recovery. Do not delete leases or database rows blindly. Queued jobs are after-commit and stages have unique identities.
6. **Out-of-office/bounce/cap:** staff reviews the evidence/contact or follows up manually through an appropriately approved workflow. No automatic substitute address, deadline extension, discount or renewed cap.
7. **Company disable/pause:** stopping before submission cancels pending mail. If a send has crossed submission_started_at, preserve evidence and reconcile. Re-enable plus new activation is required to resume.

## Verification record

- Final full guarded PostgreSQL regression: **266/266 tests passed, 2,357 assertions**, including existing phases and two separate-process Phase 8 scheduler/dispatch concurrency tests. Runtime was 753.653 seconds. The first broad run exposed dispatch-view assumptions and an empty-schema rollback constraint issue; both were fixed and the complete suite rerun successfully.
- FollowupCalendar tests cover business days/hours, holidays, Asia/Kuala_Lumpur and New York DST. Feature tests cover disabled/no activation, frozen messages/schedules/files, manual review, cumulative caps across policy edits, downtime, late replies, all response classes, source/contact/sender/timezone changes, expiry, unhealthy sync, uncertain submission and rejected-submission retries.
- After final policy input/message refinements, **3 affected tests / 37 assertions passed** again: fractional/malformed rejection, valid form normalization with empty holidays, cap preservation and the authenticated synthetic response control.
- Required Pint formatting (tracked and new PHP), strict Composer validation, compiled Blade PHP syntax checks and Vite 8.3.2 production build passed. Generated assets: CSS 50.25 kB, JavaScript 16.53 kB. Additive migration status, registered routes and both every-minute scheduler commands were inspected. No Phase 8 dependency was added.

The normal browser walkthrough used the explicit **local fixture transport**, never real client/vendor addresses. The realistic fictional precision-components quotation keeps MYR 1,300 cost, 20% markup and MYR 1,560 selling total. Current parent revision 10 has a new exact approval after fixture identity activation; earlier evidence is retained.

Browser acceptance used quotation revision **10**, exact approval **4**, plan **1**, reminder stage **1** and dispatch **7**. Staff approved automatic mode from the exact preview. Isolated business time advanced to **7 October 2026, 09:00 Malaysia**; one reminder was accepted, count became **1/2**, and its next projected date became **12 October, 09:00**, three business days after actual acceptance. An exact-thread synthetic question imported through the normal mailbox/attachment worker and held the plan. Human exact-revision/classification review then **stopped** it with no pending date. Repeated tick/worker runs created no second reminder. Both attention tasks were resolved with evidence, retaining a stopped plan and full history.

Actual Chrome inspection included accepted reminder/envelope, linked dispatch/lineage evidence, policy editor/error state, stopped/disabled-plan state and attention open/resolved/empty states. Desktop 1262px, mobile 390px and 320px checks had matching viewport/document widths and no duplicate field IDs on inspected forms. Invalid fractional policy input was preserved and rejected with a focused, visible error summary; exact valid form submission saved disabled version **2**. Browser runtime error check was empty; the console contained the existing local Boost logger information message. Keyboard Tab/Enter opened the mobile navigation and Escape closed it with focus restored to its trigger.

Acceptance cleanup completed: the client policy is disabled at version **2**, the fixture mailbox is **disconnected**, the local MAILBOX_DEMO_ENABLED flag is restored to **false**, mail acceptance jobs were drained, and the retained plan stays **stopped with one cumulative send**. No real client/vendor message, live AI call, deployment or pilot signoff occurred. Existing uncommitted Phase 6/7 work and immutable histories were preserved; no reset, staging or commit was performed.

Necessary Phase 7 prerequisite repair: HTML resend IDs arrived as strings while PostgreSQL returned integers, producing a changed frozen revision digest after persistence. The resend_of_id integer cast now makes both representations identical and is regression tested using the actual form string. The previously blocked immutable synthetic revision 9 was preserved; a fresh valid revision 10 was used.

No live Microsoft consent, delegated/shared Exchange rights, actual createReply/thread behavior, upload limits, external delivery or production worker pilot was exercised. Fixture transport and HTTP-contract tests cannot prove live Exchange behavior. AI remains unconfigured; reminder functionality needs no AI. Physical browser download saving and a full assistive-technology audit remain earlier manual limitations.

## Phase 8 manual acceptance checklist

Use a labelled fictional case and local fixture transport, or a separately authorized controlled mailbox. Never use real clients/vendors for acceptance.

- [ ] With no policy or a disabled policy, run scheduler/worker and confirm zero reminder stages/sends. An Agent must not access Admin policy configuration.
- [ ] As Admin, review disabled starter intervals/window/weekdays/holidays/freshness/templates; approve a version with a reason. Confirm this starts no parent plan.
- [ ] Open an exact approved RFQ/client quotation after known accepted sending (or explicitly declared manual evidence). Inspect actual recipients/sender, original evidence/date, every rendered message, optional manifest, projected calendar, cap and unchanged expiry; explicitly activate.
- [ ] Advance isolated fixture time to the first due date. Run tick twice and worker twice; inspect one exact reminder, one cumulative count and next date based on acceptance. Run the concurrency tests for separate-process proof.
- [ ] Choose review-each-message mode on another eligible parent. Due time must create a review task without outbound dispatch; explicit exact reminder approval is required.
- [ ] Exercise weekend/opening/closing/holiday and timezone boundaries. After downtime, only one overdue stage sends, and no stage extends quote expiry.
- [ ] Change policy or approved parent with a deliberate new activation; verify history, cumulative count and cap survive. Stale preview/authorization must fail.
- [ ] Import an exact vendor quotation/question/decline. Verify later stages stop and staff sees original source; commercial review remains separate.
- [ ] Import a client question/acceptance/decline/revision request. Verify hold during import/unreviewed evidence, then exact human revision/classification review stops the plan without any booking.
- [ ] Check out-of-office hold, bounce/contact task, potentially related unmatched reply, incomplete import, stale sync, disconnected/changed mailbox and inactive contact/staff. No send or substitute contact should occur.
- [ ] Pause/cancel while preparation is pending; no provider submission should occur. Simulate ambiguous submission; count is retained, next stage blocked and reconciliation required. Check exhausted cap yields “No response after approved follow-ups” without case closure.
- [ ] Inspect Attention ownership/evidence/next action, assign/resolve with audit reason and confirm resolution does not reactivate. Check desktop/390px/320px, keyboard focus, validation/empty states and exact reminder/file/outbox links.

Human company/pilot signoff remains separate from automated/local acceptance.

## Phase 9 and Phase 10 continuation hooks

**Phase 9 Gmail** must implement the provider adapter's supported draft/thread/attachment/sender verification and uncertain reconciliation contracts. It must continue using frozen followup_authorizations/stages, shared MailRelease/Outbox preflight and cumulative counts. Provider-specific threading must be proven with controlled live behavior; unsupported thread behavior must hold for staff, never silently change recipients/content. Incoming Gmail evidence must preserve source identity/deduplication, exact RFQ/client quotation revision matching, reviewed classifications and import/sync health before `ManageFollowups::response` or periodic eligibility checks run. Do not introduce a provider-specific automation approval bypass.

**Phase 10 outcomes** must consume the exact incoming client_quotation_revision_id, response_reviewed_by/at, original source digest/classification review events and immutable quotation/approval/send evidence. Invoke the same response/stop contract when a human records acceptance, decline or revision request. Phase 8 does not model authoritative client acceptance, capacity/rate reconfirmation or booking; accepted email transport is not a commercial outcome. Formal transitions and any job handoff require their own business authorization and preserve existing reminder/audit history.

Stop after Phase 8. Later phases remain unstarted.
