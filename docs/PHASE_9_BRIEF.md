Implement Phase 9 of LRS: Gmail integration using the existing approved email workflows.

Recommended Codex settings: GPT-6.1 Sol, Extra High reasoning (xhigh). Select these in Codex before running this prompt; the prompt cannot change the model. If unavailable, use GPT-6 Sol with Extra High reasoning. These settings concern development, not the application's AI API.

Run after Phase 8's relevant checks and handoff. Work in the existing repository and complete design and functionality together.

1. Inspect before extending

Read AGENTS.md, project documentation and Phase 5–8 handoffs. Inspect implemented Graph adapters, mailbox identity, outbox/reconciliation, approved revisions, private documents, incoming routing and reminder hooks. Run relevant existing checks. Keep Outlook working while extending the integration.

Preserve Laravel/PHP, Blade, Tailwind CSS, PostgreSQL, small vanilla JavaScript and existing queue/scheduler infrastructure. Reuse the professional design system. Do not build a second email application, switch stacks or replace approval rules with provider-specific shortcuts.

LRS is one company's Admin/Agent application. Email, public form and manual inquiries share one workspace. Agents choose vendors, approve mail and control pricing. AI cannot authorize provider actions. No client/vendor accounts are needed.

2. Provider architecture and mailbox routing

Add a focused Gmail adapter behind the existing mail service. Share business approval, release checks, incoming evidence, matching and follow-up rules. Keep provider-specific draft/message/thread/cursor semantics inside adapters; do not pretend Gmail IDs behave like Graph immutable IDs.

Represent each connection by provider and stable authorized mailbox identity. Scope provider identifiers to their mailbox and store opaque IDs/history IDs as strings. Define only the interface/capabilities this application needs; avoid a large plugin framework.

Admin may enable multiple incoming connections but choose one default outbound mailbox for new work. Existing approved messages and active conversation/reminder plans remain pinned to their authorized mailbox/envelope. A default change must not reroute queued sends or switch existing threads silently.

No automatic cross-provider resend/failover: a timeout in Outlook must not trigger a Gmail copy. Switching the mailbox for an existing request requires explicit staff authorization and the existing approval/revision rules. Connecting Gmail sends nothing and does not reactivate old reminder plans.

3. OAuth and permitted identities

Implement Admin-only Google OAuth using current official web-server guidance: secure callback/state validation, supported PKCE protection where applicable, offline access, encrypted server-side tokens and serialized refresh. Validate the authorized account against the configured company mailbox; a login hint alone is not account validation.

Preserve an existing refresh token if a later authorization response omits one. Handle revoked consent, missing permissions and refresh failures with reconnect/paused states rather than data loss or endless retries. Never put secrets/tokens in browser storage, logs or source control.

Request the least scopes needed for reading messages and managing/sending drafts, such as the documented gmail.readonly and gmail.compose scopes when appropriate. Do not request full mail.google.com access or mailbox-setting mutation merely for convenience. Document exact scopes and applicable consent-screen/testing/production verification requirements for this app's internal or external audience.

For the first implementation, authorize the mailbox through its own Google account. Gmail web-UI delegation or a Google Group address does not establish API mailbox access. Do not silently add service accounts/domain-wide delegation. Document unsupported configurations and their prerequisites clearly.

Use the authenticated address or a supported verified send-as alias. Read available aliases through supported permissions and require explicit envelope authorization. Do not create aliases or change mailbox settings automatically. Preserve correct From/Reply-To behaviour and never infer that an alias is another mailbox.

4. Draft preparation, sending and reconciliation

Build valid MIME messages from the exact approved subject/body, recipients, envelope and private attachment manifest. Use correct encoding/base64url, Unicode handling and safe header/filename construction. Enforce current provider/message/attachment limits, including encoding overhead. Never omit large files silently or replace them with public URLs.

Persist the Gmail draft ID before sending. Gmail's draft container ID and underlying message ID are different: replacing a draft changes its message ID; sending removes the draft and creates a SENT message returned by the send response. Store the resulting message/thread IDs separately. Do not copy Graph's same-ID-after-send assumptions.

Before submission, verify the prepared content still corresponds to the approved snapshot. If a provider draft can be edited externally, detect material differences and block for review rather than sending changed content. Reuse shared authorization, eligibility, attachment integrity and idempotent dispatch checks for both providers.

