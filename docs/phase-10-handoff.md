# LRS Phase 10 handoff

Implemented locally on 5 October 2026 (Asia/Kuala_Lumpur). Scope ends after Phase 10. The complete request is preserved in [PHASE_10_BRIEF.md](PHASE_10_BRIEF.md). Earlier Phase 6–9 records and all existing business histories remain.

## Outcome and complete flow

Website / manual / Outlook / Gmail inquiry → human client/contact assessment and ownership → confirmed shipment → separate approved vendor RFQs → reviewed offers and equivalent-cost comparison → final immutable selection → staff markup and exact client quotation/PDF approval → explicit shared-outbox send or outside-LRS communication → source review and exact client decision → vendor rate/capacity reconfirmation → service/company readiness checklist → frozen private handoff revision → separate exact staff approval → actual handoff to operations → optional explicitly approved booking instruction → actual reviewed vendor booking reference and dates.

AI, message matching, provider submission, acceptance and handoff approval cannot confirm a booking. There is no vendor booking API, payment processing, client portal, automatic negotiation or Phase 11 reporting/deployment work.

Use the inquiry's **Decision & handoff** tab. The panel shows one current next action, separate client/vendor/handoff states, booking state and responsible agent. Older quotations, confirmations, exports, decisions and corrections remain accessible.

## Client decisions and times

`client_decisions` belongs to one exact immutable client quotation revision. A unique action UUID plus request digest protects duplicate submissions; corrections append to the latest decision with `corrects_id`, retaining original evidence.

Current presentation distinguishes Awaiting decision, Accepted, Revision requested, Declined, Expired and Superseded. Question / review-required evidence remains explicitly unresolved. Expiry and supersession are derived from current facts; they do not rewrite earlier accepted evidence.

Staff compares the original response with the exact saved PDF, total, revision, inclusions, exclusions and terms. Acceptance requires active authorized staff/contact, current approved quote/source/PDF, communicated quotation and explicit identity/unconditional scope agreement. Conditional, ambiguous, old, stale or expired acceptance attempts are retained as `review_required`; no current vendor request is opened. A parser label never accepts.

Email must be human reviewed and matched to that exact quote and known sender. Its mailbox/provider/RFC/source hash and association version are retained. Reassociation, a new substantive reply, changed private evidence, inactive parties or reviewer, and current commercial gates can hold release. Offline evidence captures actual channel, contact, date and notes. Supplied PO reference/document is optional unless approved company policy requires it.

`decided_at` is the actual decision instant; `created_at` is staff recording/review time. Native local date/time inputs use the company timezone and persist UTC. Future actual events are rejected. Email decision time cannot precede receipt; unconditional acceptance cannot predate exact quote approval. Original quote issue/expiry meanings are unchanged.

Matched substantive client mail promptly stops the exact quote's reminders and creates a deduplicated owned response-review task before commercial decision review. Formal decisions, revision requests and questions also stop the corresponding plans. Stops never auto-resume. Existing Phase 8 caps, uncertainty and response safeguards remain.

A revision task records wording, shipment/service, cost or unresolved change scope. New draft uses existing pricing gates. Material shipment changes require the existing confirmed-version/sourcing/review/selection path. Decline records optional structured reason and notes without automatically closing the inquiry. Existing reasoned Close / Reopen actions remain; reopening enters Needs review. An inquiry lifecycle generation advances on material changes and hold/close/resume/reopen so earlier handoff approval cannot revive by toggling status back.

## Vendor reconfirmation

Unconditional acceptance opens `vendor_reconfirmations`, pinned to decision, quote, final selected offer and confirmed shipment. It creates a task and sends nothing.

An editable reconfirmation message includes reference, cargo/equipment, requested dates, vendor rate and exact charges, selected validity/scope/conditions and an explicit availability request. Staff saves an immutable message version, approves actual content/recipients/selected disclosure/files/sender, authorizes the shared envelope and separately enqueues. Outlook/Gmail use the existing jobs, idempotency, actual draft checks, uncertainty and reconciliation. No connection change or event triggers a booking instruction.

`vendor_confirmations` appends actual Pending / Confirmed / Conditional / Changed / Unavailable evidence. Known authorized vendor identity, actual exact native rate/currency, scope agreement, dates and explicit equipment/capacity evidence are required for Confirmed. An acknowledgement, estimates, missing dates or unresolved conditions cannot complete the gate. Vendor evidence time and review time are separate. Email association/hash/version and exact document versions are retained.

