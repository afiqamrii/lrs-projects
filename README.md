# LRS · Phases 1–11
A company-operated logistics workspace built with Laravel 13, Blade, Tailwind CSS 4 and PostgreSQL. Staff access and the vendor directory now extend to clients, manual inquiry intake, private supporting documents, deterministic clarifications, and immutable shipment confirmations. Phase 3A is complete: anonymous customer intake now enters the same staff workspace. Phase 3B adds private local extraction/selective OCR, source proposals, human review and usage accounting. Phase 4 adds agent-selected vendor RFQs, exact approvals and manual dispatch evidence. Phase 5 adds pinned Outlook connection, explicit approved dispatch, incoming customer/vendor mail, exact revision matching and operational recovery. Phase 6 adds reviewed vendor quotations, deterministic equivalent-cost comparison and immutable agent selection. Phase 7 adds staff-controlled markup, customer quotation PDFs, immutable exact approval and explicit sending through the same outbox. Phase 8 adds explicitly approved bounded follow-ups, business-day calendars, response cancellation and an internal attention queue through the same outbox. The professional local preview uses explicitly fictional business records. Phase 9 adds Gmail OAuth, verified aliases, provider-specific MIME/draft/history behavior and multiple incoming connections with one pinned outbound default through the same approved workflows. Phase 10 adds exact client decisions, vendor rate/capacity reconfirmation, configurable readiness, private approved handoffs and actual vendor booking evidence. Phase 11 adds staff operational reports, defined currency/outcome/AI metrics, safe CSV, observed Admin health, emergency outgoing pause, bounded recovery and an executed isolated encrypted backup restore. Live AI, Microsoft and Google credentials remain unconfigured locally. The planned local build is complete; production readiness still requires the company pilot, real provider/infrastructure checks and a deployment target.

The original baseline is [LRS_Phase_1_Codex_Prompt.md](LRS_Phase_1_Codex_Prompt.md). The Phase 2 and 3A briefs are preserved in [docs/PHASE_2_BRIEF.md](docs/PHASE_2_BRIEF.md) and [docs/PHASE_3A_BRIEF.md](docs/PHASE_3A_BRIEF.md). Current architecture, verification, limitations, manual acceptance and recommended next-phase Codex settings are in [docs/HANDOFF.md](docs/HANDOFF.md).


## Functions developed and complete workflow

| Area | Working functions |
|---|---|
| Staff and foundation · Phase 1 | Login/reset/profile, active Admin/Agent access, staff administration, company settings, audited vendor/contact directory and real overview counts |
| Inquiry workspace · Phase 2 | Clients/contacts, manual inquiries, LCL/FCL groups and services, owner/deadline queues, private documents, clarifications/customer answers and immutable human-confirmed shipments |
| Website intake · Phase 3A | Anonymous four-step request, immutable source evidence, staff contact assessment and optional controlled receipt/mailbox confirmation |
| Evidence review · Phase 3B | Local PDF/OCR/Office/CSV extraction, source navigation, optional structured AI suggestions, human review/application and shared cost controls |
| Outlook workspace · Phase 5 | Admin OAuth/pinned shared or personal mailbox, encrypted refresh, exact envelope authorization, explicit atomic outbox, draft/attachment verification, honest provider states/reconciliation, selected-folder delta intake, private sources and audited matching |
| Vendor sourcing · Phase 4 | Manual vendor selection, isolated RFQ drafts, To/CC and disclosure/file selection, deterministic LCL/FCL requests, optional AI wording, exact approvals, revision history, private approved downloads and explicit manual-send records |
| Vendor quotations · Phase 6 | Private exact-source capture, separate alternatives/revisions, quotation proposals and explicit commercial review, exact decimal/FX costs, equivalent-scope comparison, final/provisional selection and frozen cost-basis eligibility |
| Client quotations · Phase 7 | Explicit percentage markup, private estimated cost/profit/margin, stated tax and optional services, private customer PDFs, editable email, immutable revisions/exact sender approval and explicit shared-outbox sending/reconciliation |
| Gmail and company connections · Phase 9 | Own-account Google OAuth/verified aliases, exact MIME/private files, separate draft/sent/thread identities, conservative recovery, bounded history intake and cross-source copy review; one new-work default, pinned approvals/conversations |
| Client decision and handoff · Phase 10 | Exact reviewed outcomes/corrections, prompt reminder stop, vendor rate/capacity evidence, Admin service/company prerequisites, private frozen handoff/PDF and separate actual booking records |
| Reports and operating readiness · Phase 11 | Received-date/owner/status/source/vendor reports, current outcomes and native/quoted economics, AI evidence, bounded formula-safe CSV, observed health, emergency pause and isolated backup/private-key restore |
| Follow-ups · Phase 8 | Admin policy versions, exact per-revision activation, automatic/manual review modes, UTC business-day scheduling, cumulative caps, response/eligibility cancellation, uncertain-send holds and owned internal attention tasks |

The working path is **website/manual/Outlook/Gmail intake → assess client/contact and assign staff → review shipment/evidence → approve clarification if needed → authorize and explicitly send or record outside-LRS communication → human-confirm the shipment → select vendors → prepare and approve each separate RFQ → authorize its actual sending envelope → explicitly enqueue → inspect provider/Sent Item evidence → review incoming replies against the exact request revision → capture and review vendor commercial evidence → compare equivalent complete costs → choose an exact version with a reason → preserve the immutable vendor cost basis → explicitly set markup, stated tax and customer terms → save and inspect the private client PDF/email → approve exact pricing, bytes, recipients and sender → explicitly enqueue → inspect provider acceptance/Sent Item evidence → optionally approve an exact bounded follow-up plan → one due reminder through the same outbox → reviewed response stops or staff attention**. Manual copy/download and separate outside-LRS records remain available when no provider dispatch exists. Extraction and AI are optional; the manual path works without credentials. Changing material shipment facts returns the inquiry to review; reconfirmation creates the next immutable shipment and a new sourcing round. Earlier approvals and declared sends remain historical evidence.

