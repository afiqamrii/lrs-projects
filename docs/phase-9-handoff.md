# LRS handoff · Phase 9

Implemented 5 October 2026 (Asia/Kuala_Lumpur). Authorization ends after **Phase 9**. The complete request is preserved in [PHASE_9_BRIEF.md](PHASE_9_BRIEF.md). Read [PROJECT_BRIEF.md](PROJECT_BRIEF.md), [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md), [PHASES.md](PHASES.md) and the historical [Phase 8 handoff](phase-8-handoff.md) alongside this document.

## Delivered workflow

Gmail joins Outlook, website and manual intake in one Admin/Agent workspace. Existing shipment confirmation, separate vendor RFQs, commercial review/comparison, reasoned selection, staff markup, private client PDF, exact approvals, outbox and bounded follow-ups remain authoritative. Gmail does not bypass any business approval. Connecting or selecting a default sends nothing and activates no reminders.

Admin adds personal Outlook, supported delegated/shared Outlook, or a Gmail connection at **/settings/mailbox**. Several connections may receive incoming mail. One selected outbound default applies to new work; frozen envelopes, queued sends, client quotation approvals and active reminder conversations stay on their authorized connection. There is no provider failover. An existing quotation's changed sender requires a deliberate new reviewed revision. An RFQ must receive explicit renewed envelope authorization after mailbox changes; previously dispatched content needs the existing deliberate revision rules.

UI keeps the established silver canvas, white cards, graphite type and blue actions, with restrained red Gmail and blue Outlook badges. Connection cards, verified From identity, incoming enablement/default state, sync health and recovery are shown together. Staff sees provider/connection context on previews, histories and dispatches. No analytics chart is invented for fictional activity.

## Google setup and exact scopes

1. Create a Google Cloud project and enable Gmail API. Configure Google Auth Platform audience, branding and data access.
2. Create an **OAuth client of type Web application** with the exact LRS callback. Local default: `http://127.0.0.1:8000/settings/mailbox/google/callback`. Production requires its exact HTTPS callback, private server secrets and approved audience/domain configuration.
3. Set **GOOGLE_CLIENT_ID**, **GOOGLE_CLIENT_SECRET** and **GOOGLE_REDIRECT_URI** in server `.env`/secret storage. Keep APP_URL accurate. Do not commit credentials, expose them in browser storage, or share them in chat.
4. As Admin, add Gmail, save the expected mailbox account email, company From name, proposed From address, a verified message limit and explicit import boundary within 90 days. Rights confirmation records the operator's review.
5. Click **Connect pinned account** (or Reconnect pinned account) and authorize through **that mailbox's own Google account**. LRS checks the verified identity email and stable Google subject against configuration, then independently checks Gmail profile email. A login hint is insufficient.
6. Check the listed sending identities. The selected From must be the primary authenticated address or an existing accepted send-as alias before release approval; the worker checks Google again before preparing. Set the outbound default only when the connection and From are usable. Incoming enablement is separate.

The requested scopes are exactly:

- `openid`
- `email`
- `https://www.googleapis.com/auth/gmail.readonly`
- `https://www.googleapis.com/auth/gmail.compose`