For replies, use documented threadId, In-Reply-To/References and subject requirements. Retain reliable RFC message identifiers for cross-message correlation. A provider thread alone does not prove every message belongs to one inquiry, vendor or quote revision.

Record a successful provider response as submitted/accepted with its evidence, not delivered/read. After a timeout, reconcile the existing draft/SENT evidence and correlation data. A missing draft alone does not prove sending; it could have been deleted. Do not create a new draft or retry an uncertain send blindly. Avoid exactly-once delivery claims.

Handle quota/rate limits, authentication/permission errors and temporary failures through bounded provider-aware backoff. Preserve Phase 8 caps and stop conditions; a transport retry must not become another reminder stage.

5. Incoming synchronization and evidence

Use queued scheduled polling for the initial integration; Google Cloud Pub/Sub is not mandatory. Implement a bounded initial import and Gmail history-based incremental sync following current documentation. Handle arrivals during the initial import so no message is skipped between full and incremental synchronization.

Persist imported pages before advancing the committed history cursor. Support pagination, replayed changes, label changes, partial attachment failures and overlap locks. Gmail history IDs may expire; an out-of-range 404 requires a controlled resynchronization, preserving deduplication and existing business records.

Process actual incoming messages from configured labels/import rules. SENT/DRAFT messages and label-only changes must not create new customer inquiries or vendor responses. Spam/trash handling must be explicit. Mailbox deletion/removal of a label must not erase stored business evidence.

Reuse source preservation, safe HTML rendering, private attachment limits, extraction queues, inquiry creation and ambiguous-match review. Link vendor replies to exact RFQ revisions and client replies to exact quotation revisions where evidence supports it. OOO/bounce messages remain separate from substantive replies and feed Phase 8's existing stop/hold rules.

Deduplicate within a mailbox using provider message IDs. For the same email received through two connections, use conservative RFC message-ID plus content evidence to associate duplicate source copies while retaining provenance. Otherwise flag a possible duplicate for staff; never automatically merge contacts or unrelated inquiries.

6. Design and operational visibility

Extend the existing mailbox settings with provider selection, connected identity, approved alias/envelope, incoming enabled, outbound default, last sync, health, reconnect and pause/disconnect controls. Show provider/mailbox context in dispatch and email history without making staff operate two unrelated workflows.

Clearly show which mailbox an approved message/reminder will use. Explain when a mailbox change needs renewed approval. Reuse existing incoming/unmatched and outgoing recovery screens, accessible components, responsive layouts and curated SVG icons. Keep secrets and raw provider internals out of ordinary screens.

7. Verify and hand off

Run shared contract tests for both Outlook and Gmail plus provider-specific fixtures. Cover OAuth/state/account mismatch, absent refresh tokens, alias authorization, MIME/attachments, draft versus sent IDs, threading, draft changes, successful sending, ambiguous timeout, quota failures and revoked access.

Test initial-import races, history pagination/replays, expired cursors, label-only changes, partial imports and duplicate copies across providers. Prove Gmail replies cancel the same reminder plans as Outlook replies and changing the default mailbox never reroutes or duplicates an existing dispatch.

Exercise browser flows for connection settings, incoming inquiry/reply review, approved RFQ/client quotation sending and reminder state. Use fixtures or explicitly authorized test mailboxes. Without Google configuration, finish code/UI/contract tests and list live checks still unverified. Do not claim production verification from mocks.

Run relevant tests, formatting checks and production asset build. Update the existing handoff location or create docs/phase-9-handoff.md with scopes/setup, supported mailbox/alias configurations, routing rules, sync/recovery, provider limits and remaining live checks.

Use current official documentation:

- [Google OAuth web-server guide](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Gmail API scopes](https://developers.google.com/workspace/gmail/api/auth/scopes)
- [Gmail draft creation and sending](https://developers.google.com/workspace/gmail/api/guides/drafts)
- [Gmail threading](https://developers.google.com/workspace/gmail/api/guides/threads)
- [Gmail synchronization](https://developers.google.com/workspace/gmail/api/guides/sync)
- [Gmail error handling](https://developers.google.com/workspace/gmail/api/guides/handle-errors)

Stop after Phase 9. Phase 10 covers client decisions, rate/capacity reconfirmation and booking handoff. Recommended settings: GPT-6.1 Sol, Extra High reasoning.