## Final workspace quality review and publication

The final review corrected an inquiry crash on incomplete earlier website evidence, active-contact readiness counts, provider status/audit links, CSV NUL handling and draft charge feedback. Shared inquiry tabs, quiet silver/blue navigation, compact phone summaries, reachable account controls and internally scrolling evidence tables complete the existing Apple-inspired structure.

Verification passed **329 complete PostgreSQL tests / 2,931 assertions**, followed by **30 final AI/extraction tests / 247 assertions**. Actual desktop/390px/320px pages and native form/charge/menu behavior were inspected. Read the [review evidence and manual acceptance](docs/phase-11-handoff.md#final-workspace-quality-review) for coverage and limitations.

Source repository: [afiqamrii/lrs-projects](https://github.com/afiqamrii/lrs-projects). GitHub publication and internet deployment are authorized; a compatible live Laravel hosting account/project is still missing. There is no verified public application URL. Use the [rollout runbook](docs/OPERATIONS_RUNBOOK.md#reviewable-rollout-and-rollback) after connecting a target. Local preview data remains explicitly fictional.

## Phase 11 reports, pilot and local operation

Open [Operational reports](http://127.0.0.1:8000/reports) to filter current inquiry/outcome/RFQ/AI evidence and export authorized CSV. Admin opens [Operations health](http://127.0.0.1:8000/operations/health) for actual queue/sync/heartbeat/failure evidence and emergency outgoing pause/resume. Resume does not release old queued messages or reminders automatically.

The [Phase 11 handoff](docs/phase-11-handoff.md) includes metric definitions, the [manual pilot checklist](docs/phase-11-handoff.md#phase-11-manual-acceptance-checklist), actual verification and readiness limitations. The [operations runbook](docs/OPERATIONS_RUNBOOK.md#local-startup-and-verification) contains local startup, correct queues, Windows supervision, recovery, encrypted backup/restore and reviewable deployment/rollback instructions. The full request is [PHASE_11_BRIEF.md](docs/PHASE_11_BRIEF.md).

Original Phase 11 verification: 324 complete PostgreSQL tests / 2,862 assertions, then 27 affected tests / 259 assertions; export checks passed 5 tests / 64 assertions. The subsequent quality review results above are the latest regression evidence. Pint, Composer validation/platform/audit, npm audit, Blade compilation and production assets passed. Audits reported no vulnerabilities. The actual isolated restore verified 52 private files, 34 linked artifacts and 65 encrypted records while preserving paused provider evidence; normal records were not reset.

For this prepared workspace:
~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
~~~
In separate terminals, with the same PHPRC:
~~~powershell
php artisan queue:listen database --queue=mail,health --tries=1 --timeout=330 --sleep=3 --no-interaction
php artisan queue:listen database --queue=extraction,ai,default --tries=1 --timeout=330 --sleep=3 --no-interaction
php artisan schedule:work --no-interaction
~~~

The Windows commands use a listener child-process deadline because this PHP runtime lacks pcntl; see the current runbook for supervision and other targets. Manual preparation/review works with providers and AI unavailable. Local data remains explicitly fictional, demo transport is disabled and existing provider connections are disconnected. No production hosting target, genuine company policy/mailbox credentials, continuous production supervision or off-device backup recovery has been supplied/verified. This system is not production-ready yet.

Stop after Phase 11. Further work is defects or explicitly requested enhancements. Earlier verification notes below are retained historical phase records.

## Phase 10 client decisions and operations handoff

Open **Decision & handoff** in the inquiry workspace. Review the exact client PDF/terms/total alongside the original response, record the formal decision, review vendor rate/scope/dates/capacity evidence, complete the company/service checklist, save a private handoff revision and approve it separately. Actual handoff and booking evidence are separate events; an accepted email or client quotation never confirms a booking.

The extended path is **approved client quote/send → exact human decision → approved vendor reconfirmation message and reviewed reply → readiness evidence → frozen private handoff → separate approval → actual operations handoff → optional approved booking request → actual vendor booking reference/dates/evidence**. Material commercial changes require newly reviewed offer/selection, replacement pricing and renewed client acceptance. Corrections and earlier PDFs remain preserved.

Use the [Phase 10 handoff](docs/phase-10-handoff.md) for [local run instructions](docs/phase-10-handoff.md#local-run-instructions), [manual acceptance](docs/phase-10-handoff.md#phase-10-manual-acceptance-checklist), configured authority/freshness/exceptions, verification limits and Phase 11 reporting events. The full request is [PHASE_10_BRIEF.md](docs/PHASE_10_BRIEF.md). Verification passed 310 PostgreSQL regression tests / 2,712 assertions, then 29 final affected tests / 246 assertions, plus formatting, 180 compiled Blade views and production assets. Browser fixture journeys included explicit booking evidence and a revised rate requiring renewed pricing/acceptance; desktop/390px/320px and private PDF pages were inspected. Realistic local data is explicitly fictional; fixture connections are disconnected/paused, and no real bookings or live email tests occurred. The planned build now ends after Phase 11; see the current handoff.

## Phase 9 Gmail in the same workspace

Admin adds/configures Gmail at **/settings/mailbox**, authorizes the mailbox's own Google account and an existing verified From identity, enables desired incoming connections and chooses one outbound default for new work. Frozen approvals, queued sends and active threads stay pinned. Gmail uses exact MIME/private attachments, distinct draft/sent IDs, bounded history sync and conservative uncertainty recovery through the existing outbox/review/reminder rules. Connecting sends nothing.

Use the [Phase 9 handoff](docs/phase-9-handoff.md) for exact Google scopes/consent requirements, supported accounts/aliases, sync and recovery, [local run instructions](docs/phase-9-handoff.md#local-run-instructions), [manual acceptance checklist](docs/phase-9-handoff.md#phase-9-manual-acceptance-checklist) and verification limits. The full request is [PHASE_9_BRIEF.md](docs/PHASE_9_BRIEF.md). Verification: **292 complete regression tests / 2,558 assertions**, then **28 final affected tests / 208 assertions**; Pint, Composer, compiled Blade and production build passed. Actual fixture browser RFQ/quotation/reminder/reply flows and desktop/390px/320px layouts were inspected. Fixture connections and policies are disabled with their evidence preserved. Live Google and Microsoft remain unconfigured; professional local data is explicitly fictional. These are historical Phase 9 verification results; Phase 10 continues the same approved workflow below.

## Phase 8 approved reminders and staff attention

Admin versions disabled company defaults at **/settings/followups**. Staff opens **Follow-ups** from an exact approved RFQ or client quotation, previews actual recipients/sender, frozen wording/files, working calendar, projected dates, cumulative cap and unchanged expiry, then explicitly activates automatic or review-each-message mode. Manual review creates a due task and requires exact per-message approval.

Only one due stage is claimed. The shared outbox rechecks current eligibility, reply/import state, sync freshness, window and cap before submission. Next dates start from actual previous acceptance. Questions/quotes/declines stop vendor reminders; reviewed exact client responses stop customer reminders without a booking. Out-of-office, unmatched/unreviewed evidence, stale sync and uncertain sends route staff attention. Policy edits preserve cumulative counts.

The [Phase 8 handoff](docs/phase-8-handoff.md), [manual acceptance checklist](docs/phase-8-handoff.md#phase-8-manual-acceptance-checklist) and [local run instructions](docs/phase-8-handoff.md#local-run-instructions) document defaults, UTC/company-timezone rules, recovery and Phase 9/10 hooks. The complete request is [PHASE_8_BRIEF.md](docs/PHASE_8_BRIEF.md). [Open Attention queue](http://127.0.0.1:8000/attention).

Run the web app using the existing setup below. In separate terminals use `php artisan queue:work database --queue=mail --tries=1 --timeout=300` and `php artisan schedule:work`. All automation remains disabled until company policy approval and explicit per-parent activation.

Verified: **266 PostgreSQL tests / 2,357 assertions**, followed by **3 final affected tests / 37 assertions**; isolated browser reminder/response-stop flow, desktop/390px/320px and keyboard/error states; Pint, Composer, Blade syntax and production build. Live Microsoft remains unverified.

Phase 11 now supplies reporting and the pilot/operations package; payment and invoicing processing is not implemented.

## Phase 7 client pricing, PDF and approved sending

Open **Client quotation** in the inquiry workspace. Explicitly choose a current final vendor cost basis, set/confirm markup and stated tax, edit customer descriptions/contacts/terms/email, and save a new revision. Inspect the private customer PDF alongside staff-only estimated costs/profit/margin. Approve the exact bytes, email, recipients and actual sender, then separately enqueue through the existing outbox.

Drafts remain usable without Microsoft or AI configuration; missing connection/readiness explains why final release is blocked. Quotes preserve source/FX/quantities/terms and PDF checksums. Changed pricing/content creates a new revision; later scope/rate changes or expiry block release. Provider acceptance/Sent Item observation does not mean delivery, reading or booking. Optional services stay outside the total.

The [Phase 7 handoff](docs/HANDOFF.md), [manual acceptance checklist](docs/HANDOFF.md#phase-7-manual-acceptance-checklist), [local run instructions](docs/HANDOFF.md#phase-7-local-run-instructions), [pricing rules](docs/HANDOFF.md#supported-pricing-and-expiry-rules) and [Phase 8/10 hooks](docs/HANDOFF.md#phase-810-consumption-hooks) document the exact contracts and limitations. The complete request is [PHASE_7_BRIEF.md](docs/PHASE_7_BRIEF.md). Current preview: [client quotation](http://127.0.0.1:8000/inquiries/8/quotations). Dompdf 3.1.6 is the new focused PHP dependency; install from the updated lock file.

Verified: **223 tests / 2,142 assertions**, followed by **20 affected tests / 134 assertions** after the final stale-form fix; browser fixture send and stale-rate block; desktop/390px/320px inspection; one-page and three-page PDF visual checks; Pint, Composer, Blade and production build. Live Microsoft and physical download saving remain manual verification.

Phase 9 now adds Gmail to those shared workflows. Phase 10 adds human-reviewed client decisions and controlled booking evidence; automatic acceptance/booking, payment processing and invoicing remain unimplemented.

## Phase 6 vendor quotation review and selection

Staff now capture private vendor email/document/manual evidence, review exact commercial versions, compare equivalent required costs, and record a reasoned final selection or provisional preference. Open **Vendor quotations** within an inquiry. The saved cost basis freezes original source, shipment/RFQ/offer versions, charge calculations, explicit FX, commercial conditions and reviewer/selector evidence. Unknown costs, missing FX, expiry and stale versions block pricing through the central Phase 7 gate.

The latest user preference is **realistic fictional data** until genuine company/mailbox details are available. The local Business preview shows professional .example clients/vendors, five simulated website/email inquiries and six initial quotes plus a browser-captured pending alternative. All are labelled fictional. Existing QA evidence is preserved; directory samples stay labelled even when switching to real queues. Select **Real records** in Company settings or run lrs:sample-workspace --real to default to genuine incoming records without deleting samples. No real Outlook message or market FX rate is claimed.

Complete flow: **website/manual/connected-Outlook intake → client/contact assessment → shipment/evidence review → clarification if needed → human-confirmed shipment → separate vendor RFQs and exact approval → explicit approved sending or recorded outside-LRS communication → exact reply matching → private offer capture → proposal/manual commercial review → deterministic scope-equivalent comparison → reasoned final vendor selection → immutable cost-basis handoff**. Optional AI never approves prices or chooses the vendor.

Verification: **202 PostgreSQL tests, 2,003 assertions passed**, including real concurrent PHP saves/selections; local browser stories and desktop/mobile inspection are recorded in the handoff. Live Microsoft and live AI remain unconfigured. Phase 7 now consumes this contract for markup, customer PDF/email, exact approval and explicit sending. Phase 8 now adds approved reminders; formal client acceptance automation and booking remain later phases.

Read the [Phase 6 manual acceptance checklist](docs/HANDOFF.md#phase-6-manual-acceptance-checklist), [local run instructions](docs/HANDOFF.md#phase-6-local-run-instructions), [calculation rules](docs/HANDOFF.md#deterministic-calculation-rules) and [Phase 7 consumption contract](docs/HANDOFF.md#phase-7-consumption-contract). Current sample comparison: [local vendor offers](http://127.0.0.1:8000/inquiries/8/offers).

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
~~~

Manual quotation review needs no queue. For local extraction/optional AI/configured Outlook, run the existing worker in a separate terminal:

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan queue:work database --queue=mail,extraction,ai,default --tries=2 --timeout=330
~~~

If PostgreSQL is stopped, start the prepared cluster as described below. Preserve the existing .env and populated lrs database. Install the updated Composer lock file for the focused Dompdf renderer; add sample data only through the explicit local/testing lrs:sample-workspace --apply command. The planned build now ends after Phase 11; see the current handoff.

## Phase 5 Outlook and real intake

Admin setup is at **/settings/mailbox**. Staff use **/mail**, **/mail/outgoing**, an inquiry's **Email timeline**, and each approved request's **Outlook preview & dispatch history**. Settings show the pinned tenant/account/target, actual send mode, import boundary, selected folders, connection/sync/error state and audited recovery. Admin configuration and Microsoft authorization are required for genuine email.

New website submissions and connected Outlook are the chosen real data sources. **/inquiries** and **/mail** use the Company settings workspace mode (professional fictional preview locally, or real records); explicitly choose labelled fixtures to review existing synthetic acceptance examples. Overview inquiry totals and its review queue also show real records. Older reserved-test-address inquiries and future optional sample commands retain explicit fixture labels. The acceptance fixture transport is now disconnected/disabled; no genuine inquiry or Microsoft email has been supplied yet. Records across sources are retained separately. Same-email matches are suggestions, never automatic client/contact or inquiry merges. New email cases enter Needs review with private original evidence and technical values awaiting human assessment.

Approve exact RFQ content first, then authorize the actual connected From/sender/Reply-To and explicitly enqueue that envelope. Each vendor gets a separate immutable dispatch with its own To/CC and exact file manifest. Connecting or approving alone never sends. A Phase 4 manual send is excluded; a deliberate resend requires a fresh approved revision. Queued jobs recheck current readiness, contacts, approval, files and mailbox identity immediately before provider submission.

Follow **Queued → Preparing → Ready → Submitting → Provider accepted → Sent Item observed**. Delivery/reading remain unconfirmed. Timeout/ambiguous outcomes require reconciliation of the existing provider draft/correlation evidence; they cannot be blindly resent. Revocation/permission failure pauses work; safe rejected preparation can be explicitly recovered after reconnecting. Selected-folder delta synchronization preserves durable pages, cursors and immutable source evidence through repeated pages, moves, outages and deletions.

**Live Microsoft credentials and company mailbox identity are not configured locally.** No genuine email was imported and no real client/vendor test send occurred. The implementation uses delegated authorization-code OAuth with PKCE and encrypted server token refresh; fixture tests remain distinct from actual Exchange/Sent Items verification.

Phase 5 verification: **175/175 PostgreSQL tests, 1,831 assertions passed**, plus Pint, Composer strict validation, Blade compilation and the production build. Actual fixture browser flows covered three separate vendor dispatches, incoming customer/vendor messages, independent website intake, audited matching and desktop/390px/320px layouts. Live Microsoft behavior and normal-browser physical file saving remain manual checks.

See [Phase 5 setup, limits, run commands, recovery and 12-step acceptance checklist](docs/HANDOFF.md#phase-5-manual-acceptance-checklist), the complete [Phase 5 brief](docs/PHASE_5_BRIEF.md), [design system](docs/DESIGN_SYSTEM.md) and [tracker](docs/PHASES.md).

Add the mail queue and scheduler to the existing local processes:

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan queue:work database --queue=mail,extraction,ai,default --tries=2 --timeout=330
~~~

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan schedule:work
~~~

Use additive php artisan migrate --no-interaction and npm.cmd run build. Keep server Microsoft values in .env/secret storage only; .env.example lists OUTLOOK_TENANT_ID, OUTLOOK_CLIENT_ID, OUTLOOK_CLIENT_SECRET, OUTLOOK_REDIRECT_URI and the disabled-by-default MAILBOX_DEMO_ENABLED. The local website is available at http://127.0.0.1:8000/request-quote; public internet hosting is a separate setup.

## Phase 4 vendor sourcing

Open **Vendor sourcing** within an inquiry, or `/inquiries/{inquiry}/sourcing`. Incomplete/held/specialist cases show recovery reasons. Only the current eligible human-confirmed shipment can create or approve requests. Search/filter the company directory and select one or several active vendors. Repeating selection reuses each vendor’s request for that shipment.

Prepare one request per vendor: choose one active To and optional same-vendor CC, edit wording, choose response time/currency, explicitly review files and disclosures, then save and review the exact revision. No attachment or client identity/address is included automatically. Conflicting recorded capabilities/shared email/deadlines require a decision; inactive recipients cannot be overridden. Add contacts through the existing directory editor.

Approval freezes the complete subject/body/signature, reply contact, recipients, selected file versions/checksums, approver/time and digest. **Approved — not sent** is separate from **Manually recorded as sent**. Copying and downloading do not record communication. Changes create a fresh draft requiring approval; inactive/changed contacts, changed files, withdrawn readiness and old shipment rounds block current release. Historical evidence survives.

Optional wording uses the existing provider and shared run/inquiry/day caps with a separate strict schema. Staff preview the paid scope and explicitly apply a suggestion as a new draft. Core facts/checklist, recipients and files remain controlled by the application/staff. Unchanged successful wording is reused without another paid call. Disabled/failing AI leaves deterministic preparation fully usable. Live AI remains unconfigured locally.

The full [Phase 4 brief](docs/PHASE_4_BRIEF.md), [manual checklist and local run instructions](docs/HANDOFF.md#phase-4-manual-acceptance-checklist), [design rules](docs/DESIGN_SYSTEM.md) and [phase tracker](docs/PHASES.md) are maintained. Final regression: **132/132 PostgreSQL tests, 1,457 assertions**; Pint, Composer validation, Blade compilation and production build passed. Browser evidence/limitations are recorded in the handoff. The QA browser canceled file saving even for an independent synthetic download; approved attachment response/bytes/checksum and guest denial passed. Normal-browser file saving remains a manual acceptance check.

Implementation stops after Phase 10. Intake, sourcing, reviewed vendor costs, client pricing/PDF/email, exact approved sending/follow-ups, formal human decisions, vendor reconfirmation and private controlled handoff/booking evidence are complete locally. Phase 11 reporting/pilot/production work remains unimplemented.

## Phase 3B extraction and review

Open an inquiry’s **Extraction & review** tab. Select private documents for local extraction, inspect page/row/cell evidence, then select exact passages and preview the AI scope. One structured request returns permitted field proposals and an unreviewed summary. Accept/correct/reject/leave unresolved, preview all changes and apply a recorded review; existing human review still determines Ready.

Source/revision changes block stale overwrite. Material corrections to a confirmed shipment create the next Draft needing reconfirmation. Originals, classifications, approved clients and old confirmed snapshots remain. AI cannot select vendors, set commercial prices or send messages.

**Live AI is disabled/unconfigured here.** No API key/runtime model/current commercial rates are set; no live provider call was made. The adapter/fake-provider tests and clearly labelled demo proposals work without credentials. Admin configures an explicit current model, structured-output/account-access checks and dated USD rates/run-inquiry-daily caps at /settings/ai. OPENAI_API_KEY belongs only in server secrets/.env. Unknown costs stay unknown and uncertain paid outcomes remain held for Admin reconciliation.

OS dependencies: genuine Poppler (pdftotext/pdfinfo/pdftoppm), Tesseract with eng/osd data, PHP ZIP/GD. PhpSpreadsheet 5.10.0 is locked. Configure tool/language paths and bounds with .env.example. Prepared Windows tools are under ignored .tools; install them on other machines.

Run alongside the web process:

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan queue:work database --queue=extraction,ai,default --tries=2 --timeout=330
~~~

Database retry_after defaults to 450 seconds. Extraction uses bounded child PHP; paid jobs have one attempt/no automatic provider retries. Check abandoned runs using lrs:recover-processing --age=10 --dry-run, then run without --dry-run after checking worker state.

Optional fictional acceptance (EXTRACTION_DEMO_ENABLED=true only locally/testing):

~~~powershell
php artisan lrs:demo-extraction --extract
php artisan queue:work database --queue=extraction --stop-when-empty --tries=2 --timeout=330
php artisan lrs:demo-extraction --proposals
~~~

The [current handoff](docs/HANDOFF.md) documents exact installation, limits, states, schema/prompt versions, source policies, rate/budget/retry rules, measured fixtures and the ten-step manual acceptance checklist. The full [Phase 3B brief](docs/PHASE_3B_BRIEF.md) is preserved. Local OCR and fake-provider verification are reported separately. Complex layout/handwriting/diagrams/ambiguous measurements require manual review; originals remain unscanned.

## Phase 3A customer inquiry
Open [the local customer page](http://127.0.0.1:8000/request-quote). Staff login stays at /login; the root still leads to the authenticated overview. The public page guides Contact → Shipment → Documents → Review, accepts technical unknowns, preserves mode-switch details and gives a session-bound receipt reference. It requests human assessment, not an instant rate or booking.

Website submissions create ordinary Needs review inquiries. Submitted contact/company and immutable source evidence are separate from the approved directory. Staff must explicitly resolve the client/contact, assign active ownership and a deadline, clarify gaps and confirm a complete shipment under the existing Phase 2 gates. Matching an existing email never attaches or changes an approved contact automatically. Agents can copy the customer link; localhost is labeled Local preview only.

Admin settings at /settings control intake availability, public introduction/contact/privacy version, default active owner and the fixed approved receipt wording/enablement. Mail is **disabled locally**. Private public uploads default to 5 files × 10 MiB each, 30 MiB combined, subject to stricter existing/PHP limits. Supported types are PDF, JPEG/PNG, DOCX, XLSX and UTF-8 CSV; classification is a suggestion. Format validation is real, but files are visibly **unscanned**. Receipts/verification give no private file or staff access.

See [Phase 3A handoff and manual acceptance](docs/HANDOFF.md#phase-3a-manual-acceptance-checklist) for the 11-step story, states, routes, concurrency/security tests and limitations.

## Phase 3A configuration and queue
No dependency upgrade is required. On an existing installation, back up the database and private originals, run `php artisan migrate` (forward migration), then build assets. Never reset a populated database. Phase 3A adds `2026_10_04_073826_add_public_intake_foundation.php`; normal local data is on migration batch 3.

Safe defaults from .env.example:
```dotenv
PUBLIC_INTAKE_URL=http://localhost:8000/request-quote
PUBLIC_VERIFICATION_EXPIRY_HOURS=24
PUBLIC_UPLOAD_TOTAL_KB=30720
PUBLIC_RECEIPT_MAILER=null
PUBLIC_RECEIPT_LOCAL_CAPTURE=false
```
For a company-approved deployed link, set APP_URL and PUBLIC_INTAKE_URL to the same HTTPS application host, with the latter ending /request-quote. Clear configuration after changes. The company website can link to that URL; nothing is deployed by this phase.

Optional controlled local SMTP capture uses your own capture tool at loopback port 1025, `PUBLIC_RECEIPT_MAILER=smtp`, `PUBLIC_RECEIPT_LOCAL_CAPTURE=true`, and the existing MAIL_HOST/PORT credentials. Admin must approve wording and explicitly enable receipt mail. Log/array/failover transports are not counted as external mail. Do not enable real customer sends just to run acceptance.

Start a database queue worker **only when testing/enabling the approved transport**:
```powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini' # prepared workspace
php artisan config:clear
php artisan queue:work database --tries=3 --timeout=30 --backoff=10
```
The worker must use the same environment/configuration as the web process. Production requires a supervised worker and restart after releases. Jobs dispatch after commit; failures preserve inquiries. A dispatching/failed external attempt is not blindly retried: reconcile uncertain provider outcomes before staff resend. Provider acceptance does not prove delivery. Tokens live only as hashes in storage, expire after the configured interval and are consumed by explicit POST, never GET. Mailbox access does not establish customer identity/readiness.

Align PHP upload_max_filesize/post_max_size and reverse-proxy request limits with the displayed bounds (e.g. 10M per file, at least 32M post, max_file_uploads ≥ 5; allow headroom for form/multipart data). The UI clamps to stricter PHP/Phase 2 limits. An upstream rejection needs an accessible matching 413 response at that layer. Enable fileinfo/Phar; keep storage/app/private outside the web root. Configure only known trusted proxies in a deployed environment; forwarded client IP headers are not trusted by default.

## Phase 2 workspace
Admin and Agent share client/inquiry operations regardless of owner. Staff/settings remain Admin only. New functional screens: /clients, /clients/create, /clients/{id}, contact/edit/archive forms; /inquiries, /inquiries/create, /inquiries/{id}/edit, and the inquiry workspace with Overview, Shipment, Documents and Activity. It links private document previews, editable clarifications, manual communication records and immutable confirmed versions. The handoff enumerates all methods/routes.

Capture what is known in a draft, select an actual client contact, assign active staff and a response deadline, then review missing requirements. Confirm general-cargo readiness only after the mode/scope requirements are complete. Material changes preserve the last confirmed snapshot and open a new working revision. Notes, deadline and active owner reassignment do not create shipment revisions. Stale saves/actions are rejected.

Clarifications and vendor RFQs can now be sent through the Phase 5 approved Outlook flow: approve exact content, authorize the actual sender envelope and explicitly enqueue. Approval and Copy alone never send. Outside-LRS communication can still be separately recorded when no provider dispatch exists. Customer answers remain separate evidence. The optional fixed Phase 3A receipt/mailbox-confirmation message and Phase 8 bounded reminders each require their specific explicit approval. Client quotations now use Phase 7 exact approval and explicit dispatch. Formal client outcomes and booking remain future phases.

Private originals live in storage/app/private/inquiry-documents. Do not expose this directory or create a public storage symlink for it. Supported types: PDF, JPEG/PNG, DOCX, XLSX, UTF-8 CSV. Actual contents and format structure are validated. Defaults: 10 MB/file, 5/upload, 20/inquiry including archived evidence. Configure INQUIRY_UPLOAD_MAX_KB, INQUIRY_UPLOAD_BATCH_LIMIT, INQUIRY_DOCUMENT_LIMIT, and align PHP/web-server limits. fileinfo and Phar are required. Metadata includes uploader/time/MIME/size/checksum/classification. Files are never auto-extracted or used to alter shipment requirements.

Optional fictional examples: php artisan lrs:demo-inquiries (local/testing only, existing active staff required). This is idempotent and creates incomplete drafts, never approvals/messages. The default seeder remains empty.

## Updating this prepared workspace
The additive Phase 2–5 migrations have run locally; existing records and all earlier migration batches were preserved. On another existing installation, back up database/private originals, then run:
```powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini' # prepared workspace only
php artisan migrate
npm.cmd run build
.\scripts\local.ps1 serve
```

Do not run migrate:fresh against business data. Existing local QA credentials remain in ignored .tools/browser-credentials.json. The prepared browser walkthrough uses clearly labeled Phase 2 Browser QA records. Normal installation instructions follow.

## Prerequisites
- PHP **8.5** (verified with 8.5.2), Composer 2.8+; enable PDO PostgreSQL (`pdo_pgsql`), mbstring, intl, DOM/XML, ctype, curl, fileinfo, openssl and tokenizer.
- PostgreSQL **17** (verified with 17.11); two separate databases: `lrs` and `lrs_test`.
- Node 24 LTS and npm (verified with 24.13.0 / 10.9.1). Node is asset tooling only.
- No Redis, remote fonts, mail account or paid UI kit is needed.

## New installation
Run from the repository root:
```sh
composer install
cp .env.example .env
```
PowerShell: use `Copy-Item .env.example .env` instead of `cp`.

Create a PostgreSQL login with a password you choose, and give it ownership of both databases. From an administrative `psql` session:
```sql
CREATE ROLE lrs LOGIN;
\password lrs
CREATE DATABASE lrs OWNER lrs;
CREATE DATABASE lrs_test OWNER lrs;
```
The `\password` command prompts securely. Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE=lrs`, `DB_USERNAME=lrs` and `DB_PASSWORD` in the ignored `.env`. The example contains no credentials.

```sh
php artisan key:generate
php artisan migrate
php artisan lrs:admin
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```
Open [the local workspace](http://127.0.0.1:8000). The admin command prompts for name, email, password and confirmation; password entry is hidden. Use 12+ characters containing uppercase, lowercase and a number. No account or password is seeded by default. Public registration is disabled.

For asset development, run `npm run dev` in a second terminal. `composer run dev` starts Laravel's local server. No worker is needed for synchronous Phase 1 password notifications; the database queue is configured for future use.

## This prepared Windows workspace
A private portable PostgreSQL instance was prepared under the ignored `.tools` directory, listening only on `127.0.0.1:55432`. Development/test credentials and the application key are in ignored local environment files. PHP PostgreSQL extensions are enabled in a workspace-only `.tools/php.ini`; the global Laragon configuration was preserved.

Use:
```powershell
.\scripts\local.ps1 admin
.\scripts\local.ps1 serve
.\scripts\local.ps1 test
```
Create your own admin using the first command. The explicit browser QA account has a generated password in `.tools/browser-credentials.json`; it is a local verification fixture, not a production default. Professional fictional vendors/clients have permanent sample labels; old Demo/Browser QA directory entries are retained inactive as historical evidence.

If the portable database is stopped:
```powershell
& .tools/pgsql/bin/pg_ctl.exe -D .tools/pgdata -l .tools/postgres.log -w start
```
To stop it deliberately:
```powershell
& .tools/pgsql/bin/pg_ctl.exe -D .tools/pgdata -m fast -w stop
```
The `.tools` runtime is not part of the repository or a substitute for the normal prerequisites on another machine. The local helper also works with a normally configured PHP installation.

## PostgreSQL tests
Copy `.env` to `.env.testing`, set `APP_ENV=testing`, `DB_DATABASE=lrs_test`, and configure test database credentials. Keep the generated application key. Tests must use a database dedicated to tests; they migrate and roll back its records. Never point tests at business data.

```sh
php artisan test --compact
php vendor/bin/pint --format agent
npm run build
composer validate --strict
```
`phpunit.xml` forces PostgreSQL and `lrs_test`. The base test case refuses any connection other than PostgreSQL or a database name that does not end in `_test`. SQLite is not used. In this Windows workspace, `scripts/local.ps1 test` exports the local PHP configuration so Laravel's test subprocess inherits PostgreSQL support.

## Password setup and development mail
Admins create staff accounts at `/staff/create`. Active new staff receive a password setup link; admins can resend it from the account screen. Inactive staff must be activated first. Links expire after 60 minutes, with Laravel broker throttling plus request rate limits.

Default development mail is Laravel's log transport, captured in **`storage/logs/private-mail.log`**, accessible only on the local filesystem. It contains password setup links and is developer-only; do not publish or expose it to application users. Passwords and reset tokens are excluded from audit entries and UI feedback. Keep `storage` outside the web root; the server document root is `public`.

Alternatively, run your existing local mail capture tool and set:
```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
```
No real mailbox is connected. After environment changes run `php artisan config:clear`. Production transport and hardening belong to later rollout work; never use development mail capture as a public service.

## Optional fictional data
```sh
php artisan lrs:demo
```
This explicit, idempotent command adds four visibly fictional vendor records only in local/testing environments. It never creates staff credentials. The standard database seeder is empty.

## Functional routes
| Area | Routes |
|---|---|
| Authentication | `/login`, `/forgot-password`, `/reset-password/{token}`; POST logout |
| Overview | `/overview` |
| Vendors | `/vendors`, `/vendors/create`, `/vendors/{id}`, `/vendors/{id}/edit`, `/vendors/{id}/status` |
| Contacts | `/vendors/{id}/contacts/create`, `/vendors/{id}/contacts/{contact}/edit` |
| Account | `/profile` |
| Admin staff | `/staff`, `/staff/create`, `/staff/{id}/edit` |
| Admin preferences | `/settings` |

All writes use POST/PATCH forms, server validation and Laravel request forgery protection. Agents are denied direct access to staff/settings routes. Deactivated users are blocked on every authenticated request. Vendors have no deletion route.

## Manual acceptance
- [ ] Install against an empty PostgreSQL database, migrate, build assets and create an admin with the secure command.
- [ ] Sign in/out; request a reset using local capture; follow the link and choose a new password. Check expired/invalid links and repeated attempts.
- [ ] Admin creates an agent, sends/resends password setup, changes role and active status. The last active admin cannot be demoted or deactivated.
- [ ] Sign in as the agent. Create/edit vendors and contacts, change vendor status, and update the agent's own profile.
- [ ] Try direct agent GET/POST/PATCH requests to staff and settings: access must be denied without any mutation.
- [ ] Deactivate staff while they have an existing session; their next request must lose access.
- [ ] Save a vendor without contacts. Check the contact-needed warning. Add multiple contacts; transfer the primary flag; reject duplicate emails and malformed addresses.
- [ ] Save a similarly named branch. Confirm the duplicate warning permits review without blocking the record.
- [ ] Search by company/contact/email/coverage. Combine type/service/status/contact filters. Test clear filters, no results and pagination with more than 12 vendors.
- [ ] Deactivate/reactivate a vendor. Confirm records and history remain. Inspect readable actor/action/field changes; no secret values should appear.
- [ ] Check overview counts and the directory health graphic against actual records, including inactive vendors and a completely empty directory. Follow each graphic legend filter and compare its result count. Update company name/timezone/currency and your profile/password.
- [ ] Review the Apple-inspired silver/white/blue visual system at desktop and mobile widths. Verify login, overview/health graphic, directory, detail and forms. Use keyboard navigation, Escape, focus restoration, native status confirmation, visible focus and bounded table scrolling.
- [ ] Disable JavaScript and repeat sign-in, search and vendor/contact/status forms; server-rendered navigation and confirmations must remain usable.

See [handoff](docs/HANDOFF.md) for actual verification evidence and limitations, [project brief](docs/PROJECT_BRIEF.md) for business rules, [design system](docs/DESIGN_SYSTEM.md) for conventions, and [phase tracker](docs/PHASES.md) for the continuation scope.

## Phase 2 manual acceptance
- [ ] Create/search/edit/archive/reactivate a client; add contacts and transfer the primary flag.
- [ ] Capture incomplete LCL intake with explicit contact, owner/deadline and preserved source. Upload a private packing list and review the gaps.
- [ ] Prepare/edit/approve the exact clarification; Copy must not record communication. Explicitly record a manual communication and the customer answer.
- [ ] Complete mode/scope requirements, review and confirm. Inspect reviewer/time/version evidence.
- [ ] Make a material change; confirm new draft revision, preserved earlier snapshot and required reconfirmation. Inspect client history/audit.
- [ ] Check FCL cargo weight/type/quantity and requested addresses; specialist cargo must remain in review/hold.
- [ ] Try guest/inactive and cross-inquiry private-file access, stale editing, clipboard denial and phone keyboard/navigation/unsaved behavior.

The complete 10-step checklist, actual routes, verification record and next-phase model recommendation are in [HANDOFF](docs/HANDOFF.md). Phase 3B acceptance is recorded in the current handoff.


## Phase 3A manual acceptance
Use clearly labeled local demo contacts/cases and a controlled capture or Mail fake; never send uncontrolled customer messages.

- [ ] Agent copies the link; an anonymous customer opens the branded page. Local URL must say preview only.
- [ ] Submit incomplete LCL cargo with a packing list and unknown volume/date/scope; preserve inputs through steps and validation.
- [ ] Retry the same form key; receive the same reference with one case/file/mail record. A new key can create a separate intended case for the same email.
- [ ] Staff finds Website / Needs review, original contact/route/files/privacy evidence, unassigned owner/deadline and client association gaps.
- [ ] Supply an existing contact email; confirm no public client information, automatic attachment or directory change.
- [ ] Staff explicitly resolves identity/contact, assigns owner/deadline, records clarification/response evidence, edits working details and confirms Ready through Phase 2 gates; original submission remains unchanged.
- [ ] With controlled mail, GET leaves the token unused; explicit POST confirms only mailbox access. Test expiry, reuse and resend invalidation.
- [ ] Disabled/log/unavailable/failing mail retains the inquiry/reference and honest contact/transport state.
- [ ] Guests cannot access staff cases/documents by reference/token. Reject invalid/oversized/count/combined-limit uploads; reselect files after errors.
- [ ] FCL/Not sure and specialist disclosures retain entered details, gaps and human-review requirements. Test keyboard, 320px/390px layout and server-only row-add/submit.
- [ ] Disable intake as Admin; helpful unavailable/contact page replaces submit. Staff/manual email capture still works. Restore the setting.

Phase 3A checklist retained for regression. Current implementation stops after Phase 11; the remaining company/live/infrastructure acceptance is recorded in the current handoff.