Changed rates/currency/dates or an explicitly recorded material change hold handoff. Staff must review the changed offer, renew final selection, prepare/approve a replacement customer quotation and obtain renewed acceptance. The latest recorded material change requires a vendor offer review and selection after that change. Returning an old reconfirmation record to Confirmed cannot clear its changed-history gate. A new quote alone cannot accept an unchanged old cost basis.

Confirmation expiry is entered only when supplied. Optional company freshness hours run from the actual confirmation time. Client quote/vendor offer deadlines, supplied confirmation expiry and configured age are checked again at approval and release. No moving clock enters the immutable dependency fingerprint.

## Authority, checklist and configured prerequisites

Only active Admin/Agent staff can review outcomes, confirmations, handoffs or booking evidence. Existing inquiry authorization applies; child records must belong to the route's inquiry. Guests/inactive staff cannot read private exports. Admin alone versions company requirements at **/settings/handoff**. The seeder approves no policy.

Hard commercial readiness requires current unconditional client acceptance, approved current quotation/PDF, matching confirmed shipment/selection, exact reviewed vendor rate/scope/capacity/dates and valid active authority. Cargo availability evidence and an active operations owner always apply. Pickup/delivery addresses and usable named phone/email contacts depend on the confirmed scope/services; inapplicable items show Not applicable.

Company policy can require a client reference/PO, manually evidenced actual deposit, and labelled documents per applicable service. Mapping uses supplied exact private files, checksum/size/version/classification, never a fabricated receipt. Deposit recording is external evidence only, with local input converted to UTC; no payment collection is implemented.

Only policy-whitelisted pickup contact, delivery contact or company document exceptions are supported. Each exception requires an active Admin, explicit reason and time recorded in the frozen evidence. It shows Conditional. There is no generic commercial/acceptance/price/scope/capacity/cargo/owner override. Agents cannot forge exception authority. A missing-document exception cannot excuse corrupt, altered or cross-inquiry supplied evidence.

Local walkthrough configuration is documented in the verification record below. It is a fictional preview policy, not a prescribed operational or legal requirement.

## Frozen release and booking distinctions

Saving prepares a new `handoff_revisions` snapshot/PDF containing accepted customer terms, selected private cost/pricing/profit, confirmed shipment/vendor facts, checklist evidence, contacts, owner, policy and dependency identity. Missing prerequisites may be saved as an incomplete draft but cannot be approved.

PDF preparation occurs outside short database locks; current dependency digest and expected aggregate version are rechecked inside the transaction. Separate `handoff_approvals` freezes exact staff authority/time and separate approved PDF bytes. Concurrent saves/approvals preserve one current version and one approval. Database uniqueness, lineage and immutable-record triggers supplement application gates.

Current release rechecks freshness, source associations, parties, policies, evidence, current versions, inquiry generation and both prepared/approved PDF checksums. Material changes require another handoff revision and approval. Earlier PDFs remain historical. Queued booking instructions use this same current gate immediately before submission.

Handoff ready → Handoff approved → Handed to operations is separate from Booking unconfirmed → Booking requested → Booking confirmed. Preparing/approving/enqueuing a message never confirms a booking. Submitting/uncertain provider evidence is conservatively displayed as Booking requested; delivery and booking remain unconfirmed.

Staff records actual internal handoff with time and evidence. External booking request/confirmation follows that actual handoff. Booking confirmation requires vendor booking reference, authorized contact, actual agreed pickup/arrival dates, explicit scope agreement, supporting notes and optional reviewed original vendor email/private files. The original email must belong to the exact vendor operational source and case; automated/outgoing/wrong-provenance sources are rejected. Corrections reference the latest event and append without deleting the original.

Internal handoff PDFs are staff-only and excluded from vendor messages. Default vendor content never projects client markup/profit or the complete internal record. Each external attachment subset needs explicit disclosure review; customer commercial/vendor quotation files and internal handoff PDF checksums are blocked. Staff may share reviewed shipment-only files through the existing outbox.

## Local run instructions

Use PHP 8.5, PostgreSQL and the installed lock files. This workspace uses PostgreSQL 17 on loopback port 55432; existing normal database `lrs` must not be reset. Tests require a separate PostgreSQL database ending in `_test`, currently `lrs_test`; the test bootstrap refuses unsafe databases.