Readonly supports incoming evidence and the documented send-as listing. Compose supports draft creation/read/send. LRS does not request full `mail.google.com`, settings mutation, alias creation, Pub/Sub or domain-wide delegation. [Gmail scopes](https://developers.google.com/workspace/gmail/api/auth/scopes), [sendAs.list permissions](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.settings.sendAs/list).

Both Gmail scopes are restricted. Choose Internal only when the company and its Google Cloud/Workspace organization qualify; its Workspace administrator may still restrict access. An External app in Testing needs explicit test users (up to 100) and Gmail grants/refresh tokens expire after seven days. External production use requires the applicable OAuth verification; storing/transmitting restricted Gmail data on a server can require a security assessment unless an applicable exemption applies. Review the actual audience/deployment with Google before launch. These requirements are separate from local implementation. [OAuth web-server guide](https://developers.google.com/identity/protocols/oauth2/web-server), [testing/publishing requirements](https://support.google.com/cloud/answer/15549945?hl=en).

OAuth uses a confidential server client, one-use state tied to active Admin/session/configuration generation, a ten-minute expiry, S256 challenge/verifier, offline access and explicit consent. Tokens, scopes, aliases and refresh material are encrypted server-side. Missing refresh tokens preserve a known existing token for the same identity; a first grant without offline credentials cannot connect. Refresh has a serialized bounded lease and generation checks; revoked grants pause only their connection. No response body/token is logged or rendered.

**Supported Gmail configuration:** Gmail or Google Workspace user mailbox through its own Google account; primary address or verified send-as alias within that account. An alias is not another mailbox. Web-UI delegation and Google Groups do not establish API access to a different mailbox. Service accounts/domain-wide delegation are deliberately unsupported; adding them would require a separate Admin/security design and Workspace grants. Existing Outlook shared/delegated behavior is preserved.

## Provider identity, preparation and recovery

Additive migrations retain old Outlook ID 1, existing envelope digests, approved histories and business records. New envelopes carry a connection FK; legacy Outlook snapshots keep their original shape. Locally authored fictional emails may have no connected-provider FK. Actual incoming sources always retain their connection and mailbox identity. Google subject/account IDs, message/thread IDs and history values are opaque strings. Original provenance/content digest becomes immutable. Migrations 13–16 are additive; source guard hardening is idempotent across fresh and existing installs. Application rollback is intentionally refused to preserve this evidence; teardown is permitted only in the isolated testing environment with a PostgreSQL database name ending in `_test`.

Gmail has separate **draft container ID**, **draft message ID**, **sent message ID** and **thread ID**. Sending removes the draft container and creates a new sent message; LRS stores all identities separately. It never treats a Gmail draft message ID as the sent ID. [Gmail draft semantics](https://developers.google.com/workspace/gmail/api/guides/drafts).

Symfony MIME builds the exact approved Unicode subject/plain body, recipients, From/Reply-To, RFC Message-ID, correlation header and private attachment manifest. Immediately before shared submission, LRS reads the full prepared draft and checks headers, exact From/Reply-To display names and addresses, recipients, body, extra HTML/attachments, names/types/sizes/checksums, correlation and approved threading. External edits block release. Private files are reread and checked; no public link is substituted.

Application caps are conservative: combined decoded outbound attachments **25,000,000 bytes**, exact raw MIME at most **35,000,000 bytes** and the configured lower connection limit, and base64url payload at most **48,000,000 bytes**. Admin configuration accepts 1–33 MiB. Encoding/headers count toward the actual MIME limit. Google's discovery advertises a 35 MiB draft upload maximum (36,700,160 bytes); personal Gmail attachment help states 25 MB, while Workspace administrators can set attachment limits. Recipient limits can be lower. These are application caps, not claims of universal deliverability. [Upload guide](https://developers.google.com/workspace/gmail/api/guides/uploads), [official discovery](https://gmail.googleapis.com/$discovery/rest?version=v1), [Gmail attachment limits](https://support.google.com/mail/answer/6584).

A reply carries the approved Gmail thread ID plus exact RFC In-Reply-To/References and a subject matching the original after Re-prefix normalization. Admin must explicitly create an appropriate policy such as `Re: [original_subject]`; old policies are not rewritten. Thread ID alone never associates a reply with a business revision. [Gmail threading requirements](https://developers.google.com/workspace/gmail/api/guides/threads).

Provider success means **accepted/submitted**, then exact **SENT evidence observed** when reconciliation succeeds. Delivery/read and commercial acceptance remain unconfirmed. Unknown creation or submission is uncertain. Reconciliation searches exact RFC/correlation evidence, verifies the full sent message and detects multiple matches. A missing draft is inconclusive, including externally deleted drafts.

If interrupted creation yields one exact existing draft and no submission ever started, reconciliation records that container as a failed preparation requiring **explicit staff recovery**; it neither recreates nor automatically sends it. If submission started, finding an unsent-looking draft still does not authorize retry. Keep uncertain work in reconciliation/staff review. No exactly-once delivery claim or cross-provider retry is made. [Draft listing](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.drafts/list).

Quota 403 reasons and 429 use bounded Retry-After/exponential backoff; permission 403/revoked access requires reconnect. Preparation retries are bounded to six attempts, observations to ten, and worker/folder leases remain bounded. A transport retry uses the same dispatch/stage/reserved count. [Gmail error handling](https://developers.google.com/workspace/gmail/api/guides/handle-errors).

## Incoming synchronization and business evidence

Polling uses the existing mail queue and minute scheduler. Default Gmail label is Inbox; Admin may explicitly verify/select a user label. SENT, DRAFT, SPAM and TRASH are excluded. Label-only changes never create inquiries or replies. Removal/deletion preserves stored sources, private files and business associations.

Initial import captures a history anchor **before** listing bounded messages. Pages and per-item offsets are durable; after the list completes, history catches up from that anchor before the committed cursor advances. This includes arrivals during initial import. History pages are replay-safe. IDs/cursors are opaque bounded strings. HTTP/file work occurs outside short commit locks; overlap leases prevent concurrent folder imports. A cycle reaching 5,000 changes pauses for controlled staff recovery.

History 404 means the retained cursor expired. The folder pauses and Admin must choose **Resync**, an explicit boundary within 90 days and a reason. This preserves source/provider-ID deduplication and existing cases; LRS never silently drops the cursor or evidence. [Gmail sync guidance](https://developers.google.com/workspace/gmail/api/guides/sync).

The complete Gmail payload is encrypted in the private mailbox disk with its checksum/path in immutable source evidence. Staff sees safely escaped text/original HTML source; no remote images or active email HTML execute. Existing attachment format/count/size/private storage, partial import/retry, source proposals and human review apply. Normal text intake retains the shared 1 MiB processing bound; larger unsupported content stops for review instead of silently truncating/importing it. Plain/HTML body parts stored externally by Gmail are fetched within that same combined bound before intake or draft verification. A failed/missing body fetch retains the page/offset for retry and cannot create an empty inquiry. The original full payload remains unchanged and encrypted; body parts are not presented as customer attachment files. [MessagePartBody contract](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages.attachments).

Within a connection, provider message IDs deduplicate. Across providers/connections, identical RFC Message-ID plus exact sender/recipients/subject/body evidence can associate a duplicate **source copy**, retaining both origins. Attachments or conflicting evidence require possible-duplicate staff review. Contacts and unrelated cases are never merged automatically. Gmail replies reuse sender + RFC/reference + approved revision matching; automated/ambiguous/unfamiliar replies retain separate review.

A new customer source enters Needs review. Commercial prices, client associations and shipment values still require human assessment. Vendor replies and client questions reuse Phase 8 stop/hold contracts. Unreviewed/partial/ambiguous evidence holds automation; human classification tied to the exact client quotation stops it. All enabled incoming connections must be usable, have a current selected incoming folder and be freshly caught up before reminders; explicitly pause an unused incoming connection to remove it from that prerequisite. No booking/outcome is created.

## Local run instructions

Preserve the populated `.env`, private files and `lrs` database. Use forward migrations; never reset or migrate:fresh business data. Prepared PHP/PostgreSQL details and fresh-machine installation are in [README](../README.md#new-installation).

~~~powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
~~~

If the prepared local PostgreSQL cluster is stopped:

~~~powershell
& .tools/pgsql/bin/pg_ctl.exe -D .tools/pgdata -l .tools/postgres.log -w start
~~~

In separate terminals, from the same directory with PHPRC set:

~~~powershell
php artisan queue:work database --queue=mail,extraction,ai,default --tries=2 --timeout=330
~~~

~~~powershell
php artisan schedule:work
~~~

After code/config releases, restart supervised workers. Inspect `php artisan schedule:list`. Production needs its own supervised workers, shared lock store, one scheduler, private storage, backups and deployment configuration; no deployment occurred here.

Open [connections](http://127.0.0.1:8000/settings/mailbox), [incoming](http://127.0.0.1:8000/mail), [outgoing](http://127.0.0.1:8000/mail/outgoing), [attention](http://127.0.0.1:8000/attention). Staff sign-in uses existing accounts. Create a personal Admin with `php artisan lrs:admin`; no default production password is seeded.

For labelled local acceptance only, temporarily set MAILBOX_DEMO_ENABLED=true, clear config cache if used, and use Admin's **Connect fixture transport** on a Gmail connection. This is private local/testing transport, never Google. Authorize an exact fictional parent, enqueue, then drain:

~~~powershell
php artisan queue:work database --queue=mail --stop-when-empty --tries=1 --timeout=300
~~~

Repeat explicitly requested sync until initial/history catch-up is complete. For an active synthetic plan, use its displayed approved due date:

~~~powershell
php artisan lrs:followup-tick --plan=PLAN_ID --fixture-at=APPROVED_ISO_INSTANT
php artisan queue:work database --queue=mail --stop-when-empty --tries=1 --timeout=300
php artisan lrs:followup-tick --plan=PLAN_ID
~~~

The fixture clock stays on that plan's authorized connection. An explicit `--plan` tick may recover recorded outcomes for stopped/paused plans but creates no new stage there; advancing a fixture clock still requires an active/held plan. The global scheduler considers only eligible active/held plans. After acceptance stop/disable plans/policies, disconnect fixture access and restore MAILBOX_DEMO_ENABLED=false. Preserve all histories.

## Verification record

- Complete sequential guarded PostgreSQL regression passed: **292 tests / 2,558 assertions**, recorded in `.tools/phase9-final-full.xml` and `.tools/phase9-final-full.json`.
- After the final MIME display-name checks, stopped-plan outcome recovery, opaque-identity hardening and external text-body retrieval refinements, **28 affected tests / 208 assertions passed** (`GmailIntegrationTest`, `GmailSyncTest` and one `DatabaseMigrations` intake/teardown check), recorded in `.tools/phase9-final-focused.xml`. The complete suite was not repeated after these final affected refinements; its current total includes two additional body-retrieval tests.
- Coverage includes both providers' frozen default routing, exact private client PDF/RFQ files, separate Gmail IDs, Unicode/header safety, altered draft sender/content/files, missing draft, ambiguous submit and interrupted preparation recovery, one-use OAuth/account/scopes/refresh/revocation, verified aliases, quota retry/count preservation, exact threaded human-reviewed response cancellation, initial arrival races, history pagination/replay/expiry, label exclusions/removal, partial files, external body retries/limits and conservative cross-provider copies.
- An initial complete run exposed a migration teardown restriction; isolated `testing` databases ending in `_test` can now perform the required teardown. Normal populated databases retain the forward-only rollback refusal. The repaired complete and final affected runs passed. Business data was never reset.
- After the final folder field-ID and fixture-alias wording refinements, the Admin/Agent connection/default UI contract passed again: **1 test / 10 assertions** (`.tools/phase9-final-ui.xml`); 169 compiled views passed syntax checks again.
- Required Pint dirty formatting and explicit new/affected PHP formatting passed. Composer strict validation passed with no Phase 9 dependency changes. **169 compiled Blade views** passed PHP syntax checks; production Vite assets built successfully. Additive migrations **13–16** are applied, scheduler entries are present, and `git diff --check` passed (Git reported only Windows line-ending notices).

Actual browser acceptance used the running Laravel app/PostgreSQL/private local fixture, with Admin's normal forms and the existing queue workers. No live provider account was used.

- Added Gmail connection **2**, selected its verified fictional sender and outbound default, and explicitly paused unused Outlook incoming polling. Existing approval/dispatch histories remain pinned.
- Imported a fictional customer inquiry **LRS-2026-000013** for Port Klang → Singapore machinery. Source remained private, clearly fictional and Needs review; no automatic shipment/contact approval.
- Deliberately prepared and approved fictional client quotation **revision 11 / approval 5** for inquiry **8**, retaining the earlier Outlook lineage. Gmail dispatch **8** carried its exact private customer PDF and reached observed SENT evidence.
- Explicit policy **version 3** and new exact activation retained the prior cumulative count. Gmail reminder dispatch **9** used the approved original thread. Its imported question was human reviewed against that exact quotation revision. Plan **1** is **Stopped, 2/2 cumulative sends, no pending date**, with both stages **Accepted**. An explicit tick recovered the already accepted stage display after stopping without creating another stage or send.
- Explicitly authorized and sent the existing fictional vendor RFQ **inquiry 9 / revision 2** through Gmail: dispatch **10** shows **Sent Item observed · delivery unconfirmed**. Reconciliation made no additional send.
- Inspected desktop (including 1440px), 390px incoming/settings, and 320px settings/follow-up screens. Measured document width matched viewport width on checked phone pages. Keyboard Enter opened navigation; Escape closed it and returned focus. The folder recovery fields now have unique per-folder IDs. The final settings DOM had no duplicate IDs or unlabelled controls.
- Submitted an invalid business-day schedule at 320px: the server returned a visible alert, preserved the entered `two` value and set `aria-invalid=true`, without saving a policy. Saving the valid disabled policy returned its success state.
- Final fresh browser session reported no page runtime errors; console contained the local Laravel Boost browser-logger notices. One earlier automation session stalled, while the app still returned HTTP 200; acceptance continued in a new session.
- Screenshots: [desktop RFQ](screenshots/phase9-rfq-desktop.png), [390px connection cards](screenshots/phase9-connections-phone.png), [390px original incoming question](screenshots/phase9-incoming-phone.png), [320px stopped reminder](screenshots/phase9-stopped-reminder-phone.png), [320px final disconnected settings](screenshots/phase9-cleanup-320.png).

Cleanup preserved all evidence: Outlook **1** and Gmail **2** are **Disconnected / Incoming paused**; client policy **version 4 is disabled**; plan 1 remains stopped. `MAILBOX_DEMO_ENABLED=false` is restored and configuration cache cleared. Gmail 2 remains the selected default but is disconnected, so new provider release is blocked until Admin configures/authorizes a usable account and its exact envelope. Connection does not reactivate this history. Real website intake and manual work remain available.

No live Google/Microsoft configuration exists locally. No real client/vendor message, real mailbox import, live AI call, production deployment or pilot signoff occurred. Fixture/HTTP-contract tests do not prove live Google consent/PKCE acceptance, Workspace policy, alias presentation, provider normalization/thread behavior, actual quotas, delivery or production scheduling. A full assistive-technology audit and normal-browser physical download saving remain manual checks.

## Phase 9 manual acceptance checklist

Use explicit fictional fixture transport or a separately authorized controlled test mailbox; never real customers/vendors for acceptance.

- [ ] Admin adds/configures Gmail and Outlook; Agent/guest controls are denied. Adding, connecting, enabling incoming and changing default produce no sends or reminder activation.
- [ ] With live Google configuration, verify consent/scopes and own-account matching. Wrong account, expired/replayed/cross-session state, missing permission, denied grant and missing first refresh token must fail. Reauthorization without a new refresh token must preserve the same-account token; revoke consent and verify only that connection pauses.
- [ ] Check primary/verified alias listing; pending/removed aliases cannot authorize or send. Changing From/Reply-To requires explicit renewed approval; aliases are not incoming mailboxes.
- [ ] Approve an RFQ and client quotation with exact Unicode subject/body/To/CC/From/Reply-To and private manifests. Explicitly enqueue and inspect accepted/SENT states, different draft-container/draft-message/sent-message IDs and exact client PDF bytes.
- [ ] Edit/delete a provider draft externally. Changed content/extra files must block; a missing draft or uncertain send must never create another send. Test interrupted creation, one exact recovered draft, staff recovery and duplicate SENT correlation.
- [ ] Switch default after an approved queued Outlook send and after a queued Gmail send. Both must remain pinned. Repeat enqueue/worker and confirm no duplicate. No timeout may initiate cross-provider failover.
- [ ] Import an incoming customer source and explicitly review/assess it. Confirm Needs review, original encrypted/private evidence, no automatic shipment/contact approval and safe HTML behavior.
- [ ] Exercise initial-import arrival race, history pagination/replay, label-only/excluded changes, deletion, partial attachment failure/retry and overlap. Cursor advances only after retained pages commit.
- [ ] Force history expiry; inspect paused recovery, explicitly resync within 90 days with a reason and confirm preserved cases/files and no duplicates.
- [ ] Receive the same RFC email through two connections: identical no-file copies retain provenance without another case; conflicting/file-bearing copies require staff review rather than merging.
- [ ] Approve a Gmail-compatible policy and exact parent activation. Reminder stays in its accepted original thread after default switch. Import exact vendor/client replies, review classification/revision, and confirm the same stop/hold rules/cumulative caps as Outlook. Quota transport retry must not create another logical stage.
- [ ] Inspect connection/health, error, no-credentials, incoming/unmatched, outgoing/recovery and stopped-reminder screens at desktop/390px/320px; keyboard focus and visible labels must remain usable.
- [ ] Complete the full guarded PostgreSQL suite, Pint, Composer validation, compiled Blade syntax and production build. Record live checks separately; clean up fixture connections and enabled policies.

## Phase 10 continuation

Phase 10 is **not implemented**. Consume exact incoming client quotation revision, reviewed response/classification evidence and immutable quotation/approval/dispatch lineage. Add formal client decision, rate/capacity reconfirmation and explicit booking/job handoff only under that phase's business authorization. Accepted transport is not client acceptance, a delivery claim, or booking permission. Preserve provider provenance, shared approvals, private customer projection and reminder cancellation contracts.
