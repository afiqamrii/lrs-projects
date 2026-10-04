# LRS handoff · Phase 5

Phases 1–5 implemented locally on 4–5 October 2026 (Asia/Kuala_Lumpur). Authorization stops after Phase 5. The complete request is preserved in [PHASE_5_BRIEF.md](PHASE_5_BRIEF.md). Previous handoffs below are historical records; their scope statements describe earlier releases.

## Current outcome and real-data decision

One Laravel/Blade/PostgreSQL staff workspace now supports website, manual and Outlook intake; client assessment; private evidence and optional extraction/proposal review; human shipment confirmation; separate selected-vendor RFQs; immutable content approval; actual sender-envelope authorization; explicit outbound dispatch; and incoming customer/vendor reply review. [README](../README.md#functions-developed-and-complete-workflow) maps the complete flow.

The user chose **new website submissions and connected Outlook** as the real data sources. Inquiry and incoming-mail queues default to real records. Labelled fixtures remain separately selectable, preserving existing work. Test-domain website submissions and fixture-transport mail are marked synthetic. The overview's inquiry counts, review queue and inquiry activity also use real records. Older client-directory inquiries with reserved .test contact addresses are explicitly labelled; optional sample commands preserve these labels. No genuine customer/vendor information was fabricated, no records were deleted, and no real vendor/client test messages were sent.

**Microsoft tenant/application/server secret and company mailbox identity are unavailable locally.** Implementation and fixture tests do not constitute live Outlook or production verification. New genuine website submissions can enter the real queue now, at the local URL; public internet hosting is not configured. Real email requires Admin setup and Microsoft authorization described below. Website receipt mail remains independently disabled locally.

## Phase 5 architecture and next-phase contracts

| Stored contract | Responsibility |
|---|---|
| mailbox_connections | Singleton pinned tenant/account/target, send mode, transport limit, connection generation/hash, encrypted tokens and serialized refresh lease |
| mail_oauth_attempts | Hashed one-use state bound to active Admin/session/generation; encrypted PKCE verifier; ten-minute expiry |
| mailbox_folders | Selected incoming/Sent Items folders with separate encrypted opaque cursors, durable pages/offsets, leases, retry counters and explicit import boundary |
| mail_messages + mail_folder_message | Immutable encrypted original source/hash and mailbox + case-sensitive provider-ID deduplication; retained move/deletion evidence; editable, audited match/classification |
| mail_attachments + mail_attachment_documents | Immutable original encrypted metadata, actual private bytes/checksum and recoverable file state; deduplicated links into ordinary inquiry documents |
| mail_envelopes | Immutable approved source plus exact actual From/sender/Reply-To and connection identity; human authorization, digest and time |
| mail_dispatches + mail_events | Atomic outbox, unique logical dispatch UUID, persisted provider draft/thread identity, encrypted upload state, leases/backoff and append-only operational evidence |

Additive migration 2026_10_04_133600_create_outlook_mail_foundation.php ran as **batch 6**. Migration 2026_10_04_154208_label_existing_synthetic_inquiries.php ran as **batch 7**, updating only fixture provenance on older reserved-test-address inquiries; their sources, approvals, shipments, timestamps and audit history are unchanged. Prior batches/records remain. PostgreSQL guards source/envelope/event immutability, frozen dispatch binding, exact source/case lineage and approved clarification content. Existing RFQ approval and confirmed-shipment triggers remain. No dependency or authentication system was added.

Mailboxes manages OAuth/pinned identity/refresh. GraphMail is the bounded Graph adapter; MailboxFixture is explicit local/testing transport. MailRelease consumes existing approval snapshots and the central RfqEligibility/ManageRfq locking/preflight. MailOutbox owns authorization/enqueue/cancel/recovery. DispatchMail prepares/verifies/submits; ReconcileMail observes provider evidence. MailboxSync persists pages before cursor advancement; MailIngest matches/classifies/creates review cases; ImportMailAttachments reuses actual InquiryUploads/StoreInquiryDocuments validation and private storage.

Phase 6 should consume **matched MailMessage records with an exact rfq_revision_id**, original source_hash/source and linked private inquiry documents. classification quote/question/decline is a suggestion or audited staff classification, never an approved commercial offer. Follow revision → RFQ → vendor/sourcing round/confirmed shipment. Use oldRevision() to flag earlier requests. Preserve original message/revision association and review history when creating later offer proposals. Bounce, out_of_office and noise are operational evidence, not commercial responses. No offer/pricing/comparison/client quote/reminder/booking implementation exists.

## Sending states, release and recovery

Queued → Preparing → Ready for submission → Submitting → **Provider accepted** → **Sent Item observed**. Failed, Outcome uncertain and Cancelled before submission are separate. Graph 202 means accepted for processing; neither a Sent Item nor a successful job proves delivery or reading. Later bounce mail is separate evidence. There is no exactly-once-delivery claim.

Content approval does not send. Connecting does not send approved backlogs. Staff first authorize the exact connected envelope, then explicitly enqueue that immutable authorization. Each vendor has its own draft and To/CC, manifest and UUID. A changed mailbox/material envelope requires renewed authorization; routine token refresh does not. The approving/envelope/requesting staff must remain active. Fixture inquiries require fixture transport; real inquiries require the real company connection. Incoming automatic and staff associations enforce the same data provenance. The existing central preflight checks digest, current human-confirmed shipment, readiness/hold/closure, active vendor/contact, deadlines, actual file availability/checksums and envelope both at enqueue and immediately before submission. Editing needs a new draft/approval.

An approval manually recorded as sent in Phase 4 cannot be sent through Outlook. An active Outlook outbox blocks a second action key and an outside-LRS declaration. A deliberate resend requires a separately approved revision. Cancelling before the frozen submission point releases the logical outbox but may leave an unsent provider draft; inspect Outlook Drafts before another request. After submission begins, cancellation/disconnection cannot recall the HTTP request.

Draft creation intent is persisted before HTTP; the exact immutable provider draft ID is persisted before submission. Draft preparation verifies actual To/CC/BCC/subject/body/From/Reply-To/sender where supplied and actual attachment bytes/MIME/checksums. Unexpected/duplicate/changed files or provider edits block sending. A creation timeout without a persisted ID becomes uncertain; no automatic replacement draft. Recovery inspects correlation evidence rather than creating another message.

A timeout/crash/5xx after a possible send becomes uncertain. Reconciliation checks the persisted immutable ID and dispatch UUID extended property in the target mailbox and configured account Sent Items. A draft remaining or a temporarily missing Sent Item does not prove failure. Automatic observation attempts are bounded at ten; unresolved outcomes need staff evidence review and explicit reconciliation. **Never click a new send or create a replacement to work around uncertainty.** No safe automatic resend of uncertain messages exists.

Provider 401/403 pauses the connection; reconnect/resume after correcting credentials/Exchange rights. Explicit recovery of a known rejected operation rechecks the current release and reuses its persisted draft. 429 respects Retry-After, including long delays; preparation has at most six automatic attempts. Expired large-upload sessions are recreated only after inventory proves the approved file is absent. No HTTP call holds the business database transaction open.

## Microsoft setup and supported identity

1. Register a **single-tenant confidential Web app** for the company in Microsoft Entra. Supply its tenant UUID/application UUID/server client secret in server environment only.
2. Register the exact OUTLOOK_REDIRECT_URI. Local development uses http://127.0.0.1:8000/settings/mailbox/callback; deployment needs the actual stable HTTPS callback/APP_URL and secure session settings. Redact OAuth callback query parameters/code/state in reverse-proxy/access logs. The application does not flash these values or log provider bodies/tokens.
3. Use delegated permissions offline_access, User.Read, Mail.ReadWrite and Mail.Send. offline_access permits refresh; User.Read verifies the expected signed-in identity; Mail.ReadWrite creates/reads drafts, folders/messages/attachments; Mail.Send sends approved drafts.
4. Shared/delegated access additionally requests User.ReadBasic.All for pinned target identity checks, Mail.ReadWrite.Shared and Mail.Send.Shared. Follow tenant consent requirements. No application-wide mailbox permission is requested.
5. In Admin **Outlook connection**, pin expected account object UUID/email, target mailbox object UUID/email, company display From name, personal/shared type and Send As/Send on Behalf. For a personal mailbox, account and target must match and use Send As. Record an administrator-verified message-size limit and explicit import start (now or at most 90 days earlier).
6. Shared mailbox draft/send through /users/{target} requires actual **Full Access** and the chosen Exchange **Send As or Send on Behalf** rights. OAuth consent alone does not grant them. Confirm where Exchange stores target/account Sent Items; optionally inspect authorized-account Sent Items too. The app checks target/folder access but does not invent send-right autodiscovery.
7. Authorize Microsoft using the pinned account. State is Admin/session/generation bound and one-use; code flow uses PKCE S256. /me and target identity must match the pinned UUID/email before encrypted tokens are committed. Supported target Inbox/Drafts/Sent Items access is checked.
8. Do a controlled live test only with explicitly authorized company test recipients, recording From/sender/Reply-To, attachment bytes, 202 and delayed Sent Items, account copies, revocation/refresh and throttling. These remain **unverified locally**.

Send As displays the company target as From/sender. Send on Behalf displays the target From with the authorized account as visible sender. Graph sets sender; the app does not forge it. Reply-To follows the exact approved company reply contact and is separately displayed/authorized. Provider identity mismatches are blocked where visible during preparation or flagged on observed evidence.

Current implementation targets the commercial Microsoft Graph/Outlook endpoints. Sovereign cloud endpoints, personal Microsoft consumer accounts, Gmail, webhooks and application-permission access are outside this phase.

Server keys without secret values:

~~~dotenv
OUTLOOK_TENANT_ID=
OUTLOOK_CLIENT_ID=
OUTLOOK_CLIENT_SECRET=
OUTLOOK_REDIRECT_URI=http://127.0.0.1:8000/settings/mailbox/callback
MAILBOX_DEMO_ENABLED=false
~~~

Keep .env and private storage out of source control. Retain APP_KEY and its supported rotation history with backups; encrypted sources/tokens/cursors/envelopes/upload URLs depend on it. No real Microsoft token/client secret is stored in docs, browser storage or URLs.

## Intake, files and catch-up

Only explicitly selected incoming folders create requests/replies. Default target Inbox is incoming and target Sent Items is reconciliation evidence. Company-originating messages are outgoing evidence even if moved into an incoming folder. Sent Items never create new customer inquiries.

Each folder durably persists an encrypted page and next message offset before advancing its opaque cursor. The expected HTTPS Graph host, pinned mailbox/folder path and case-sensitive IDs are enforced. Replayed pages, folder moves and provider removals retain the same original business evidence; removals never delete a case. No filtered initial delta shortcut that silently caps historical results is used.

Original source retains sender/recipients, subject/body, relevant threading headers, internet/provider IDs and dates. Sources are encrypted with immutable digests and separate from working shipment fields. Browser HTML is escaped; readable text strips active blocks, all output is escaped, no active source link/remote image/script loads. Private downloads require active staff, nested ownership, matching checksum, no-store and sandbox headers.

New unfamiliar nonreply requests become **Needs review**, with unresolved client/contact and unknown shipment values. Staff assess identity explicitly; same-email website/email records remain separate and appear as related-case hints. Known thread + expected approved recipient can associate exact replies; unknown senders, multiple approved reference candidates and weak subject/sender evidence enter review. Clarification acceptance enters Needs client information; a matched customer reply returns it to Needs review without altering shipment/version. Automated evidence never generates reply loops or automatic acknowledgment. Public receipt behavior is unchanged.

Private supported files: PDF, JPEG/PNG, DOCX, XLSX, UTF-8 CSV, using existing actual-format checks. Defaults: 10 MiB per file, existing 20-document inquiry/message count bound, 30 MiB total per imported message (inline included), 1 MiB original message-source bound. Reference/item/external-link/unsafe/oversized/count-excess attachments remain visible as unsupported; listing/download/association failures remain partial/failed with explicit retry. Files remain unscanned. New inquiry document copies retain mailbox_import provenance, original metadata/hash and private-byte checksum; retries cannot duplicate files. Existing extraction is staff-selected and proposals remain human-reviewed.

Outbound: <3,000,000-byte files use fileAttachment POST; ≥3,000,000 bytes use sequential 1 MiB upload-session chunks for supported own personal mailboxes, within existing 10 MiB file/25 MiB RFQ preparation bounds and the Admin-confirmed encoded tenant limit. Shared/delegated mailboxes block files ≥3,000,000 bytes because of Microsoft's documented issue. Replacement/smaller files require a new reviewed manifest; no public URL or silent file omission. Upload-session URLs are encrypted and never receive a Graph bearer header.

Expired delta cursor resync uses a bounded seven-day overlap after last success, respecting the original boundary. Gaps older than 90 days/repeated cursor expiry pause for explicit Admin resync; older imports need a separately controlled migration. Catch-up pauses every 5,000 messages in one unfinished cycle with a retained next boundary for explicit resume. Six consecutive sync errors pause automatic retries. Admin enable/retry/resync is audited with reason; an active page lease blocks reset. Failure retains evidence and cursor rather than silently skipping a message.


Provider-specific decisions were checked against current Microsoft documentation: [authorization code/PKCE](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [shared send identity/Exchange rights](https://learn.microsoft.com/en-us/graph/outlook-send-mail-from-other-user), [draft send and 202](https://learn.microsoft.com/en-us/graph/api/message-send?view=graph-rest-1.0), [immutable IDs and delayed Sent Items](https://learn.microsoft.com/en-us/graph/outlook-immutable-id), [folder delta/cursors](https://learn.microsoft.com/en-us/graph/delta-query-messages), [attachment methods/shared limits](https://learn.microsoft.com/en-us/graph/outlook-large-attachments) and [throttling/Retry-After](https://learn.microsoft.com/en-us/graph/throttling). These references establish provider contracts; live tenant behavior still needs controlled verification.

## Local run instructions · Phase 5

Existing Windows setup uses ignored .tools/php.ini and portable loopback PostgreSQL on 127.0.0.1:55432. Start PostgreSQL/web/build as in the earlier README setup; do not reset the existing lrs database. Use additive migrations only. Tests use guarded, separate lrs_test.

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
~~~

Run separate terminals for the worker and scheduler:

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan queue:work database --queue=mail,extraction,ai,default --tries=2 --timeout=330
~~~

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan schedule:work
~~~

Mail jobs have their own tries=1; mail maintenance schedules bounded retries/lease recovery, so do not blindly retry failed/uncertain mail through queue:retry. Database retry_after remains 450 seconds, exceeding job timeouts/leases. lrs:mailbox-tick runs every minute, requeues due preparation/reconciliation/folder sync and recovers expired claims. It makes no provider HTTP request in the scheduler process. Workers must run for the “queued” state to progress. Token refresh uses an expiring database lease; simultaneous refresh attempts defer rather than race rotating tokens.

Manual maintenance/isolated local checks:

~~~powershell
php artisan lrs:mailbox-tick
php artisan queue:work database --queue=mail --stop-when-empty --tries=1 --timeout=300
php artisan test --compact
php vendor/bin/pint --dirty --format agent
php .tools/composer.phar validate --strict
php artisan view:cache
npm.cmd run build
~~~

Fixture-only acceptance: enable MAILBOX_DEMO_ENABLED locally/testing, reload config, explicitly select **Connect fixture transport** on Admin settings. Connecting alone creates no outbox. Select/approve fictional vendors, authorize exact fixture envelopes, explicitly enqueue, run mail worker/maintenance, then use **Load synthetic incoming mail**. Fixture transport never calls Microsoft. Disable the flag afterwards; retained fixture/history remain clearly labelled. Do not select fixture mode over a real connection during business operations.

## Phase 5 verification record

Final complete regression on 5 October 2026 (Asia/Kuala_Lumpur), with the fixture flag disabled and the final overview correction: **175/175 PostgreSQL tests, 1,831 assertions passed**. This includes the prior separate-process concurrency tests and 43 Phase 5 stories. The final affected real/fixture/overview check also passed independently (1 test / 17 assertions). There are no remaining failing checks.

Pint fixed changed PHP files; Composer strict validation, Blade compilation, additive migration/status, scheduler/route registration and production Vite build passed. Tests cover PKCE/session/state/identity failure, encrypted secrets/refresh races/revocation; envelope binding/manual exclusion/double-click/live leases; three isolated vendors; provider draft/file changes; small/resumable/expired uploads/shared block; acceptance/delayed Sent Items/unknown draft and send timeouts/429/permission recovery; clarification replies; durable delta pagination/replays/moves/410/catch-up/throttling; exact old/ambiguous/foreign-mailbox matches; HTML/private files/partial failure/dedup and real/fixture filters.

Browser acceptance used the owned Chrome session lrs-phase-5-qa and explicit local fixtures:

- Unconfigured Microsoft settings preserved manual/website/RFQ work. Explicit fixture connection left the outgoing queue empty, proving no approval backlog was sent.
- Reselected three existing fictional vendors without duplicate requests and reused their separately approved exact Phase 4 revisions. Inspected From/sender/Reply-To, separate To/CC and manifest, authorized each exact envelope and explicitly enqueued three distinct outboxes. The third included backup-qa@example.test in CC and the approved phase2-packing-list.pdf (checksum 4660fa70f8c257906affba57bbca2d13fe9ed8526d929afd6a9b8aadd5150f34); the other two intentionally had no attachments.
- Actual local mail workers moved all three through **Provider accepted** to **Sent Item observed**, with delivery still unconfirmed. Seven incoming fixture messages and three Sent Items were retained. Three vendor replies matched their exact approved revisions; out-of-office/bounce stayed automated evidence.
- A new customer email entered Needs review as LRS-2026-000006. The four-step website submission created separate LRS-2026-000007 with the same test email; staff saw related-case hints without an identity/case merge. Website receipt explicitly showed email disabled; no external acknowledgment occurred.
- Original unsafe HTML rendered as escaped evidence/readable text with no tracking images or injected script. Case lookup and audited staff correction associated the unfamiliar sender's question with inquiry 1 / Harbor RFQ revision 3, retaining the original and a visible assessment reason.
- Visually inspected desktop and 390px/320px mobile screenshots. Measured no document-width overflow in inbox/message/preview/timeline/dispatch/settings at the tested widths. Verified keyboard Tab focuses Skip to content and Enter focuses main-content; long addresses/digests/files remain readable. The final overview and default inquiry queues exclude preserved sample cases.
- Disconnected fixture transport through Admin and set MAILBOX_DEMO_ENABLED=false, clearing config. All seven local inquiries remain labelled fixtures; no genuine data is present yet. New genuine website submissions can populate the real view now; real email awaits company Microsoft setup. Historical fixture dispatches/sources remain available.

Local browser evidence is retained under ignored .tools/phase5-shots/, including desktop inbox screenshot-1791128287210.png, 390px inbox screenshot-1791128700790.png, 320px saved review screenshot-1791129622133.png and 320px keyboard-focused exact preview screenshot-1791129870633.png. These are synthetic acceptance evidence, not real Microsoft screenshots.

The final real-mail workspace showed zero messages/zero matching attention and the disconnected notice; default inquiries and overview showed zero real review/waiting/overdue counts. Browser error inspection returned no errors. No live Microsoft/Exchange check, genuine imported email, public hosting, actual delivery/read confirmation, antivirus scan, production deployment or company pilot signoff is claimed. Normal-browser attachment saving and actual live tenant behavior remain acceptance items.

## Phase 5 manual acceptance checklist

1. Active Admin can manage connection; Agent can use shared case/mail screens but cannot configure or disconnect. Inactive staff and guests cannot read private email/documents.
2. Without Microsoft config, new/manual/website inquiries, evidence review, sourcing drafts and approvals still work. Real queues exclude labelled fixtures; filters/counts/pagination are consistent.
3. Pin verified company identity/rights/size/boundary, authorize the correct Microsoft account, and reject wrong/expired/replayed/session-mismatched state/account. Verify only encrypted server token storage and no secret values in pages/logs.
4. Connecting alone leaves the outgoing queue empty. Select three fictional/controlled vendors, review and approve separate exact content/recipients/manifests, authorize actual From/sender/Reply-To, and explicitly enqueue each.
5. Inspect three distinct provider drafts/outboxes, one vendor's To/CC per message and exact approved file bytes. Repeated clicks/jobs yield one logical dispatch. Ordinary file download/save works in your normal browser.
6. Verify queued/preparing/ready/submitting/202-accepted/Sent Item states; delivery/reading remain unconfirmed. A held/closed/edited case, inactive contact, changed file, stale approval/envelope and manual historical send block release.
7. Inject a provider timeout after submission; confirm uncertain state and reconciliation only, without a new draft/resend. Verify delayed Sent Item, 429/backoff, known permission rejection/reconnect, cancelled pre-send and honest late cancellation.
8. Submit a genuine website inquiry and send a genuine customer inquiry to the configured Outlook mailbox. Both enter Needs review separately with source labels, private evidence and unresolved identity; no duplicate Outlook acknowledgment or client-directory mutation.
9. Reuse an approved clarification, authorize/enqueue its exact recipient/body/envelope, then import a known customer reply. The case returns to review; confirmed shipment facts/version do not silently change.
10. Import a known vendor reply, old-revision reply, ambiguous/unknown-sender message, out-of-office, bounce and list noise. Inspect exact historical request links/reasons; correct classification/match with reason and audit; no commercial price approval is implied.
11. Replay delta pages, interrupt a page, move/remove a message, revoke access and expire a cursor. Evidence remains; bounded catch-up/dedup/recovery work. Inspect unsupported/oversized/failed files, retry without duplicates, and check private unauthorized denial.
12. Inspect desktop/390px/320px layouts, keyboard focus/error states and long addresses/digests. Run tests/Pint/build, complete controlled live Exchange/Sent Items/limits checks and company acceptance before production use.

## Next phase

Stop here. **Phase 6** is reviewed vendor offers, comparable-cost calculations and agent selection, using the exact source/request/version interfaces above. Recommended development settings remain GPT-6.1 Sol / Extra High. Application AI model/rates/provider configuration is a separate decision.

---


# LRS handoff · Phase 4

Phases 1–4 implemented locally on 4 October 2026. Authorization stops after Phase 4. The complete request is preserved in [PHASE_4_BRIEF.md](PHASE_4_BRIEF.md). Earlier handoffs are retained below as historical records; their scope/next-phase statements describe earlier releases.

## Delivered functions and connected flow

[README](../README.md#functions-developed-and-complete-workflow) maps all developed functions. Staff access/settings/vendor directory (Phase 1), clients/manual inquiries/clarifications/private evidence/confirmed versions (Phase 2), website intake/contact assessment/controlled receipt (3A), local extraction/optional structured proposals/human application/cost controls (3B), and separate vendor RFQ preparation/approval/output/manual declaration (4) work together.

1. A client submits the website form, or staff creates a manual inquiry. Preserve the original source, assess/choose client and contact, assign an active owner and choose the client's response deadline.
2. Enter LCL package groups or FCL container groups, route, dates, service scope and required addresses. Inspect private evidence. Optional extraction/AI proposals assist; staff explicitly review/apply them.
3. Clarify missing facts using the existing approved message/manual communication path. Customer answers remain separate evidence.
4. Human-confirm the current general-cargo shipment as Ready for sourcing. Specialist/held/incomplete cases retain existing review restrictions.
5. Select directory vendors manually. A round binds one immutable confirmed shipment; each selected vendor gets its own stable RFQ and initial draft.
6. Prepare that vendor's To/CC, wording, requested reply time/currency, selected files/disclosures and exceptions. Review the saved exact message and approve its revision.
7. Copy exact eligible approved content and download only its manifest. This does not send or record communication. If actually sent outside LRS, staff separately declare the exact approved version/recipients/files/time/channel.
8. New RFQ content requires a new draft/approval. Material shipment changes follow inquiry revision and reconfirmation, then new-round preparation. Keep earlier approvals and declared sends in history.

Live Outlook sending and inbound replies are Phase 5. Offers/comparison, markup/client selling quotations/PDFs, reminders, Gmail, acceptance/booking and production rollout remain later phases.

## Phase 4 routes and permissions

All routes below are authenticated active-staff routes with private no-store/nosniff/no-referrer/noindex handling; private sourcing pages exclude Boost injection. Admin and Agent share sourcing preparation/approval. Owner represents responsibility rather than exclusive access. Only Admin changes company/AI controls. Clients/vendors have no accounts and no public directory search endpoint.

Let **P** mean `/inquiries/{inquiry}/rfqs/{rfq}`.

| Method/path | Action |
|---|---|
| GET /inquiries/{inquiry}/sourcing | Readiness gaps, shipment rounds, real request states and active vendor search/type/service filters |
| POST /inquiries/{inquiry}/sourcing | Select vendors; idempotently create isolated drafts for the current shipment |
| GET / PATCH P | Saved editor / create next immutable content revision |
| GET P/review?revision=n | Exact current/historical review, differences, approvals and audit |
| POST P/approve | Deliberate revision/digest approval |
| POST P/state | Needs approval, Changes requested or cancellation with reason |
| GET P/output | Eligible approved copy and manual declaration |
| GET P/attachments/{document} | Authenticated exact-manifest file download after preflight |
| POST P/manual-send | Idempotent exact-version outside-LRS send declaration |
| GET P/wording/scope | Whitelisted optional AI input/cost preview |
| POST P/wording | Explicit paid wording authorization or unchanged-result reuse |
| POST P/wording/{run}/apply | Human-reviewed wording into a new draft |

Nested case/request/run/document ownership is checked server-side. Existing contact editor now supports active/inactive contacts; an inactive primary loses primary status. Draft saving never changes the shared vendor directory implicitly. Company settings add real RFQ reply name/email/signature; missing values block approval with an Admin recovery link.

## Data, references, locking and revision rules

Additive migration `2026_10_04_110741_create_sourcing_foundation.php` applied to local `lrs` as **batch 5**. Existing records preserved; no new dependency or frontend/backend framework.

- `sourcing_rounds`: one immutable confirmed `shipment_versions` record, one round per version; composite same-inquiry ownership foreign key.
- `rfqs`: stable `RFQ-{inquiry.reference}-S{shipment.number}-V{vendor.id}`; unique round/vendor selection; current revision number.
- `rfq_revisions`: preserved payload/author/change reason; unique RFQ/number. Review state is Draft, Needs approval, Changes requested, Approved, Superseded or Cancelled.
- `rfq_approvals`: one immutable exact rendered snapshot per revision, SHA-256 deterministic content/manifest digest, actual approver/name/time.
- `rfq_dispatches`: one immutable declaration per approval, unique action UUID, actual actor/time/channel/recipient evidence. No provider transport ID or inbound tracking.
- Existing `inquiry_documents` gain explicit version and optional same-inquiry `prepared_from_id`; contacts gain active status; `ai_runs` gain purpose and optional RFQ-revision binding. Existing extraction runs default to shipment_proposals.

PostgreSQL triggers protect saved round/revision content, approvals/dispatch evidence and AI purpose binding. Review status changes are explicit audited operations; they do not overwrite content. Composite foreign keys/unique constraints reinforce case lineage/idempotency. Approval snapshot and audit commit in one transaction; audit failure rolls back approval.

`PrepareRfqs`/`ManageRfq` use consistent company → inquiry → request → sorted vendor/contact/document locking and optimistic case/revision checks. Concurrent selection/approval coalesces; stale edits/preview digests reject authorization of newer content. AI settings/budget reservations use the existing consistent locked path. Real competing PHP processes test these boundaries.

Editing saved recipients/subject/opening/closing/vendor notes/alternatives/files/deadline/company/disclosure/exceptions creates a new draft and supersedes prior review status while retaining prior approval/manual evidence. An unchanged save reuses its revision. Changes requested requires a revised draft before approval. Cancel future use cannot undo an already recorded external message.

## Content, recipients and disclosure policy

`RfqContent` deterministically renders confirmed route/cargo, LCL group weights/dimensions/volume or FCL container groups/weights with units, cargo-ready/arrival targets, service scope/additional services/Incoterm and explicitly disclosed addresses. It requests charge breakdown/unit basis/minimums/inclusions/exclusions/taxes/surcharges/validity/transit/availability/constraints; it never invents their values. Alternatives are labeled separately from the shared baseline. Wording remains editable; material shipment facts remain in the inquiry confirmation path. Blade safely escapes the plain-text/HTML presentation.

One active same-vendor primary To plus optional explicit active same-vendor CC. Normalize email, validate syntax/header safety, reject duplicate To/CC and cross-vendor injection. Shared emails among selected records require deliberate resolution; no silent merge. Recorded mode limitations require a reason. Coverage and free-text minimum notes remain documented facts or unknown; they are not inferred service commitments or machine-interpreted rules.

The requested response deadline is staff-selected, future and compared against the client's response time. A conflict requires a reason bound to the actual client deadline; moving that deadline can require renewed acknowledgement. It is a requested time, never a vendor SLA.

No attachment is selected by default. An intentional empty manifest is valid after acknowledgement. Manifest identity/version/checksum/name/MIME/size/classification/actual scan status is frozen. Selection must belong to this inquiry, be unarchived/available and match actual bytes. Reference freight quotation files are ineligible. Adding unrelated/new files never changes an approval.

Original files remain private and unscanned. A prepared/redacted upload is a separate file linked to its original with staff's preparation note; no automatic redaction, malware scan or anonymization claim. Selecting a prepared copy never silently adds its original. Explicit identity/address/file disclosure includes a reason; essential addresses for requested pickup/delivery require approval.

Preparation defaults: `RFQ_PREPARATION_LIMIT_KB=25600` (25 MiB hard bound), `RFQ_SIZE_WARNING_KB=15360` (15 MiB warning). Existing per-file upload/type limits also apply. These limits do not verify a future mailbox provider's actual transport limit or encoding overhead.

## Central preflight, frozen evidence and manual sending

`RfqEligibility` is the central guard for approve, approved output, manifest download and manual declaration. It requires current ready shipment/version/hash, active responsibility/vendor/recipients, current RFQ revision/state, safe complete wording, future chosen deadline/valid conflict acknowledgements, reviewed disclosure/manifest and configured frozen reply/signature. It checks contact identity/email, file metadata/version/existence/checksum and total size. Holdings/closure/stale rounds/changed recipients/files block release with recoverable reasons.

Snapshot approval freezes actual subject/body/signature, intended reply contact, selected recipient addresses, exact file versions and vendor identity. Directory/template/settings changes never silently regenerate approved content. Contact/file changes block current release; a deliberately saved revision refreshes the relevant data and requires approval. Repeat approval uses the existing frozen evidence and is idempotent. Nonmaterial internal notes do not invalidate it.

Copy/downloading leaves **Approved — not sent**. Staff must deliberately record actual outside-LRS sending, exact approved To/CC/files/revision/digest, time after approval and chosen channel; optional evidence can describe the external reference. **Manually recorded as sent** is a declaration, never provider-confirmed acceptance/delivery/reading. Same UUID repeats reuse evidence; another UUID cannot add an unnoticed second dispatch. Deliberate resend requires a new revision/approval. Historical evidence stays readable after current release is blocked.

## Optional AI wording and costs

Separate purpose `rfq_wording` and prompt/schema version `rfq-wording-1` reuse the existing Responses adapter, persistent `ai` queue, strict output validation and shared run/case/daily monetary caps/paid-attempt bounds. No second provider. Existing shipment extraction schema/review remains separate and unchanged.

Only necessary confirmed facts (excluding identity/addresses) plus permitted subject/opening/closing/vendor notes enter the input. No full files, inbox history, client budget/goods values/internal notes or other selected vendor identities. AI has no tools/send authority. Staff source notes are untrusted. Output can propose only subject/opening/closing/quotation_request; unsafe HTML/header/control content, rates/commitments and numerical body facts are rejected. Human judgment remains necessary.

Staff preview exact input/model/rates/bounds and authorize paid work. Results require explicit review/apply and create a new draft; deterministic facts/checklist/recipients/files remain unchanged. Current-version/confirmed-source guards block delayed newer/approved overwrites. Same scope coalesces queued/successful work; unchanged successful wording can be rebound to another revision after recipient/file/deadline-only edits with source run lineage, zero new provider attempts/reservation/incremental cost. Cosmetic retry also reuses success.

Shared captured rates, paid/failed reported usage, queued/in-flight reservations and uncertain outcome holds retain Phase 3B accounting. A failed/unavailable retry needs an explicit reason; uncertain cost needs Admin evidence reconciliation. Disabled/unconfigured/failing/budget-limited AI leaves manual template preparation usable.

Live provider key/model/account-support/rates remain unset locally; no live provider call or live AI acceptance is claimed. Fake-provider tests verify strict scope/schema, failure/stale behavior and shared costs.

## Phase 4 local run instructions

The prepared PostgreSQL cluster must be running on loopback port **55432**. Keep existing private .env credentials and data; never use migrate:fresh on populated `lrs`. If stopped, README's prepared Windows section contains the verified pg_ctl path/startup. Use separate PowerShell terminals:

~~~powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan config:clear
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
~~~

Optional extraction/live-AI worker (manual RFQs need no worker):

~~~powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan queue:work database --queue=extraction,ai,default --tries=2 --timeout=330
~~~

Open [login](http://127.0.0.1:8000/login), then Inquiries → an eligible inquiry → Vendor sourcing. Configure Admin Company settings RFQ reply details before approval. No default password is seeded; use existing private credentials or the prompted `php artisan lrs:create-admin` setup in README. Do not publish .env/private files/credentials/mail logs. For another machine use README installation/prerequisites and locked Composer/npm dependencies.

Guarded PostgreSQL regression:

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan test --compact
php vendor/bin/pint --dirty --format agent
php .tools/composer.phar validate --strict
php artisan view:cache
npm.cmd run build
~~~

Tests require the explicitly separate `lrs_test` configuration; TestCase and process fixtures refuse a non-test PostgreSQL database. Do not change this to use application data.

## Phase 4 verification evidence

Final full PostgreSQL suite: **132/132 tests, 1,457 assertions** (205.966 seconds). Earlier Phase 2/3A/3B checks were rerun before extension. New coverage is 18 RFQ feature stories, 9 purpose-specific AI tests and 1 real multi-process RFQ story; existing budget concurrency and receipt tests remain passing.

Coverage includes policies/incomplete/specialist gates, three-vendor isolation and duplicate creation, nested/header-safe recipients, shared-email/capability/deadline recovery, frozen approval/idempotency and concurrent stale preview rejection, manifest ownership/integrity/metadata/prepared-copy lineage, audit rollback/database immutability, material quantity reconfirmation/new-round preservation, notes/hold/inactive blocking, truthful manual declarations/resend guard, FCL units/addresses/scope and disabled/failed/delayed/reused AI. Tests assert no RFQ mail/provider-send work and preserve Phase 3A receipt behavior.

Pint `--dirty --format agent`, Composer strict validation, Blade cache compilation, route inspection and production Vite build passed. CSS 47.25 kB, JS 14.23 kB; Node is build tooling only. Forward migration status confirms batches 1–5. The checkout began entirely untracked/unborn; no staging/reset/commit was performed.

### Actual browser inspection and measured limitations

An isolated Chromium QA session exercised the real application forms, using existing synthetic staff/cases/vendors and keeping the normal populated database intact. Desktop 1440×1000 and 390px/320px phone layouts were inspected.

- Case 1 (LRS-2026-000001), confirmed LCL shipment version 2: manually selected Straits, Meridian and Harbor Bridge; three separate vendor requests with their own To contacts and the same cargo baseline. Repeated selections reuse requests. Only Harbor Bridge explicitly selected the existing packing list; the others approved intentional empty manifests.
- Set requested response times, reviewed disclosure and used clearly labeled synthetic reply/signature settings. All three were approved as exact revision 2, initially Approved — not sent. Copying Harbor Bridge's body left that state unchanged.
- Recorded Harbor Bridge revision 2 with evidence explicitly stating **SYNTHETIC QA SIMULATION: no real message was sent**. The application labeled it Manually recorded as sent and retained the actor/time/evidence. This is an acceptance simulation, not a real delivery.
- Added a same-vendor backup CC and revised its opening through the real editor: revision 3 became Draft, with revision 2 approval/manual evidence preserved. Reviewed/reapproved revision 3; it is Approved — not sent and has no copied manual dispatch.
- Case 5's incomplete/next-Draft sourcing screen showed a recovery reason and disabled vendor creation. Case 2's FCL round/editor/review showed 2 × 40HC, 12,000 kg group total, Door to port and the explicitly selected pickup address. No file was selected. FCL remains a reviewed draft for staff approval.
- Intentional duplicate To/CC in the FCL form produced a server error, preserved vendor notes and focused the error alert. Corrected the form and saved a valid revised draft.
- Disabled AI scope showed exact current input and no paid authorization action. No real provider call. Normal keyboard Tab reached Skip to content. A constructed beforeunload event confirmed the actual dirty-form guard cancels leaving; no full human Stay/Leave dialog or screen-reader audit was performed.
- Forced clipboard rejection selected/focused all 1,476 exact approved-body characters and explained Ctrl+C/Command+C. It did not record a communication.
- Sourcing/editor/output/review/FCL/incomplete layouts measured document width equal to the 390px/320px viewport; screenshots inspected for clipping. No JavaScript/page errors were reported. Private RFQ pages contained no injected Boost logger; earlier nonprivate pages produced informational developer logs.
- Actual approved-file GET returned HTTP 200, attachment disposition, application/pdf, private no-store, 784 bytes and SHA-256 `4660fa70f8c257906affba57bbca2d13fe9ed8526d929afd6a9b8aadd5150f34`, matching the manifest. A separate unauthenticated browser redirected to login.
- **Browser file saving remains unverified:** the QA tool/Chromium canceled both the private PDF and an independent synthetic in-memory text download, including after a fresh session with an explicit writable Windows download folder. This points to the browser/tool environment; no successful saved-file claim is made. Authenticated response/bytes/checksum and policy denial were verified. A tested sandbox-header hypothesis did not fix cancellation and was reverted; the original restrictive policy remains. Check saving from a normal user browser in the manual checklist.

Screenshots: [initial desktop editor](screenshots/phase4-editor-initial-desktop.png), [desktop exact review](screenshots/phase4-review-desktop.png), [desktop initial approved output](screenshots/phase4-approved-output-desktop.png), [390px sourcing](screenshots/phase4-sourcing-mobile.png), [390px clipboard fallback](screenshots/phase4-output-mobile.png), [320px revised review](screenshots/phase4-review-mobile.png), [320px FCL review](screenshots/phase4-fcl-mobile.png). Earlier draft screenshots may show historical wording subsequently revised; immutable history was preserved.

The final 38 affected RFQ/Phase 2 tests passed again (574 assertions) after restoring the original response policy; final Pint/Blade/Composer checks also passed. The repository-named testing-best-practices skill was searched in local skill/plugin catalogs and was unavailable; repository PHPUnit rules and meaningful PostgreSQL tests were followed.

Pilot limits: live AI/model/account rates are unconfigured, no mailbox/provider dispatch exists, original/prepared files are unscanned, no actual print-dialog/PDF-export pass or full accessibility audit, no production deployment, and no company pilot signoff. Material-change/concurrent-agent/private-file/inactive restrictions were tested in PostgreSQL; they were not all reenacted as a two-person browser session.

Local QA company reply fields are explicitly fictional (`rfq-qa@example.test` / Synthetic QA signature). Replace them with company-approved details and explicitly authorize a real sender/reply-to envelope before Phase 5 live transport. Existing receipt remains disabled locally. No real vendor/client mail or live AI call was used. Temporary diagnostic DOM was removed and only owned QA browser sessions were closed.

Pilot acceptance remains a separate company decision.

## Phase 4 manual acceptance checklist

Use synthetic cases/vendors and never send real vendor/customer mail for acceptance.

1. Open incomplete/specialist/held sourcing: recovery reasons appear and creation/approval cannot bypass readiness.
2. Complete and human-confirm an LCL case with client/contact/active owner/deadline; select three existing vendors with their own contacts.
3. Verify separate references/To/CC and identical confirmed cargo baseline. Repeat selection: no duplicate requests or sends.
4. Select only necessary reviewed files (or intentional none); inspect scan/version/checksum/size/disclosure. Upload a separate prepared copy and verify original lineage/no automatic inclusion.
5. Edit one vendor's wording/deadline; preview its exact saved content. Demonstrate disabled/failing AI fallback, or authorized fake-provider wording; facts/recipients/files stay controlled.
6. Review/approve each exact revision. All begin Approved — not sent; no RFQ mail is emitted. Company reply gaps and deadline/capability/shared-email exceptions require recovery.
7. Copy/download an eligible approval and verify no send record. Only after an actual external send (or clearly labeled synthetic simulation) record exact version/time/channel/recipients/evidence.
8. Change recipient/body/file/deadline: fresh draft needs approval, while prior approval/manual evidence stays in history.
9. Change confirmed cargo quantity: next Draft needs human reconfirmation/new sourcing round; earlier manual send remains historical and old release is blocked.
10. Two staff edit/review concurrently: stale expected revision/digest cannot approve newer content; repeated creation/approval is idempotent.
11. Deactivate recipient/vendor, alter/archive a selected file, attempt guest/cross-case/cross-vendor access: release/download/injection is blocked with appropriate recovery or denial.
12. Repeat FCL with container quantities/types/group weights/units/service scope and necessary explicitly disclosed addresses. Check desktop/phone, keyboard errors, unsaved edits, copy fallback and print preview.

## Exact Phase 5 consumption and stopping boundary

Phase 5 must consume existing `RfqApproval.snapshot`/`digest` and its bound RFQ revision/round/shipment, never regenerate content from live templates/directory settings. Add a real transport envelope/provider record through explicit staff authorization: connected sender mailbox/From/reply-to must be bound to the approved intent and audited; envelope changes cannot silently rewrite approval.

Before reserving/dispatching an approved message, acquire consistent locks and run central `RfqEligibility` preflight on the current approved revision. `automaticDispatchAllowed` is a foundation guard, not a sending implementation: it rejects approvals with any manual dispatch. Exclude all previously manually recorded approvals even after requirements change or a mailbox connects. Require a deliberately newly approved revision for another dispatch.

Phase 5 must add transport idempotency, mailbox/provider IDs, actual provider acceptance versus delivery/read distinctions, inbound matching/deduplication/catch-up and uncertain-send recovery. A preflight result now is not a permanent guarantee: recheck at dispatch, actual mailbox attachment limits, sender authority and current files/contacts. Preserve frozen snapshots/history and approved attachment lineage. Do not repurpose the Phase 3A transactional receipt job.

No Outlook/Gmail connection, live RFQ send button/job/API, reply tracking, offers/comparison, markup, client PDF quote, reminder or booking was added. Phase 4 is complete locally; stop here. Next coding recommendation: GPT-6.1 Sol / Extra High when available; application runtime AI settings are independent.

## Historical handoffs (Phases 1–3B)

# LRS handoff · Phase 3B

Phases 1–3B implemented locally, 4 October 2026. Authorization stops after Phase 3B. The complete request is preserved in [PHASE_3B_BRIEF.md](PHASE_3B_BRIEF.md). Historical handoffs below retain the earlier verification and setup; their “extraction/AI absent” statements describe those earlier releases.

## Outcome and boundaries

Staff can extract private source documents locally, select exact evidence, preview paid scope, request one structured set of proposals plus unreviewed summary, compare sources, accept/correct/reject/leave unresolved, preview all changes and apply a recorded review. The existing Phase 2 human review still determines readiness.

Original manual text, immutable website submissions, human classifications, clients and confirmed shipment versions are preserved. Processing/review state is separate from inquiry business status. Extraction/AI cannot confirm readiness, verify identity, mutate the approved directory, choose vendors, set vendor costs/selling prices, send messages or book transport. Goods value, budget and reference freight price remain distinct.

Live AI is disabled and unconfigured here: no API key, explicitly selected model, checked account access or current commercial rates. The complete Responses adapter and deterministic fake-provider tests work. No real client material was sent to OpenAI; no live integration pass is claimed. Clearly labelled synthetic proposals work independently of live configuration.

## Dependencies, installation and local run

Installed compatible stack: Laravel 13.34.0 / PHP 8.5.2, Blade, Tailwind 4, Vite 8.3.2 and PostgreSQL 17.11. Node remains build tooling only. PhpSpreadsheet 5.10.0 and its locked dependencies were added as authorized by this brief. No runtime Python service, microservice, vector store or provider framework was added.

PHP requires existing PDO PostgreSQL/mbstring/XML/DOM plus ZIP and GD. Enable ZIP/GD in the actual CLI/worker configuration. This workspace’s ignored .tools/php.ini enables ZIP; GD was already available.

OS dependencies:

- Genuine Poppler pdftotext, pdfinfo and pdftoppm; keep companion DLLs with Windows binaries. Linux commonly uses poppler-utils, macOS Homebrew poppler.
- Tesseract plus eng.traineddata and osd.traineddata for orientation detection. Linux commonly uses tesseract-ocr, tesseract-ocr-eng and tesseract-ocr-osd; macOS Homebrew tesseract. See [official Tesseract installation](https://tesseract-ocr.github.io/tessdoc/Installation.html) and [language downloads](https://tesseract-ocr.github.io/tessdoc/Downloads.html).
- Configure controlled executable paths and language data using .env.example. Quote paths containing spaces. Initially EXTRACTION_OCR_LANGUAGES=eng; additional languages must be installed and explicitly configured.

Prepared tools: Poppler 26.09.0 at .tools/poppler/poppler-26.09.0/Library/bin; Tesseract 5.5.3.20260724 at .tools/tesseract, with eng/osd data in tessdata. The ignored .env and .env.testing use these exact paths. Install OS dependencies on another machine; these local tools are not separate services or distributed application assets.

Use separate PowerShell terminals:

~~~powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php .tools/composer.phar install
php artisan config:clear
php artisan migrate --no-interaction
npm.cmd install
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
~~~

~~~powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan queue:work database --queue=extraction,ai,default --tries=2 --timeout=330
~~~

The existing loopback PostgreSQL cluster must be running on prepared port 55432. Earlier README/handoff sections include cluster startup and lrs:create-admin secure setup. Never reset the populated application database. Only guarded lrs_test is reset by tests. The additive 2026_10_04_092225_create_document_review_foundation migration was applied as batch 4.

Open /login, then an inquiry’s Extraction & review tab; Admin settings are /settings/ai. Public intake stays /request-quote. Existing QA credentials are in ignored .tools/browser-credentials.json; no default staff password is seeded. Never publish credentials, private storage or development mail logs. No storage symlink exposes derived evidence.

Web and worker must use the same environment. Restart old processes after PHP/env changes. Extraction launches a fresh PHP child; workers need PHPRC and tool paths. Production requires supervised workers and release restarts; no production deployment or worker supervision was claimed.

## Configuration, limits and extraction behavior

.env.example contains secret-free paths/key/limit placeholders. Runtime model, enablement, dated rates and caps are Admin-only database settings in ai_settings, disabled/model null by default. OPENAI_API_KEY belongs only in server secrets/environment and is never rendered.

Extractor version local-text-1. Default limits are configurable:

| Resource | Default |
|---|---|
| PDF pages / image pixels | 20 pages; 12,000,000 pixels; PDF render side at most 2,600 px |
| Text | 200,000 characters; no silent truncation |
| DOCX | 500 body blocks, 500 total direct table rows, 5,000 table cells |
| XLSX / CSV | 10 sheets, 500 rows per sheet, 100 columns, 5,000 total cells; CSV 500 rows |
| Office archive | 100 MiB total expansion, 20 MiB per entry, 100× ratio, 2,000 entries |
| Child memory / elapsed run | 256 MiB / 300 seconds |
| Binary timeout / captured output | 45 seconds / 1 MiB |
| OCR output | TSV at most 8 MiB and 20,000 words |
| AI scope/input | 1–200 passages, 60,000 characters, conservative token bound at most 80,000 |
| AI output / paid attempts | 4,000 output tokens (absolute configurable ceiling 16,000), 3 paid attempts per inquiry |
| Provider HTTP | 10-second connect / 75-second total |

Digital PDFs use Poppler UTF-8 layout text per page. Usable text heuristic: at least 30 letters/five words and under 3% replacement characters. Three-or-more-space column patterns warn about tables/layout; these are hints, not measured accuracy. OCR only pages failing that heuristic or explicitly selected for forced OCR. Skipped/failed/unavailable pages are recorded; successful pages survive partial failure. Digital text survives a render failure where possible.

JPEG/PNG check dimensions before decoding, normalize EXIF direction and attempt Tesseract OSD rotation when its orientation indicator reaches 5. OCR lines/words retain page coordinates and engine indicators; indicators are not calibrated probabilities. Initial English OCR, complex tables, handwriting and diagrams require human visual review.

DOCX ZIP/XML parses bounded body paragraphs/direct table rows with IDs; DTD/entity declarations are rejected and network access disabled. Headers/footers, comments, footnotes, drawings/text boxes, embedded/alternate content and merged/nested-layout meaning are not interpreted.

XLSX uses bounded PhpSpreadsheet reads, preserving sheet/row/cell, exact numeric XML lexemes, raw values, formula text, cached result (possibly absent/stale), type and number format. No formula evaluation, external refresh or embedded execution. Drawing/hidden/merged-layout meaning needs inspection. CSV is bounded encoding/delimiter-aware text with row/column IDs; formula-like cells stay inert. The existing public upload policy currently accepts UTF-8 CSV; the reader also understands supported stored encodings.

Missing binaries/languages are configuration failures; corrupt/encrypted/unreadable/oversized content has clear failure/partial/unavailable/manual paths. Originals remain unscanned. No paid image-model escalation was built; record that as future work.

## Schema, identity, transitions and recovery

Additive tables: document_runs, ai_settings, ai_budget_days, ai_runs, proposal_reviews. Private JSONB retains evidence/snapshots/configuration. Composite case/document lineage and unique case/identity/generation plus partial unique active-run indexes prevent competing duplicate identities. PostgreSQL triggers freeze completed document evidence, captured AI result/proposals and recorded reviews.

App\Support\Processing defines labels/permitted transitions. No run means Not processed / Not requested. Local transitions: Queued → Processing → Extracted / Partial / Needs manual review / Failed / Unavailable. AI: Queued → Processing → Needs review / Failed; pre-dispatch configuration/source failures close Unavailable without a provider call. Staff outcomes and inquiry business status are separate. Explicit retry creates a new generation with a reason; terminal history remains.

Start actions persist intent under database locks and dispatch after commit. Job locks plus atomic database claims protect work. OCR/provider calls execute outside transactions. Extraction child PHP enforces memory/time on Windows and Unix. All tool invocations use Symfony Process argument arrays and controlled paths, never shell strings containing source content.

Reuse is inquiry-scoped: exact checksum/extractor/tools/configuration/selected pages for local runs; exact authorized sources/current material snapshot/model/prompt/schema content+versions/output/rate/settings for AI. No cross-client global cache. Public uploads trigger neither local processing nor paid AI. Successful local pages remain after failure.

Queue retry_after defaults to 450 seconds, above the 330-second extraction timeout. AI has one queue attempt/100-second timeout and no automatic HTTP retries. Raising time limits requires adjusting the entire envelope.

~~~powershell
php artisan lrs:recover-processing --age=10 --dry-run
php artisan lrs:recover-processing --age=10
~~~

Recovery respects inactivity and the captured extraction timeout. It retains local checkpoints and requires explicit reprocessing. Undispatched queued AI releases its reservation; processing AI retains uncertain usage/cost and a hold until Admin reconciliation. Remaining old queue jobs safely no-op against terminal claims. Recovery scheduling/supervision is an operational rollout responsibility.

Temporary directories live under private extraction/inquiries/{case}/runs/{run}/work/{uuid}; cleanup resolves the path and removes only this run’s directory. Retained PNGs/text remain private. Originals/checksums/classifications stay intact.

## Responses adapter, schema and evidence policy

One App\Support\OpenAiResponses adapter uses Laravel HTTP/DI: POST /v1/responses with explicit model, store:false, max_output_tokens, system/source input, text.format strict json_schema and tools:[].

Prompt shipment-evidence-1; schema shipment-proposals-1. Actual prompt/schema hashes guard scope/reuse/dispatch. Strict output contains candidates, summary, conflicts, missing and identity_flags. Only existing supported shipment fields are permitted; nullable decimal strings represent unknowns. Packages/containers stay distinct. Additional fields/identity mutation/sending/vendor-cost/selling-price output is rejected. Application code keeps existing decimal-safe conversions/calculations.

Input is explicitly selected source text/IDs/locators/method/classification/warnings. It excludes PDFs/images, submitted contact identity, unrelated history/cases, internal notes/pricing and secrets. Original manual text and immutable website shipment/notes are available evidence. Selected source/working/result snapshots remain private.

IDs/locators/quotes are checked against selected evidence, normalized numbers/units/literal values against quoted text. Contradictory candidates stay visible. Obvious amount-role/seller-address/arrival-role mismatches, duplicate groups and multiple-shipment markers require manual interpretation. These guards cannot prove factual or semantic accuracy. OCR-quality warnings persist. No model confidence percentage is presented.

Admin must configure the key, explicitly choose a current model, verify its current Responses text.format support, check configured account access (GET /v1/models/{model}), enter current dated/versioned USD rates per million tokens and positive run/inquiry/daily caps, then enable live processing. No model/rate is guessed from the coding model. Queued configuration/source changes stop dispatch and release the hold without a provider call.

Refusal, incomplete output, invalid structure, 429/failure and timeout are real failure states. Missing usage/ambiguous outcome stays unknown/held. Provider request/response IDs, model/status and reported cached/reasoning usage are retained privately; raw errors/input do not enter ordinary logs.

Current official references: [structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [Responses request/output](https://developers.openai.com/api/docs/guides/migrate-to-responses), [model access](https://developers.openai.com/api/reference/resources/models/methods/retrieve), [data handling](https://developers.openai.com/api/docs/guides/your-data). store:false requests no Responses application-state storage; it does not guarantee zero retention. Abuse monitoring/account terms still apply. A real configured model/support/access/live request remains unverified until credentials/configuration are supplied.

## Costs, caps and reconciliation

Scope preview shows exact source/page count, characters, conservative UTF-8 byte-based token bound including prompt/schema/protocol allowance, model/output and worst-case reservation. Characters are not exact tokens. Unknown pricing blocks paid submission.

USD cost uses Brick\Math at eight decimal places, rounded up, with configured dated/versioned rates. Cached input gets its configured rate; reasoning detail is part of reported output and is not charged twice. This is an estimate from reported usage, not a provider invoice. Tests use fictitious rates/model.

Settings → case/run → UTC day locking commits conservative reservations before queueing. Caps count spent and held attempts; simultaneous inquiries cannot bypass them. Identical queued requests coalesce to one run/job. Reported charged failed attempts count. Missing usage/ambiguous timeouts retain positive holds instead of inventing zero.

Uncertain attempts block retry until Admin compares provider evidence, explicitly acknowledges reconciliation and records actual evidence-backed USD amount plus explanation. Zero may be entered only from evidence, never inferred. The ledger/audit records reconciliation. A subsequent retry still needs explicit reason, fresh scope and available caps. Exactly-once provider billing is not promised. Local extraction/review stays usable when AI is unavailable.

## Human review, revisions, routes and privacy

No decisions are preselected. Optional bulk selection includes only supported, empty, unambiguous/unconflicted fields. Conflicting/populated replacements and corrections require acknowledgement/explanation. Missing/ambiguous evidence cannot be accepted as source verified; staff can enter a documented manual correction. At most one accepted candidate per group field prevents silent mixing.

Evidence links carry candidate/index IDs instead of private quote text. Correct page/row/cell/text opens; literal passages highlight where possible, otherwise exact text is shown without image-precision claims. Mobile source/proposal switches and keyboard/native controls remain usable.

Preview uses the actual InquiryRequest validation and displays every before/after change, including cleared alternate rows. Apply rechecks case lock/material/source checksum/classification in the existing mutation transaction. Session-bound 30-minute preview UUID makes repeat application idempotent. Immutable review evidence retains staff/time/decisions/reasons/before-after/source-run refs/resulting revision.

Material confirmed changes create the next Draft and retain the confirmed snapshot; human reconfirmation is required. Nonmaterial notes do not create unnecessary revisions. Stale summaries/proposals remain visible with current comparison, but cannot overwrite. Apply/extract never advances Ready.

| Route | Function |
|---|---|
| GET/POST /inquiries/{inquiry}/extraction | Private workspace / authorized local extraction |
| POST /inquiries/{inquiry}/ai/scope | Selected input/scope preview |
| POST /inquiries/{inquiry}/ai | Explicit paid request/reuse |
| POST /inquiries/{inquiry}/ai/{run}/preview | Staff decision/change preview |
| POST /inquiries/{inquiry}/ai/{run}/apply | Acknowledged idempotent review |
| GET /inquiries/{inquiry}/extraction/{documentRun}/pages/{page} | Private PNG |
| GET/PATCH /settings/ai | Admin configuration/usage |
| POST /settings/ai/check | Admin model-access check |
| POST /settings/ai/runs/{run}/reconcile | Admin uncertain-cost reconciliation |

Existing active-staff policies apply. Admin/Agent share inquiries; owner is responsibility, not exclusive access. Nested case/run/document IDs are checked. Settings/reconciliation remain Admin only. Private/no-store, nosniff, no-referrer/noindex protect processing responses. Public reference/receipt grants none of this access.

## Fixtures and measured outcomes

Synthetic assets live in tests/Fixtures/extraction; generate.py optionally reproduces assets and is not a runtime service. They contain no client data/secrets and deliberately include hostile source instructions.

| Fixture | Real measured result |
|---|---|
| Digital one-page PDF | Poppler, no OCR; 2 pallets, 250.5 kg, 100×80×90 cm |
| Scanned PDF / JPEG / PNG | Tesseract with private page/word evidence and quality warnings; 250.5 retained |
| Mixed two-page PDF | Page 1 Poppler; page 2 OCR only |
| DOCX | Paragraph 2 cargo; table 1 rows 2/3 quantities 2 versus 3, ambiguous date/units |
| XLSX | Cargo sheet/cells; inert =2+3, absent cache, 0.0000 format; exact long-decimal lexeme test |
| CSV | Semicolon; row 4 ambiguous 1,200/$/unknown; row 7 inert formula |
| Corrupt/unsafe/checksum change | Honest failure/rejection, no business change |
| Missing OCR / subset / cell-pixel limits | Unavailable/partial/failure with explicit omissions/checkpoints |

The real persistent extraction worker completed nine synthetic jobs through child PHP; malformed PDF is a failed run, not an extraction success. Business inquiries 1–4, website originals and confirmed snapshots were preserved.

Optional synthetic demo:

~~~powershell
# Set EXTRACTION_DEMO_ENABLED=true only locally/testing.
php artisan lrs:demo-extraction --extract
php artisan queue:work database --queue=extraction --stop-when-empty --tries=2 --timeout=330
php artisan lrs:demo-extraction --proposals
~~~

The final command creates clearly labelled fixture proposals after real PDF/DOCX extraction; it never calls OpenAI. Unchanged runs are reused. Material changes create a fresh labelled demo snapshot.

Persistent browser acceptance case: LRS-2026-000005, Evidence review (Synthetic Demo Phase 3B). Supported fields were accepted, conflicting quantity explicitly compared, ambiguous row rejected, changes previewed/applied and status remained Draft. Normal human review confirmed version 1. Another labelled run then received a documented cargo correction; application created Draft revision 2 and preserved version 1. Source/client data remained intact.

Verification on 4 October 2026: full PostgreSQL-backed suite **104/104 tests passed, 1,032 assertions** (172.1 seconds). After the final website-notes correction, all affected public-intake/review tests passed separately: **33 tests / 382 assertions**. The final stopped-worker cleanup check passed **1 test / 10 assertions**, retaining checkpoints/derived images/other-run files while removing only this run’s temporary copies. These are fixture application checks, not live OpenAI calls.

Real local digital/scanned/mixed PDF, JPEG/PNG, DOCX/XLSX/CSV checks passed. Separate competing PHP processes proved identical requests create one run/job and simultaneous inquiries cannot bypass the daily reservation cap. Tests cover private derivative access, source lineage, refusals/incomplete/429/charged failures/unknown timeouts, schema/evidence/role checks, stale edit after preview, idempotent application and confirmed-shipment reconfirmation.

Pint passed after the last PHP changes; Composer strict validation, Blade compilation, additive migration/route checks and final production Vite build passed. Final assets: CSS 46.39 kB and JavaScript 13.88 kB (before gzip).

Actual Chrome screens were inspected at 1,440px desktop and 390px/320px phone widths. Evidence links/literal highlighting, private rendered PDF, Word row navigation, source/proposal switching, conflict errors with preserved decisions, readable preview/apply, human confirmation, next draft/version preservation and stale state were exercised. Measured page scroll widths equalled viewport widths. Tab traversal/native review controls worked; browser errors were empty. Development browser logging is absent from the private processing page. Guest browsers were redirected to login for original/derived/review routes; public intake remained separate. Comprehensive screen-reader audit and production deployment were not performed.

Saved synthetic captures: [desktop review](screenshots/phase-3b-review-desktop.png), [mobile Word evidence](screenshots/phase-3b-evidence-mobile.png), [change preview](screenshots/phase-3b-change-preview.png).

### Manual acceptance checklist

Use fictional sources; do not test the provider with real client documents.

1. Extract digital-packing-list.pdf from an incomplete case. Check page 1 poppler-layout/no OCR and original checksum/classification.
2. Process scanned/mixed/image files. Check mixed OCR only on page 2, correct private evidence and explicit omitted-page/quality warnings.
3. Select exact passages and preview paid scope. With authorized current key/model/rates/caps request one result and inspect usage/summary. Without credentials verify blocked live submission and use clearly labelled fixtures.
4. Compare conflicting quantity/unknown units/date; correct/reject/leave unresolved with explanation. Bulk selection excludes conflicts/populated/missing/ambiguous data.
5. Preview/apply; check client/contact/originals/unselected values unchanged and status not automatically Ready. Repeat the same action without duplicate mutation.
6. Submit existing human review and explicitly confirm Ready; inspect immutable version and deterministic current shipment summary.
7. Start another run/preview, materially edit the case elsewhere, apply old preview. Verify stale/lock error and no overwrite.
8. Correct a confirmed material field via review. Verify next Draft, preserved prior confirmed version and mandatory reconfirmation; notes-only edit does not create a revision.
9. Reuse unchanged runs/results; no unnecessary paid call. Test caps, missing key, unreadable/partial source and refusal/incomplete/429/timeout. Unknown costs stay held until Admin evidence reconciliation.
10. Sign out/use inactive staff/another case ID. Verify original/derived/AI/review privacy; public form/receipt exposes no AI. Check keyboard and 320/390px source/proposal navigation.

## Limitations and stop

No live OpenAI call, selected-model support/access check, actual provider invoice or production supervision/deployment was verified. Fake provider and labelled demo checks are distinct from real local OCR. OS tools/languages need installing per target. Complex layouts/handwriting/diagrams, unsupported Office content and ambiguous interpretations require manual review; deterministic evidence guards do not prove accuracy. Uploads remain unscanned.

Company branding/privacy/service restrictions and human pilot signoff remain existing launch decisions. Receipt email is disabled locally. No image-model escalation, vendor RFQ/outreach, pricing/offer comparison, client quote/PDF, reminder, mailbox connector or booking was built.

Stop after Phase 3B. Next is Phase 4: agent-selected vendors, professional RFQ drafts, recipient/attachment selection and approval of an exact outreach version. Recommended coding setting **GPT-6.1 Sol / High**. Live mailbox sending remains Phase 5; runtime model selection is independent.

---

## Historical handoffs (Phases 1–3A)

# Phase 3A handoff · current implementation

Updated 4 October 2026. **Phase 3A is complete; stop here.** The complete approved source is [PHASE_3A_BRIEF.md](PHASE_3A_BRIEF.md). Phases 1/2 and their records were preserved. Phase 3B has not started. Earlier Phase 2 evidence follows as a historical section; this current section supersedes its public-intake/email/next-phase statements.

## Delivered outcome
Anonymous customers can submit general-cargo sea-freight inquiries at /request-quote without accounts. Contact → Shipment → Documents → Review extends the approved Apple-inspired light system. It explains LCL/FCL/unknowns, requested service boundaries, optional files and privacy; it promises assessment, not instant prices or bookings. The root/login routing is unchanged.

The page uses real configured company identity/introduction/contacts; local branding is honestly labeled pending approval. Agents have a copy-link action in the inquiry list with text-selection fallback. Localhost is labeled Local preview only; sharing externally requires a deployed configured HTTPS URL.

A success creates one normal Website form Inquiry in Needs review, an immutable original submission and private source documents. Unresolved client/contact, owner and deadline are visible gaps. Staff use the existing inquiry workspace to assess identity, select/create a client/contact, assign an active owner/deadline, clarify, edit the working draft and explicitly confirm a complete general-cargo shipment. Website intake cannot set trusted workflow fields or bypass Phase 2 gates.

Unknown technical values are nullable/explicit unknown, never zero. LCL supports up to 20 package groups with total group gross weight, per-package dimensions/units or declared total volume/source. FCL supports up to 20 container type/quantity/weight rows. Alternative rows survive mode changes. Requested services, special flags/notes and optional goods value/budget/reference freight price remain distinct. Conditional addresses can stay incomplete at intake and become staff gaps. Specialist cargo remains in human review.

Inputs survive step navigation and server errors. Inline/summary errors focus the relevant field/step; attachment failures explain reselection. Review reflects current values and exposes Edit links. Unsaved edits use the browser leave warning without localStorage persistence. Without application scripts all sections are visible, row-add uses a server POST and submission still works. No inquiry or persistent document is saved before final submit.

## Additive routes
Existing staff/root/login routes remain. New routes:

| Method / route | Access and behavior |
|---|---|
| GET /request-quote | Anonymous branded page, issued session-bound form key; helpful paused/contact page when disabled |
| POST /request-quote | Anonymous CSRF-protected validation and transactional intake |
| POST /request-quote/draft | Server-only row-add round trip; preserves safe input, reselect files |
| GET /request-quote/received | Minimal receipt for the original submitting session only |
| POST /request-quote/received/resend | Original receipt session, current original email, CSRF and resend limits |
| GET /request-quote/confirm | Minimal code/action page; never consumes a token or exposes a case |
| POST /request-quote/confirm | CSRF-protected scoped atomic mailbox confirmation |
| GET /inquiries/{inquiry}/public-contact | Active authorized staff; working contact corrections and explicit association |
| PATCH same | Validate identity assessment/selection and optimistic lock; original remains immutable |
| POST .../public-contact/resend | Active authorized staff, optimistic lock and resend limits |

Only Admin edits /settings. Admin and Agent share all inquiry/client/document operations under existing policies; owner is responsibility, not exclusive access. Website filters/badges, unassigned queues, contact-check state and private possible-match/repeat hints are integrated into the existing workspace. There is no second inbox or customer dashboard. “Website form” is server-derived at public creation, not a manual intake/communication choice.

Public pages send private/no-store, no-referrer, noindex/nofollow and nosniff headers. Confirmation tokens use a URL fragment; JavaScript copies it into the POST code field and removes it from the address bar. The code printed in the receipt message provides a script-free alternative. A reference number, confirmation link or receipt grants no private case/file access.

## Schema and integrity
Migration **2026_10_04_073826_add_public_intake_foundation.php**, applied locally as **batch 3**:
- Allows nullable inquiry client/owner and document uploader for anonymous intake. Existing manual intake and all Ready transitions still enforce appropriate active associations.
- Adds inquiries.public_contact JSONB for mutable, normalized working contact values.
- Adds document provenance and truthful scan_status (unscanned); anonymous uploads use System / public submission, never a staff actor.
- Adds public_intake_keys: hashed issued key, HMAC session binding, expiry and unique optional inquiry link.
- Adds public_submissions: unique inquiry/key hash, session binding, original snapshot JSONB and received time. PostgreSQL rejects UPDATE/DELETE through an immutable trigger.
- Adds mailbox_verifications: inquiry/address, nullable unique token hash, expiry/confirmation/invalidation times, approved template, claim/transport times and constrained transport state; a partial unique index permits one non-invalidated row per inquiry.
- Adds the small company settings fields listed below. No later-phase tables or dependencies are introduced.

Original evidence preserves submitted contact/company/phone/email text (trimmed middleware input, original email casing), structured shipment, notes, original file IDs/names/checksums/classification, exact privacy notice/version and acknowledgment UTC time. Working email is normalized separately. Staff edits/confirmed revisions never rewrite this source. Classification is a customer suggestion staff can correct on working file metadata; original classification is preserved.

A 64-hex cryptorandom form key is issued by the server, hashed in PostgreSQL and bound to a per-session nonce. Pending forms initially expire after 24 hours. Transaction row locking plus unique key/submission/inquiry constraints serialize competing requests. A completed same-session key returns the same inquiry/reference without revalidating/reuploading/requeueing. It is not email/content deduplication: a new key may create a distinct intended case for the same contact.

Inquiry, source, file metadata, receipt state and audit changes commit together; queue dispatch runs after commit. Private writes are compensated if persistence fails, removing only new paths owned by that failed request. A committed case survives queue/mail failure. References reuse the existing global PostgreSQL sequence; gaps are normal. Tests use two real PHP processes to prove one case/source/file/queued receipt job under competing requests.

Treat this as a forward migration on populated installations: back up database and private originals first. Never use migrate:fresh or routine rollback against business records. Down removes public evidence/configuration and does not restore pre-intake NOT NULL constraints; it is not a business-data rollback plan.

## Contact assessment and workflow
Public intake never creates approved Client/ClientContact records. Even an exact approved email match leaves client/contact null and never reveals directory data publicly or modifies that client. Staff alone see bounded possible existing matches and cross-channel case hints.

The client assessment screen supports:
- Defer association and correct working submitted contact fields.
- Explicitly select an active existing client/contact.
- Create a new contact for an active client after assessment.
- Create a client/contact after explicit identity assessment.

Selection/creation requires the staff assessment acknowledgment and a current lock_version. Existing approved contact data is not overwritten by supplied public values. Email changes invalidate the old mailbox check. Assessment/material selection uses the existing SaveInquiry revision/stale-edit rules and records actor/resolution metadata; sensitive contact/source payloads are not copied into audit entries.

An active Admin-configured default intake owner is used; otherwise Unassigned. Deadline remains null unless staff supplies it; no response SLA is invented. Human Ready confirmation requires active client/selected contact/owner, response deadline, route/cargo/date/mode/scope and complete conditional general-cargo measurements/addresses. Clarification approval/copy remains separate from manual communication. Customer answers remain evidence and do not populate working fields automatically. Mailbox confirmation is independent of these shipment gates.

## Receipt mail and mailbox access
**Local receipt mail is disabled; no real customer message was sent.** Submissions still receive a session-bound reference. The sole new automatic email is the fixed company-approved receipt text, inquiry reference and confirmation action/code, with no cargo, source attachments, internal notes, rates or quotation promises.

Admin approval/enablement and explicit PUBLIC_RECEIPT_MAILER are both required. Log, array and failover/fallback configurations are not treated as external sending. A local preview allows only explicitly opted-in loopback SMTP capture in APP_ENV=local. Public configuration also requires a non-placeholder sender. Actual transport/provider credentials, deliverability and sender-domain approval require deployment review.

| State | Truthful meaning |
|---|---|
| disabled | Admin has not enabled receipt email; not externally sent |
| unavailable | Requested transport/configuration is unsupported/unavailable; not externally sent |
| queued | Database queue dispatch recorded; not yet submitted to transport |
| dispatching | Durable send claim made; outcome uncertain, reconcile before resend |
| transport_submitted | Transport accepted the call; delivery/reading is not confirmed |
| failed | Queue dispatch or transport reported failure; case retained, delivery not confirmed |

Syntax validation does not verify a mailbox. A fresh 32-byte cryptorandom secret is minted in the claimed job, stored as SHA-256 only, and scoped to inquiry/current address. Raw confirmation secrets are not persisted in the database job/audit/application logs. All customer routes explicitly exclude Boost's development logger, which otherwise captures complete browser URLs, including fragments; staff diagnostics remain available. Default expiry is 24 hours from claim; configurable by PUBLIC_VERIFICATION_EXPIRY_HOURS. GET never mutates confirmation. Explicit POST locks parent/check records and returns safe confirmed/expired/already-confirmed/invalid states without a case reference or payload. Repeated confirmation is idempotent. Resend invalidates previous checks; working-email change invalidates the corresponding state.

Resend requires the original receipt session and unchanged normalized original email, or authorized staff with a current lock. No public email lookup exists. After confirmation there is no ordinary resend prompt. Confirmation proves mailbox access only; staff assessment and Ready remain separate.

SendInquiryReceipt is queued after commit with tries=3, timeout=30 and backoff 10/30 seconds for pre-claim failures. A durable claim precedes the external call; duplicate jobs skip claimed/submitted/failed rows, preventing blind repeat sends. Transport exceptions are recorded without logging sensitive error payloads. A process crash during the external call can remain dispatching; reconcile provider/capture evidence before any manual resend. This is not a claim of exactly-once provider delivery. Manual resend is a new intentional check and invalidates the old token.

## Uploads and abuse controls
Public defaults: **5 files, 10 MiB each, 30 MiB combined**. Helpers use the minimum of public defaults, existing INQUIRY_UPLOAD_* / INQUIRY_DOCUMENT_LIMIT and PHP constraints. Combined size reserves 1 MiB below PHP post_max_size. UI/server read these same limits.

Allowlist in config/public-intake.php: PDF, JPG/JPEG, PNG, DOCX, XLSX and UTF-8 CSV. Server validates extensions, actual MIME/contents, Office structure, count, per-file/combined size and bounded names. It rejects SVG/HTML/executables, ordinary ZIP/archives, macro-enabled formats and Office VBA/activeX/embedding structures. Office ZIP internals do not allow ordinary ZIP uploads. This is format validation, **not malware scanning**; files show unscanned. Do not claim antivirus coverage.

Generated private paths, sanitized display names, size/MIME/checksum/provenance/classification/time are retained. Deduplication is within one case; no cross-customer filename/hash disclosure. All reads/downloads require active staff and scoped inquiry bindings. PDF/image previews are sandboxed/nosniff; Office/CSV use download fallback. Responses are private/no-store; never create a public storage link for inquiry originals.

CSRF applies to every public POST, with a honeypot, bounded strings and maximum 20 rows per kind. The limiter uses database cache rather than process memory:
- Public page: 60/minute per IP.
- Submission/server-row-add (including attempted uploads): 4/minute and 8/hour per IP.
- Confirmation POST: 5/minute and 20/hour per IP.
- Resend: 1/minute and 3/hour per staff ID or anonymous IP.
Initial receipt sends are bounded by successful-intake limits and same-key deduplication. Resend/confirmation have their own limits. Default proxy behavior trusts no arbitrary forwarded client-IP headers; deployed proxies must be explicitly configured. Shared-IP customers may hit limits; helpful 429/contact/recovery states remain.

413 upload-limit, 419 expired session/CSRF and 429 throttle responses render the customer layout with useful recovery/contact guidance. Upstream reverse proxies need a corresponding accessible 413 response if they reject before Laravel. Confirmation/form secrets are excluded from exception flash/audit payloads. No mandatory paid CAPTCHA or analytics scripts are introduced.

## Configuration and local run
Company Admin at /settings controls public_intake_enabled, public_service_intro, public_contact_email/phone/address, public_privacy_notice/version, public_intake_owner_id and receipt_mail_enabled/body. Approve legal/display branding, actual service restrictions, public contact and privacy text/version before launch; these defaults are not a legal policy signoff. There is no marketing consent.

```dotenv
PUBLIC_INTAKE_URL=http://localhost:8000/request-quote
PUBLIC_VERIFICATION_EXPIRY_HOURS=24
PUBLIC_UPLOAD_TOTAL_KB=30720
PUBLIC_RECEIPT_MAILER=null
PUBLIC_RECEIPT_LOCAL_CAPTURE=false
```
PUBLIC_INTAKE_URL must be a full application inquiry URL ending /request-quote. For deployment align APP_URL with its HTTPS origin. The staff copy action and confirmation action derive from this configured URL. Edit the config allowlist only within supported validators. Existing INQUIRY_UPLOAD_MAX_KB=10240, INQUIRY_UPLOAD_BATCH_LIMIT=5 and INQUIRY_DOCUMENT_LIMIT=20 retain stricter bounds.

Prepared Windows workspace:
```powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
# If the prepared portable PostgreSQL service is stopped:
& .tools/pgsql/bin/pg_ctl.exe -D .tools/pgdata -l .tools/postgres.log -w start
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan migrate
npm.cmd run build
.\scripts\local.ps1 serve
```
The database is currently running at loopback port 55432; business database lrs and isolated lrs_test are separate. The server is available at http://127.0.0.1:8000; use /request-quote for anonymous customers, /login for staff. Existing QA credentials are in ignored .tools/browser-credentials.json; do not publish them. An independent installation follows README prerequisites/secure admin setup with its own .env/key/database. Keep PHP pdo_pgsql, fileinfo and Phar enabled.

Align PHP/web-server upload limits: 10M per file, post_max_size at least 32M (64M gives more multipart headroom), max_file_uploads ≥ 5; proxy limit must accommodate the form and files. Stricter PHP settings reduce displayed application limits. Storage remains outside public and unlinked.

For optional **controlled local capture**, use an existing loopback SMTP capture tool (e.g. 127.0.0.1:1025), set MAIL_HOST/PORT, select PUBLIC_RECEIPT_MAILER=smtp and PUBLIC_RECEIPT_LOCAL_CAPTURE=true, clear configuration, then explicitly enable the approved template as Admin. Do not substitute a real recipient/provider just to test. No capture server was installed/connected during this phase; server tests use Mail/Queue fakes.

Run a worker when receipt mail is deliberately enabled:
```powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan config:clear
php artisan queue:work database --tries=3 --timeout=30 --backoff=10
```
Worker config must match the web application. Production needs supervision, restart after deploy and provider-outcome reconciliation. Do not enable real messages until company-controlled configuration is approved. There is no Outlook/Gmail connector or automatic clarification/quotation send.

## Verification evidence
- Initial Phase 1/2 PostgreSQL baseline: 48 tests / 469 assertions passed before extension.
- Final combined PostgreSQL suite: **71 tests / 768 assertions passed**. Tests use lrs_test, with a PostgreSQL/name guard; they never reset lrs business data.
- New meaningful tests: PublicIntakeTest, MailboxVerificationTest and PublicIntakeConcurrencyTest (23 added tests). Cover validation/unknowns/conditional addresses and monetary contexts, source immutability, unresolved/deferred/explicit associations, existing-email spoofing protection, development-logger exclusion with the logger actively injected on staff login, private files/types/limits, actual CSRF/persistent throttles, stale edits/review gates, rollback/file compensation, same-key sequential/competing requests and one queued receipt job.
- Mail/Queue fakes prove after-commit dispatch, minimal approved content, disabled/log/unavailable modes, transport failure and uncertain-send deduplication; hashed scoped valid/expired/reused/resend/changed-address checks; GET leaves tokens unused and POST is atomic/idempotent. No real SMTP/provider delivery is claimed.
- PHP Pint --dirty --format agent passed; production Vite build passed (Tailwind/CSS 44.15 kB, JS 12.79 kB); Blade compilation and Composer validate --strict passed. Dependency versions were preserved.
- Normal migration status shows all six migrations applied: Phase 1 batch 1, Phase 2 batch 2 and Phase 3A batch 3. Seven public and three staff-contact route additions were inspected.

### Actual Chrome inspection
Local labeled QA records were used; no real customer communication was sent:
- Agent link-copy fallback selected the full URL and reported Local preview only.
- Anonymous LCL submission used an already-existing approved contact email, unknown volume/date/scope and a packing list. Invalid SVG was rejected with preserved inputs, first-error Documents focus and explicit attachment reselection. Switching LCL → FCL → Not sure → LCL retained entered package data.
- Receipt **LRS-2026-000003** showed one reference and Email disabled / not externally sent. The source-filtered unassigned staff queue showed the Website case with no client/contact association. Private existing-match hints appeared only to staff. Explicit existing client/contact selection, owner/deadline assignment, clarification draft/approval and a clearly labeled simulated response were exercised. The working volume/date/scope were corrected separately and human Ready confirmation created shipment version 1; original volume/date/scope remained unknown. The approved clarification was not falsely recorded as an actual outside-LRS send.
- Application scripts blocked in a separate browser session: all four sections remained usable; FCL row-add via server POST preserved contact/route/container/special data, followed by successful normal submission **LRS-2026-000004**. This is a scripts-blocked check, not a full browser-JavaScript-engine-off claim.
- Phone contact/Shipment/receipt layouts were inspected at 390px; the Shipment form was also measured at 320px. Required-field validation focused contact[name], marked aria-invalid and showed a readable error. Step navigation focused the section heading.
- Accessibility scan on the public Contact screen: zero reported WCAG 2 A/AA violations, with eight gradient-background contrast nodes requiring human review. Automated scans do not prove comprehensive accessibility conformance.

- Staff mobile FCL case 000004 retained 40RF/container details and temperature disclosure, remained Needs review with nine gaps, and had no Confirm Ready action; width 390px equaled document width.
- Authorized staff opened the public packing list in the private PDF preview. The anonymous receipt-session browser was redirected to /login when opening its /file URL.
- Admin paused intake: customer page had no form and clear company-contact/preview guidance; manual staff intake still loaded. The original intake-enabled setting was restored, with receipt mail still disabled.
- The minimal confirmation page excluded the development logger and all private case links; an obviously fake code produced a safe “confirmation unavailable” state. Valid/expired/reused flows were proved by controlled Mail fake tests.
- Keyboard Tab reached the review Edit contact action with visible focus. Desktop review showed the live unknown-mode/date/scope summary. Customer and staff Chrome error collections were inspected; no uncaught application error was reported.
- Staff evidence accessibility scan also reported zero WCAG A/AA violations, with 11 gradient-related contrast nodes marked for manual review.

Saved visual evidence (actual local QA screens):
- [Customer Contact · 390px](screenshots/phase3a-contact-mobile.png)
- [Customer Review · desktop](screenshots/phase3a-review-desktop.png)
- [Session receipt · 390px](screenshots/phase3a-receipt-mobile.png)
- [Staff Ready and preserved original](screenshots/phase3a-staff-confirmed-original.png)

Screenshots were visually inspected. Tests provide concurrency/mail/security proof; screenshots alone do not.

## Phase 3A manual acceptance checklist
Use labeled local/demo records and a controlled capture/fake transport. Do not contact real customers/vendors as a test.

1. [ ] Agent copies the configured link; anonymous browser opens the branded page. Check normal copy/fallback and Local preview versus deployed URL.
2. [ ] Submit incomplete LCL, optional packing list and unknown volume/readiness/scope. Check step back/edit and preservation after an invalid file; reselect attachments.
3. [ ] Retry the exact same form key, including competing requests: same reference and one source/file/receipt. Start a new key for a distinct intended request with the same email.
4. [ ] In staff Website/Unassigned filters, inspect Needs review, original contact/shipment/files/privacy, received time and ownership/deadline/contact gaps. Confirm System/public actor.
5. [ ] Submit an existing approved email separately; no public directory data, automatic association, overwrite or auto-merge. Staff-only hints must remain suggestions.
6. [ ] Explicitly assess/select/create client/contact, assign owner/deadline, prepare/approve clarification, record actual outside-LRS communication and the answer, complete working values and confirm Ready. Preserve original snapshot and stale-edit/revision gates. Local QA simulation does not substitute for real communication evidence.
7. [ ] Through a controlled mail transport, inspect the minimal receipt. GET must leave token unused; POST confirms only mailbox access. Expired/used/invalid and resent/changed-email tokens must be safe; no readiness approval.
8. [ ] Test disabled/log/unavailable and failing/uncertain mail: reference/case retained, no false delivery claim and no blind duplicate send.
9. [ ] Guest/reference/token cannot access case/file. Reject invalid, macro/archive, oversized/count/aggregate-limit files; verify private generated paths, scoped staff access and unscanned label.
10. [ ] FCL/Not sure and specialist disclosure retain correct rows/unknowns and human-review gaps. Check 320px/390px, desktop, keyboard, leave-warning Stay/Leave and JavaScript fully disabled.
11. [ ] Disable intake as Admin: helpful contact/paused state, no submit; staff workspace/manual email intake stays operational. Restore desired company setting.

## Limitations and next phase
- No deployment, public DNS/HTTPS or production transport/deliverability proof. Receipt mail is disabled locally; controlled Mail fakes prove behavior.
- Company branding, contact/service/privacy wording/version and receipt approval remain launch inputs. No legal policy approval or response SLA is claimed.
- Uploads remain unscanned originals. Format/MIME/structure checks and sandboxed previews do not establish that files are malware-free. A real scanning service, retention and operational backup/restore policy require rollout decisions.
- Complete assistive-technology/browser/OS coverage, human gradient contrast review, browser-engine-off testing and human Stay/Leave acceptance remain manual checks. Upstream proxy/request limits must be verified on the deployment target.
- Expired form keys/check records are retained; no speculative automatic cleanup/retention scheduler is implemented. Shared-IP throttling may need company pilot tuning. Crashed/uncertain transport outcomes need reconciliation before manual resend.
- This phase adds no extraction/OCR/AI, Outlook/Gmail integration, vendor outreach, RFQs, offer comparison, quotation/PDF, reminders or booking.

Next is **Phase 3B**, only when authorized: text-first document extraction, OCR fallback, structured AI field proposals, source evidence, human review and usage/cost tracking in this same workspace. Recommended coding setting: **GPT-6.1 Sol / Extra High**, when available. Runtime AI provider/model is a separate later decision.

---

# Historical Phase 2 handoff · prior completion record

Updated 4 October 2026. Phase 2 is implemented. The source brief is preserved in [PHASE_2_BRIEF.md](PHASE_2_BRIEF.md). Phase 3 has not started.

## Delivered screens and behavior
- Shared Admin/Agent client list, company/contact search, active/archive and usable-contact filters, pagination, create/edit/detail, audited archive/reactivate, normalized contacts and atomic primary transfer. Client detail shows actual inquiry history. Both roles manage every inquiry; owner denotes responsibility.
- Inquiry queues and real overview counts; search by reference/title/client and filters for status, active/inactive owner, priority, client and overdue open responses. Manual intake preserves source text/channel/received time; updates cannot overwrite them.
- A sectioned editor and two-column inquiry workspace with Overview, Shipment, Documents and Activity. Conditional LCL package groups / FCL container rows, explicit units, addresses, scope, dates, requested services, specialist flags, client-stated Incoterm/place and three separate commercial values.
- Missing requirements beside fields and next-action guidance; nonblocking same-endpoint, service/scope and declared-versus-calculated-volume warnings. Unknown measurements are null. Exact decimal calculations sum row gross-weight totals and calculate each LCL group quantity × dimensions after conversion to metres. No chargeable-weight or capacity assumptions.
- Immutable confirmed snapshots, reviewer/time/contact evidence, revision history, optimistic edit tokens, hold/close reasons and audited resume/reopen. Material changes fork after confirmation and require fresh review; notes/deadline/active owner reassignment preserve the shipment revision.
- Editable deterministic clarification drafts; approval binds exact saved text, recipient and revision. Copy with text-selection fallback. Explicit manual communication confirmation records staff/channel/recipient/time and exact wording; customer answers preserve evidence and return a waiting inquiry to review. LRS sends no inquiry email.
- Private uploads and authenticated PDF/image previews/downloads, classifications, checksum deduplication within one inquiry, audited archive and preserved originals. Document uploads cannot modify shipment fields. No extraction or malware scan result is fabricated.

## Routes and implementation
Existing Phase 1 routes remain. Main new routes:

| Route | Purpose |
|---|---|
| GET/POST /clients; GET /clients/create | Directory and capture |
| GET/PATCH /clients/{client}; GET /clients/{client}/edit | Detail and editing |
| GET/PATCH /clients/{client}/status | Archive/reactivate |
| GET /clients/{client}/contacts/create; POST /clients/{client}/contacts | Contact capture |
| GET /clients/{client}/contacts/{contact}/edit; PATCH same without /edit | Contact management |
| GET/POST /inquiries; GET /inquiries/create | Queue and manual intake |
| GET/PATCH /inquiries/{inquiry}; GET /inquiries/{inquiry}/edit | Workspace and working draft |
| POST /inquiries/{inquiry}/transition | Central status changes |
| POST /inquiries/{inquiry}/communications | Notes/customer answers and document references |
| GET /inquiries/{inquiry}/versions/{version} | Immutable confirmation evidence |
| POST /inquiries/{inquiry}/clarifications | Prepare deterministic draft |
| GET/PATCH /inquiries/{inquiry}/clarifications/{clarification} | Review/edit exact draft |
| POST .../clarifications/{clarification}/approve | Approval only |
| POST .../clarifications/{clarification}/communicated | Explicit outside-LRS communication record |
| POST /inquiries/{inquiry}/documents | Private upload |
| GET/PATCH /inquiries/{inquiry}/documents/{document} | Metadata/preview/archive/classification |
| GET .../documents/{document}/file?download=1 | Authorized original download |

Every route requires active staff authentication; nested bindings reject records belonging to another inquiry/client. No delete route exists for business records. Staff/settings remain Admin only.

Small transaction actions live in app/Actions. Form Requests validate submissions; app/Support/InquiryWorkflow defines transitions and readiness; Shipment normalizes and calculates; InquiryUploads checks actual content. Policies use the established shared workspace permissions.

## Database and dependencies
Additive migration: 2026_10_04_055713_create_phase_two_tables.php. Adds clients, client_contacts, inquiries, shipment_versions, clarifications, inquiry_communications, inquiry_documents, communication_document; adds client/inquiry audit references.

PostgreSQL partial/normalized unique indexes enforce one active primary contact and unique per-client email. A composite FK enforces selected contact ownership. Inquiry references use a global PostgreSQL sequence, rendered as LRS-{company-local-year}-{sequence padded to 6}; gaps are expected, including rolled-back allocations. A trigger rejects UPDATE/DELETE of confirmed versions. Canonical snapshots normalize JSONB key order and decimal strings before hashing. Optimistic lock_version rejects stale saves/actions without overwriting submitted values. Validation retries retain the submitted token until staff reload and compare. Sequence/trigger definitions tolerate dedicated-test database recreation; the down migration removes owned objects.

No Composer/npm dependencies were added in Phase 2. Decimal operations use the existing Laravel dependency Brick Math 1.0.0. Preserve Laravel 13, PHP 8.5, Blade, Tailwind 4, Vite 8 and local Phosphor icons. No runtime AI model is configured.

## Readiness and transitions
Draft → Needs review / On hold / Closed.
Needs review → Draft / Needs client information / Ready for sourcing / On hold / Closed.
Needs client information → Draft / Needs review / On hold / Closed.
Ready for sourcing → Needs review / On hold / Closed.
On hold → Resume (Needs review) / Closed.
Closed → Reopen (Needs review, reason required).

Hold and close require a reason. Resume/reopen always return to review and recalculate gaps; they never silently grant readiness. Confirmation requires active owner/client/selected belonging contact, deadline, cargo, both route endpoints, known mode/scope and cargo-ready date. Door/service-requested addresses are conditional. LCL needs type/quantity/gross weight/unit plus per-package dimensions/unit or declared volume and source; FCL needs type/quantity/cargo gross weight/unit. An optional Incoterm needs its named place. Specialist flags, unresolved notes, refrigerated/Other containers block ordinary general-cargo readiness.

Confirmed versions preserve the exact snapshot/contact email and SHA-256, reviewer identity/name and UTC timestamp. Material client/contact/shipment changes invalidate draft/approved clarifications and current readiness. Each confirmed revision can be referenced by later phases; do not mutate its snapshot. A contact email change invalidates current hash eligibility even before the inquiry is next saved. Metadata-only edits do not fork.

## Private document operations
Disk inquiry_documents: storage/app/private/inquiry-documents, never under public or a storage symlink. Back up originals with the database; do not publish this directory. UUID storage names are hidden from serialization/UI. Defaults: 10 MB/file, 5 files/upload, 20 originals/inquiry including archived files. Configure INQUIRY_UPLOAD_MAX_KB, INQUIRY_UPLOAD_BATCH_LIMIT, INQUIRY_DOCUMENT_LIMIT. PHP/web-server upload/post limits must accommodate the configured batch; fileinfo and Phar are required for MIME/Office archive structure checks.

PDF/JPEG/PNG are checked against actual detected MIME; images also need valid image headers. CSV requires supported text MIME, valid UTF-8 and no NUL throughout. Office files require an actual ZIP/OOXML MIME/container plus content-type and document/workbook entries; a TAR renamed as DOCX is rejected. This is type/structure validation, not a malware scan or deep content certification. PDF/image previews use authorized routes, private no-store, nosniff and sandbox headers; other formats download only. A missing original yields a useful 404. Same checksum in another inquiry creates a separate authorized original and reveals no cross-client duplicate.

## Verification record
Tests run against isolated PostgreSQL lrs_test; the final run passed **48 tests, 469 assertions** (28 Phase 1 tests plus 20 Phase 2 tests). No production database reset was performed.

| Check | Final verified result |
|---|---|
| Phase 1 baseline before implementation | 28 tests / 202 assertions passed |
| Full final php artisan test --compact | 48 tests / 469 assertions passed on PostgreSQL |
| php vendor/bin/pint --dirty --format agent | Passed after correcting the final helper-text syntax error |
| npm run build | Passed; CSS 41.08 kB and JS 4.17 kB before gzip |
| php artisan view:cache | Passed |
| composer validate --strict | Passed; no new dependencies |
| php artisan migrate / migrate:status | Additive Phase 2 migration in batch 2; all five migrations applied |
| php artisan route:list --except-vendor | 61 routes inspected |
| Attachment SHA-256 | Preserved Phase 2 brief matches the attached text exactly |
| Secret/private-file exclusions | Environment files, QA credentials, private mail log and original uploads are ignored |
| Chrome desktop acceptance | Client/primary contact, incomplete LCL, deadline, packing-list upload, visible gaps, editable exact-message approval, Copy, explicitly simulated manual communication, answer with document reference, readiness/version 1, changed destination, draft revision 2, reconfirmation, both snapshots, client history and audit passed |
| Private original in Chrome | Authenticated PDF preview renders; separate unauthenticated browser redirects to login |
| FCL in Chrome | Conditional fields; missing cargo weight/unit and requested pickup address; completion/review/confirmation passed |
| Phone layouts | 390px shipment workspace/inquiry list and 320px client list/FCL editor measured with no page overflow; tables scroll inside bounded containers |
| Keyboard / enhancements | Navigation Escape and focus restoration passed; repeated-row removal/reindexing has no broken labels/duplicate IDs; 20-row cap passed; beforeunload cancellation handler verified |
| Copy fallback | Successful copy and simulated clipboard rejection both exercised; rejection focuses/selects the 197-character exact message and gives keyboard instructions |
| Browser runtime errors | None observed during the acceptance flow; console contained Laravel Boost diagnostic logs |
| Automated WCAG A/AA check on inquiry screen | 0 violations; one contrast check reported incomplete and requires human review |

Local fixture: Phase 2 Browser QA Manufacturing / Aina Browser QA; LRS-2026-000001 is the LCL case (confirmed revisions 1 and 2); LRS-2026-000002 is FCL (confirmed revision 1). Its communication records are fictional acceptance examples, not claims that a real client was contacted. Existing Phase 1 QA/Demo records and active/inactive staff state were preserved.

Inspected screenshots are in [screenshots](screenshots): phase2-inquiry-complete-desktop.png, phase2-private-document-desktop.png, phase2-shipment-mobile.png, phase2-inquiries-mobile.png, phase2-clients-320.png, phase2-activity-desktop.png and phase2-overview-desktop.png. Earlier screenshots remain.

Chrome was checked, not a full browser/device/screen-reader matrix. The browser automation auto-accepts native beforeunload dialogs; cancellation behavior was checked through the attached event handler, while a human should still exercise the native leave/stay prompt. A full browser-wide JavaScript-disabled walkthrough and production backup/restore/pilot signoff were not performed.

## Remaining scope and operational limitations
No OCR/text extraction, AI proposals, mailbox connection, public intake, RFQs, quotes, markup, booking, automatic reminders or new client/vendor accounts. Those roadmap phases remain untouched. This phase does not verify email deliverability, scan malware, send inquiry messages, or prove production backup/restore and provider integration. Confirmation is for general cargo only; specialist cases require review/hold. Private evidence has no destructive delete/purge UI. Audit/version controls do not protect against a database administrator altering schema/data directly. Files cannot be repopulated after upload validation failure; other fields retain submitted values.

## Local run and manual acceptance
Normal installation: composer install, configure PostgreSQL .env, php artisan migrate, npm ci, npm run build, php artisan lrs:admin, php artisan serve --host=127.0.0.1 --port=8000. Prepared Windows helper: scripts/local.ps1 serve and scripts/local.ps1 test. See README for portable database restart and prerequisites.

Optional lrs:demo-inquiries is local/testing only, requires an existing active staff account, creates clearly labeled fictional clients/incomplete LCL/FCL drafts, and is idempotent. It sends and confirms nothing. DatabaseSeeder remains empty. Browser QA records are separately labeled.

1. Create a client and primary contact; verify normalized email, transfer primary, archive/reactivate and search.
2. Capture incomplete LCL intake; explicitly choose contact, owner and deadline. Check preserved source and visible gaps.
3. Upload a packing list; inspect private preview, metadata, duplicate warning and download.
4. Prepare/edit/approve a clarification. Copy it; verify no communication is recorded by approval or copy.
5. Explicitly confirm outside-LRS communication with recipient/channel/time. Record a customer answer with the document reference.
6. Complete cargo/route/scope/ready date and package facts; submit for review and confirm. Inspect reviewer/time/version evidence.
7. Change destination or package facts; verify new draft revision and preserved prior confirmation. Review/reconfirm; inspect both snapshots, client history and audit.
8. Check FCL rows and scope-dependent addresses. Missing weight/quantity/type or specialist cargo must block ordinary readiness.
9. Verify guest/inactive access to private file is redirected, cross-inquiry document IDs return 404, and a stale second edit is rejected.
10. Check phone layouts, horizontal table scrolling, focus/keyboard dialog navigation, unsaved-change prompt and clipboard fallback.

## Recommended next-phase coding settings
When Phase 3 is explicitly authorized: use **gpt-6.1-sol with high reasoning** for the extraction/review integration and tests; consider gpt-6-astra with high reasoning for a difficult evidence/conflict design review. This recommendation concerns Codex coding, not the AI model LRS will call at runtime. Choose and configure the runtime model separately during Phase 3, with explicit structured-output, source-evidence and usage requirements. Start by reading AGENTS and all five maintained docs plus the next attached brief. Reuse immutable versions, private originals and approval boundaries. Do not infer permission to start Phase 3 from this handoff.

---
## Historical Phase 1 handoff
# Phase 1 handoff
Completed locally on 4 October 2026. Phase 1 implementation is complete; no later phase, production deployment or pilot signoff is claimed.

## What works
Laravel 13.34.0 / PHP 8.5.2, server-rendered Blade, Tailwind 4.3.3 / Vite 8.3.2 and PostgreSQL 17.11 provide a functioning staff workspace.

- Login/logout, time-limited password reset/setup, no public registration, secure prompted admin setup, sensitive-action rate limits.
- Admin staff creation, role/status management, password setup/resend and last-active-admin protection.
- Agent vendor/contact/profile access; direct staff/settings requests are denied by server policies and middleware.
- Inactive accounts cannot authenticate or continue privileged work. Database sessions/reset tokens are revoked on deactivation; active checks also guard each authenticated request.
- Vendor list/create/detail/edit/status forms, normalized contacts, one primary quotation contact, duplicate-email prevention and nonblocking company duplicate warnings.
- Real server-side company/contact/email/coverage search, type/service/status/contact filters and pagination.
- Real-data overview, profile name/password changes, company display name/timezone/currency preferences.
- Transactional, sanitized vendor/contact/staff/settings audit entries and readable activity history.
- Responsive visual system, keyboard navigation/drawers/dialogs, inline validation, preserved values, honest empty/error/success states and server-rendered fallbacks.

Working routes are enumerated in README. Main screens: `/login`, `/overview`, `/vendors`, `/vendors/create`, `/vendors/{id}`, `/vendors/{id}/edit`, `/profile`, `/staff`, `/settings`.

## Important decisions
Native Laravel session authentication and password broker avoid an unnecessary auth/UI dependency. Business logic remains in PHP. PostgreSQL enforces case-insensitive email uniqueness and the partial unique primary-contact constraint. Staff mutations lock the singleton company settings row to serialize last-admin decisions. Audit snapshots exclude passwords, hashes, reset/remember tokens and secrets; users have no audit mutation route. This is not tamper-proof against database administrators.

Staff emails are fixed account identities in this phase. Contact creation/editing uses separate reusable forms; vendor status uses a standalone confirmation screen. Coverage is explicit multiline text and services are a small agreed list, without premature shipment/quote schemas. Currency is a display preference only.

Dependencies are versioned through Composer/npm lockfiles. Runtime packages are Laravel and the skeleton's first-party Tinker dependency. Development tooling includes Pint, PHPUnit, Laravel Boost/Pao/Pail, Tailwind/Vite and Phosphor SVG assets. Boost was installed to follow the scaffold's generated AGENTS.md. Unused concurrently/multiplex tooling and remote font loading were removed.

The accepted screen structure was refined following the user's Apple-style request: silver navigation, graphite typography, white rounded surfaces, blue controls and selective green/amber status color. The overview now includes a real-data directory health graphic; its categories and matching filters are covered by a test. No later-phase analytics or dependencies were introduced.

## Verification actually performed
| Command/check | Result |
|---|---|
| Official Laravel release and Tailwind/Vite references | PHP/framework compatibility and Tailwind setup checked |
| `php artisan migrate` on empty local PostgreSQL database | All four migrations passed |
| `php artisan lrs:admin` | Hidden password prompts exercised in tests and explicit local QA setup |
| `scripts/local.ps1 test` | **28 tests passed, 202 assertions**, PostgreSQL lrs_test |
| `php vendor/bin/pint --dirty --format agent` | Final pass clean |
| `npm ci` | Fresh lockfile installation passed; 37 packages audited, zero reported vulnerabilities |
| `npm run build` | Production assets passed; CSS 39.05 kB and JS 1.44 kB before gzip |
| `composer validate --strict` | Passed |
| `php artisan view:cache` | Blade compilation passed |
| `php artisan route:list --except-vendor` | Functional route/middleware structure inspected |
| `git check-ignore` | .env, .env.testing, local QA credentials and private mail log are ignored |
| PostgreSQL `current_setting('TimeZone')` | UTC |

The feature suite covers authentication/logout, guest protection, registration absence, rate limiting, inactive staff, direct agent/admin denial, staff creation, last-admin protection, session/token revocation, fixed staff email identity, reset expiry and sanitization, vendor CRUD/status/history, missing contact states, validation, primary transfer, scoped contact binding, real PostgreSQL constraints, duplicate warnings, search/filter/pagination, profile password checks, company preferences, audit rollback and secure setup. Mail failure/throttling preserves created staff and produces a warning.

Earlier environment/test issues were corrected: Laragon omitted pdo_pgsql; a local PHP configuration enabled it without changing global configuration. Laravel's subprocess needed inherited PHPRC. User model defaults were made consistent with database defaults. Docker's engine was unavailable, so a portable PostgreSQL instance was used; SQLite was never substituted.

## Actual browser evidence
Chrome was controlled with the agent-browser and agent-browser-verify skills. Desktop widths included 1440px (login also initially inspected at 1264px); mobile width was 390px, with an additional vendor-detail containment check at 320px.

- The refined Apple-inspired login, overview/health graphic, directory, vendor create/edit/detail and mobile login screens were visually inspected. The health legend's active/missing-contact link opened the matching server-filtered directory.
- After refinement, mobile directory/detail/edit/overview measured 390px at a 390px viewport; vendor detail also measured 320px at a 320px viewport. The drawer was rechecked with keyboard activation, focus containment, Escape and focus restoration. The agent permission state was recaptured, and the QA agent was restored to inactive after session revocation was rechecked.
- Refined staff, profile and company settings screens were inspected at desktop width. A no-results directory search and invalid sign-in submission rendered clear empty/validation feedback, with preserved email and an empty password field.
- A fictional vendor was created through the form with LCL/FCL services and an uppercase email, which persisted normalized.
- Editing, deactivation/reactivation, additional contact creation and readable activity worked through actual forms.
- Admin created an agent. A real password setup link was captured in the private local mail log, followed in the browser, and used to set the agent password and sign in.
- A direct agent request to settings rendered the permission state. A separate admin session deactivated the agent; their already authenticated browser was redirected to login.
- Mobile navigation opened by keyboard, closed with Escape and restored focus. Staff access confirmation did the same.
- Mobile detail/edit forms measured 390px at a 390px viewport. A directory overflow was found and fixed by positioning the table scroll region to contain its offscreen labels; the page then measured 390px while the table remained internally scrollable at 860px.
- A maximum-length unbroken heading was checked after long-content wrapping was added; no page overflow appeared.
- With the application JS asset deliberately blocked, sign-in, normal navigation, contact creation and vendor activation/deactivation still worked. The enhancement class was confirmed absent.
- Normal final screens produced no browser runtime errors or console errors; the only console output was Boost's development browser logger. The intentional JS-blocking and forbidden-route probes were separated from that final check.

Screenshots contain clearly fictional local data:
- [Desktop overview and health graphic](screenshots/overview-desktop.png)
- [Desktop directory](screenshots/vendor-directory-desktop.png)
- [Desktop login](screenshots/login-desktop.png)
- [Desktop vendor form](screenshots/vendor-form-desktop.png)
- [Desktop vendor detail](screenshots/vendor-detail-desktop.png)
- [Mobile overview and health graphic](screenshots/overview-mobile.png)
- [Mobile navigation](screenshots/navigation-mobile.png)
- [Mobile directory](screenshots/vendor-directory-mobile.png)
- [Mobile login](screenshots/login-mobile.png)
- [Mobile vendor form](screenshots/vendor-form-mobile.png)
- [Mobile vendor detail](screenshots/vendor-detail-mobile.png)
- [Mobile permission state](screenshots/permission-mobile.png)

- [Desktop staff](screenshots/staff-desktop.png)
- [Desktop profile](screenshots/profile-desktop.png)
- [Desktop settings](screenshots/settings-desktop.png)
- [Desktop no-results state](screenshots/no-results-desktop.png)
- [Mobile validation state](screenshots/validation-mobile.png)

## Key files
- `routes/web.php`, `bootstrap/app.php`: routes and active-session middleware.
- `app/Http/Requests`, `app/Policies`: authoritative validation and permissions.
- `app/Actions/SaveVendor.php`, `SaveContact.php`, `SaveStaff.php`: transactional business operations.
- `app/Support/Audit.php`: sanitized snapshots and append-only application activity.
- `database/migrations/2026_10_04_000001_create_phase_one_tables.php`: Phase 1 schema/checks/unique indexes.
- `app/Console/Commands/CreateAdmin.php`, `SeedDemo.php`: prompted setup and explicit fictional data.
- `resources/views/components`, `resources/css/app.css`, `resources/js/app.js`: reusable visual system and small enhancements.
- `resources/icons`, `licenses/PHOSPHOR-LICENSE`: locally bundled curated icons/license.
- `tests/Feature/PhaseOneTest.php`, `tests/TestCase.php`: meaningful PostgreSQL tests and test-database guard.
- `scripts/local.ps1`: prepared Windows local commands.

## Local state and run instructions
The app is available at http://127.0.0.1:8000; portable PostgreSQL listens only on 127.0.0.1:55432. Both lrs and lrs_test exist. Private environment files, runtime binaries/configuration, generated QA passwords and mail capture are ignored. Four explicit Demo vendors plus a Browser QA vendor are present. The QA admin is active; the QA agent is inactive after the revocation check.

Create your own admin: `scripts/local.ps1 admin`. Start/restart the app: `scripts/local.ps1 serve`. Verify: `scripts/local.ps1 test`. See README for fresh-machine prerequisites, PostgreSQL setup, assets, mail capture and database start/stop commands. Remove or deactivate fictional records before using the directory for real operations.

## Known limitations and manual acceptance
No blocking Phase 1 implementation gap remains. Browser checks covered Chrome, not a full browser/device or screen-reader matrix. Blocking the application module tested progressive fallback; a full browser-wide JavaScript-disabled pass remains in the manual checklist. External SMTP delivery and real contact ownership/deliverability were not tested; development log capture was tested. No mailbox integration, production deployment, backup/restore exercise or pilot approval was performed.

The manual acceptance checklist is in README. Company-specific branding, staff roster, service/routes, commercial terms, mailbox permissions and quote templates still require business confirmation before a pilot, as stated in the agreed brief.

## Next phase — only when requested
Phase 2 adds clients and manual inquiries: ownership/deadlines, explicit general-cargo LCL/FCL shipment/service fields, private attachments, review/clarification and shipment revisions. Reuse the design system, server permissions and transactional audit conventions. Keep extraction, RFQ sending, connectors, offers, quote arithmetic/PDFs, reminders and booking in their later phases.

Compatibility/design references: [Apple Human Interface Guidelines](https://developer.apple.com/design/human-interface-guidelines/), [Laravel 13 releases](https://laravel.com/framework/docs/13.x/releases), [Laravel password reset](https://laravel.com/framework/docs/13.x/passwords), [Tailwind with Laravel/Vite](https://tailwindcss.com/docs/installation/framework-guides/laravel/vite), [PostgreSQL version policy](https://www.postgresql.org/support/versioning/), [Phosphor assets/license](https://github.com/phosphor-icons/core).