~~~powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
composer install
npm.cmd ci
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
~~~

In separate terminals with the same working directory/PHPRC:

~~~powershell
php artisan queue:work database --queue=mail --tries=1 --timeout=300 --no-interaction
php artisan queue:work database --queue=documents,ai --tries=1 --timeout=300 --no-interaction
php artisan schedule:work --no-interaction
~~~

The scheduler runs existing mailbox/follow-up ticks and `lifecycle:maintain` every minute. Lifecycle maintenance only recovers deduplicated staff tasks/cancellation; it never infers outcomes or creates bookings, and does not repeatedly cancel an explicit later activation for an already recovered response. Current release gates also run synchronously and at the worker, independently of scheduler timing.

Login with your existing staff account at **http://127.0.0.1:8000/login**. No default password or new account is seeded. If an initial Admin is needed, run `php artisan lrs:admin` interactively; it asks for name, email and a hidden password. Existing accounts and historical authors are preserved.

Manual work works without a live mailbox/AI. Shared-outbox release requires a configured authorized usable connection and an exact current envelope. See Phase 9 for live Google setup/scopes/aliases, and earlier HANDOFF for Microsoft setup.

For an explicitly controlled local fictional walkthrough, temporarily enable `MAILBOX_DEMO_ENABLED=true`, clear config, reconnect/select the existing fixture Gmail, enable its incoming folder, and use **Capture fictional reply** on an accepted client quotation/reconfirmation/booking dispatch. It injects clearly labelled source into the existing fixture sync, not a decision. Run the mail worker; review original mail, then explicitly record workflow evidence. This helper is Admin-only, local/testing-only, demo-only, same-identity and accepted/observed-dispatch-only, with duplicate reply prevention. Restore the flag and disconnect/pause after verification.

~~~powershell
php vendor/bin/phpunit --log-junit .tools/phase10-full.xml
php vendor/bin/pint --dirty --format agent
composer validate --strict --no-check-publish
php artisan view:cache --no-interaction
npm.cmd run build
~~~

Run database tests sequentially. Do not use migrate:fresh/reset/rollback against populated normal data. New lifecycle migrations are additive and rollback remains restricted to guarded testing databases.

## Verification record

Complete sequential PostgreSQL regression passed **310 tests / 2,712 assertions** (`.tools/phase10-verified-full.xml`). Final affected checks after the PDF-review validation redirect fix passed **29 tests / 246 assertions** (`.tools/phase10-final-ui-recheck.xml`), including actual two-process handoff saves/approvals and existing client quotation regressions. Earlier focused follow-up/lifecycle checks passed **51 tests / 334 assertions**.

Pint passed for changed and new Phase 10 PHP files. All **180** compiled Blade views passed PHP syntax checks; Composer strict validation, production Vite build and Git whitespace checks passed. The older globally installed Composer executable emits PHP 8.5 deprecation notices in its own bundled libraries; project validation succeeded. Additive normal migration batches **17–18** are applied. Scheduled mailbox/follow-up/lifecycle commands are registered, and `lifecycle:maintain` ran successfully without inferring a decision or booking.

One earlier full run produced malformed stdout from a concurrent test worker. Strict JSON diagnostics were added; the cause was not reproduced. Subsequent focused concurrency runs and the complete rerun passed. Retain those diagnostics for future Windows/CI investigation rather than treating that earlier harness failure as a business approval.

The actual browser journey used the existing local fictional precision-components inquiry **#8**, without resetting its data:

- Current quote **r12**, MYR **1,560**, was separately approved, explicitly sent through fixture Gmail dispatch **#11**, and observed. Its original reply was imported through normal sync, associated/reviewed by staff, then recorded as exact unconditional decision **#1**.
- Reconfirmation request **#1** and exact operational message **#1** were separately approved, authorized and explicitly queued as dispatch **#12**. The original vendor response was human reviewed. Missing date entry stayed Pending v1; complete exact MYR **1,300** rate/scope/capacity/date evidence became Confirmed v2. Nothing automatically accepted or booked.
- Admin policy **#1** requires service-dependent addresses/contacts, cargo availability, matching current commercial facts and an active owner. It configures no additional document, PO, deposit, age limit or operational exception. This is an explicit fictional walkthrough configuration; supplied quote/vendor expiries still apply.
- Private handoff **v1** was saved, all three A4 PDF pages visually inspected, separately approved, and explicitly handed to operations. Booking stayed unconfirmed. Exact booking message **#2** / fixture dispatch **#13** was separately approved and sent; provider acceptance only meant Requested. Original vendor response review and an explicit actual-evidence record with **SIM-OCEAN-1025-093** and agreed dates then produced a historical simulated Booking confirmed event.
- A later fictional vendor rate change was appended as Changed v3. It held release while preserving the earlier booking record. Vendor offer **v7 / revision ID 20** was freshly commercially reviewed, final selection **#4** saved at MYR **1,400**, and client quote **r13** approved at MYR **1,680** (20% markup). Staff then recorded Farah's renewed exact phone decision **#2** and vendor reconfirmation request **#2**, with fresh confirmation **#4**.
- A new private handoff **v2** was prepared, all three pages inspected, and separately approved. Its current booking is **Unconfirmed** and its next action is an actual transfer to operations. The earlier booking and immutable PDF remain pinned to handoff v1 / quote r12.

Screens were inspected at **1440px, 390px and 320px**. The readiness and lifecycle screens had no horizontal overflow or duplicate IDs; tested readiness controls all had labels. The mobile menu closed with Escape and subsequent Tab focus was visibly highlighted. Empty workflow, missing prerequisites, retained-input server error and success states were exercised. A future decision time returned a useful error while preserving notes/checkboxes; this exposed and fixed Laravel's inline-PDF previous-page redirect issue. No browser page errors were reported; the console contained only the development browser-logger notice.

Saved browser evidence:

- [Current approved handoff and separate unconfirmed booking](screenshots/phase10-lifecycle-desktop.png)
- [Rate change and release hold](screenshots/phase10-rate-change-desktop.png)
- [Retained-input decision error](screenshots/phase10-decision-error.png)
- [Mobile readiness](screenshots/phase10-handoff-phone-top.png)
- [320px current lifecycle](screenshots/phase10-lifecycle-320.png)
- [Keyboard focus](screenshots/phase10-keyboard-320.png)
- [Empty lifecycle](screenshots/phase10-empty-320.png)
- [Disconnected fixture sources](screenshots/phase10-cleanup-phone.png)

Cleanup restored **MAILBOX_DEMO_ENABLED=false** and cleared configuration. Gmail **#2** is disconnected with incoming paused; Outlook **#1** remains disconnected/paused. Existing follow-up policy/plan state and all old authors, messages, documents, approvals and histories remain preserved. The approved fictional handoff policy stays available for manual review.

Native date controls required setting their observed DOM values and dispatching normal input/change events because CLI filling did not reliably populate them; submissions still used actual forms, CSRF, server validation and business gates. Physical native date-picker interaction, screen-reader coverage and printed output remain manual acceptance items. Live Google/Microsoft/AI configuration remains unavailable locally. Fixture/HTTP contracts do not prove live consent/provider normalization/quotas, deliverability, actual capacity/booking, production scheduling or pilot acceptance. No real email or booking occurred. Realistic preview records remain explicitly fictional with reserved .example contacts.

Coverage includes exact/old/expired/conditional/manual/email acceptance, append corrections/idempotency, deduplicated tasks, immediate reminder cancellation, material rate/date changes, renewed vendor review/selection/quote/decision, missing prerequisites, exact document bytes, company timezone deposit, Admin exceptions, freshness, stale queued booking rejection, actual booking corrections, closure/reopen generation and private staff access.

The manual checklist below remains a reusable company acceptance checklist; automated and browser evidence above identifies what was actually verified. Company-authorized live checks and assistive-technology/physical-output checks remain outstanding.

## Phase 10 manual acceptance checklist

Use fictional fixtures or separately authorized controlled test mailboxes, never customer/vendor bookings as tests.

- [ ] Send an exact approved client quote through the shared outbox. Inspect actual sender/recipients/PDF checksum and provider evidence separately from commercial outcome.
- [ ] Import a substantive exact reply. Confirm one review task and prompt corresponding reminder stop before formal acceptance; repeated sync/review creates no duplicate outcome.
- [ ] Compare original reply with exact PDF/terms/total/revision. Human-review association, then record unconditional current acceptance and optional supplied reference/PO. Confirm reconfirmation task only and booking still unconfirmed.
- [ ] Record conditional, ambiguous, wrong-contact, old, superseded and expired acceptance evidence. Preserve it as review-required, without current acceptance or booking.
- [ ] Record a phone/offline decision with actual date/contact/channel/evidence. Reject future time. Append a correction to the latest record; original source and outcome remain.
- [ ] Request wording versus material shipment/cost revision. Material change must renew existing shipment/offer/selection gates. Decline must not close; explicitly close/reopen with reasons and verify earlier handoff cannot revive.
- [ ] Edit/approve reconfirmation content and exact attachment subset/envelope; separately enqueue. Matching, acceptance and policy saves send nothing automatically.
- [ ] Review actual vendor rate/scope/dates/capacity. Missing facts/acknowledgement remain Pending; unresolved conditions Conditional; unavailable capacity Unavailable; material changes Changed. Preserve all versions.
- [ ] After a changed rate, confirm old selection/quote/acceptance cannot release. Review new offer and final selection, approve replacement quote, renew exact client acceptance and vendor confirmation.
- [ ] Admin approves practical service-dependent company prerequisites. Verify PO/deposit/documents only when configured; unsupported/malformed policy data fails validation. Agent/guest Admin-policy actions are denied.
- [ ] Inspect Complete/Missing/Conditional/Not applicable checklist states. Check applicable addresses/usable contacts, actual cargo evidence, active owner, exact required files and optional deposit. Missing prerequisites block approval.
- [ ] Apply only an explicitly permitted Admin operational exception with reason/time. Commercial acceptance, scope, rate, capacity, cargo and owner must remain non-overridable.
- [ ] Save and inspect the private internal PDF; approve separately. Competing edits/approvals must produce one current revision/approval. Guest, inactive staff and cross-inquiry child access are denied.
- [ ] Let confirmation/quote/rate expire, edit parties/source associations/documents/policy or close/reopen. Current release and queued booking instructions must hold before submission while old PDF/history remains.
- [ ] Record actual handoff to operations; confirm booking still unconfirmed. Optionally approve/enqueue booking message; provider acceptance is only Booking requested.
- [ ] Review actual vendor booking source/reference/agreed dates/contact/scope, then explicitly record Booking confirmed. Incorrect dates, automated/unreviewed/outgoing source, future/predating time or missing reference must block. Append booking correction preserving original.
- [ ] Inspect desktop/390px/320px, keyboard navigation/focus, source links, empty/no-policy states and server error/success/retained input. Check physical saving and assistive technology manually where not verified.
- [ ] Run sequential PostgreSQL regression, Pint, Composer, Blade compilation and production build; record live checks separately. Disconnect fixture access and disable the demo flag while retaining evidence.

## Phase 11 continuation contracts

Reporting/pilot/production work is not implemented. Consume existing immutable IDs and actual timestamps rather than inferring outcomes from email labels or submission states.

| Record/event | Timestamp and reporting meaning |
|---|---|
| ClientDecision | Exact quote revision/reference, requested/effective outcome, `decided_at` actual decision versus `created_at` staff review; correction link and original source digest |
| VendorReconfirmation / VendorConfirmation | Accepted decision/selection/shipment lineage, append current confirmation version, actual `confirmed_at`, supplied `expires_at`, review `created_at`, status/differences/evidence |
| HandoffPolicy | Approved Admin identity/time/reason, exact optional age/PO/deposit/service-doc/exception rules |
| HandoffRevision / HandoffApproval | Exact preparation versus approval times, dependency identity/digests, private byte identity; current eligibility derived afresh |
| HandoffEvent | Actual `occurred_at` versus recording `created_at`, recorder, handed_to_operations / booking_requested / booking_confirmed, vendor reference, correction link and source evidence |
| MailDispatch / FollowupPlan | Provider acceptance/observation/uncertainty and reminder stop remain independent transport/attention evidence |
| Inquiry/audit | Explicit hold/close/reopen reasons and actors, lifecycle generation; reopening renews review, no automatic commercial approval |

Count the latest decision within the intended exact quote lineage; do not double-count corrections/superseded quotes. Do not call quotation value realized revenue or estimated margin realized profit. A historical confirmed booking record and current release eligibility are separate facts. Private financial/export/source fields must remain subject to existing staff permissions. Pilot must verify live mailbox, scheduler/queue, backup/restore, actual workflow policy, downloads/accessibility and operational handoff with company-authorized participants.

Stop after Phase 10. Phase 11 requires separate authorization.
