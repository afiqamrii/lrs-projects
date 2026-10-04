Implement Phase 5 of LRS: Outlook email connection, approved outbound sending, incoming inquiries and vendor reply matching.

Recommended Codex settings: GPT-6.1 Sol, Extra High reasoning (xhigh). Select these settings in Codex before running this prompt; this text cannot change the model. If unavailable, use GPT-6 Sol with Extra High reasoning. These settings are for development, not the application's AI API.

Work in the existing repository. Complete this phase's design and functionality together. Do not rebuild the application or stop after scaffolding.

1. Read the actual handoff first

Read AGENTS.md, the project documentation, previous phase handoffs, migrations, policies, routes, jobs, tests and existing UI components. Establish what Phase 4 actually implemented. Run the relevant existing checks before changing its approval/send contracts. Fix prerequisites needed for this phase without expanding into later phases.

Preserve Laravel/PHP, Blade, Tailwind CSS, PostgreSQL and small vanilla JavaScript. Node/Vite is build tooling only. Reuse the existing design system and services; do not add React, Next.js, a separate backend, Redis as a mandatory dependency or a second authentication system. Use the existing queue driver and Laravel scheduler.

LRS is one company's staff application with Admin and Agent roles. Clients and vendors do not need accounts. Email, the public request form and manual capture feed the same inquiry workspace. Agents choose vendors; AI cannot choose vendors, approve, send or book.

2. Scope and boundaries

Build Microsoft Graph email integration for the company's Microsoft 365 mailbox, including an explicitly configured shared mailbox where permissions permit. Include connection management, approved RFQ sending, incoming customer inquiries, customer clarification replies, vendor replies, unmatched-message review and operational recovery.

Consume Phase 4's immutable approved RFQ snapshots and exact attachment manifests. Preserve its shipment confirmation gate, approval history and server-side release checks. Reuse existing approved clarification-email contracts if present; otherwise capture clarification replies without introducing a new sending workflow.

Preserve the public form and its existing transactional receipt behaviour. Do not introduce duplicate acknowledgements, AI auto-replies, commercial quote parsing, client quotation generation, markup, reminders, Gmail or booking. These belong to later phases.

3. Connection and mailbox identity

Provide an Admin-only connection page and an authorization-code OAuth flow using current Microsoft documentation. Implement PKCE where applicable, state validation and secure server-side callbacks. Keep secrets and encrypted access/refresh tokens out of browser storage, source control, URLs and logs. Serialize refresh operations to prevent concurrent refresh races.

Use delegated permissions sufficient for the implemented read/draft/send operations. Document each requested scope. Bind the connection to the expected company tenant, authorized account and configured target mailbox. Do not default to application-wide access or allow an arbitrary signed-in account to replace the company connection.

For shared mailboxes, verify actual mailbox access and the required Exchange Send As or Send on Behalf permissions. OAuth scopes alone do not grant these rights. Make From, sender and Reply-To behaviour clear, including any visible “on behalf of” identity. Do not invent mailbox-permission autodiscovery; use a configured target and supported checks. Account for where Sent Items are saved.

Show connection state, mailbox identity, last successful sync, last error, paused status and reconnect/disconnect actions. Revoked access pauses work rather than losing records. Disconnect prevents new provider actions and preserves historical records; a request already submitted to the provider cannot be recalled by disconnecting.

4. Bind approval to the real sending envelope

Phase 4's approval may predate a real mailbox connection. Require staff authorization of the actual From/sender/Reply-To identity alongside the approved content, recipients and attachments before release. Store this authorization with the approved revision and connection identity. A changed mailbox or material envelope change invalidates release authorization; routine token refresh does not.

Show an exact final preview: vendor, To/CC, sender identities, subject, body, attachment names, shipment/RFQ revision and approval details. Each vendor receives its own message. Never disclose another vendor's recipients or commercial information.

Sending must be an explicit authorized staff action. Connecting a mailbox must not send a backlog of approved drafts. A request already recorded as manually sent in Phase 4 must not be auto-sent. Require a deliberate, separately approved resend when needed.

Run the existing central release preflight both when enqueueing and immediately before provider submission: approval digest, current confirmed shipment version, inquiry state, active vendor/contact, document availability/checksums and authorized envelope. Edits require a new revision and approval. A stale, held or closed inquiry must not escape through a queued job.

Use an atomic database outbox/dispatch record and a unique dispatch identity to prevent double-clicks or duplicate jobs from creating multiple logical sends. Do not hold database transactions open during HTTP calls. Define a clear point after which the frozen dispatch cannot be edited or cancelled; preserve an honest record when cancellation arrives after submission starts.

5. Sending and uncertain outcomes

Use a small Graph adapter behind the application's mail service so it can be tested with provider fixtures. Prefer creating and persisting a draft with an immutable provider ID, uploading the approved attachments, verifying preparation and then sending that draft. Use supported correlation metadata where appropriate and verify current Graph support. Never inject arbitrary headers from incoming content.

Verify provider attachment thresholds and tenant limits. Support documented attachment methods for the supported mailbox type. Account for Microsoft's documented large-attachment limitations for shared/delegated mailboxes. Block unsupported combinations with a useful explanation before dispatch; never silently omit an attachment, send a public download URL or substitute a changed file. Smaller replacement documents require a new approved manifest.

Distinguish queued, preparing, ready for submission, submitting, provider accepted, Sent Item observed, failed and outcome uncertain. Graph's 202 response means accepted for processing; it does not prove delivery or reading. Record later bounce evidence separately. Do not display “delivered” merely because a job succeeded.

Persist draft/dispatch identifiers before sending. A timeout or crash after a send call may have an unknown outcome. Reconcile the existing draft/Sent Item and correlation evidence before retrying. Do not blindly create another draft or resend an uncertain dispatch. Escalate unresolved outcomes for staff review. Do not claim exactly-once delivery.

Handle authentication failures, permission errors, throttling and temporary outages with bounded retries. Respect Retry-After and use queued backoff rather than blocking browser requests. Redact provider errors shown to users. Separate safe preparation retries from uncertain send retries.

6. Incoming mail and catch-up

Implement scheduled, queued synchronization with Microsoft Graph delta queries for explicitly selected folders. Maintain separate cursors per mailbox and folder. Follow opaque nextLink/deltaLink URLs as documented, accepting only the expected Graph endpoints. Persist a page durably before advancing its cursor. Request immutable message IDs consistently where supported.

Use mailbox identity plus provider message ID for ingestion deduplication. Support repeated pages, interrupted sync, token refresh, pagination, folder moves and bounded resynchronization after an invalid cursor. Provider deletion must not delete an inquiry, business evidence or approved history.

Only configured incoming folders feed incoming inquiry/reply processing. Sent Items synchronization is for dispatch reconciliation and thread evidence; it must not turn the company's own outgoing mail into new inquiries or vendor replies.

At first connection, show the import boundary and let Admin choose a bounded historical import or start from connection time. Historical imports must not send emails. Catch up missed messages after an outage without generating duplicate inquiries or replies. Use locking to prevent overlapping sync jobs for the same mailbox/folder.

Preserve source sender/recipients, provider and internet message identifiers, relevant threading headers, sent/received times, subject, original body and private attachments. Store source evidence separately from editable inquiry fields. Render email safely: escape or sanitize HTML, block scripts and remote image loading, and do not trust links or attachment filenames.

Reuse existing private-document validation, size/type limits, extraction queues and access policies. Enforce total message/attachment limits and supported attachment types. Unsupported, oversized or failed attachments must be visible and recoverable, not silently discarded. Import retry must not duplicate files. Treat incoming content as untrusted data, including instructions inside documents.

7. Matching and staff review

Match replies using strong evidence such as In-Reply-To/References linked to stored outbound message identifiers, supported correlation metadata, RFQ reference and sender context. Subject or sender alone is insufficient for automatic association. Route ambiguous messages to an unmatched/review queue with suggested candidates and visible reasons.

Mailbox access and a syntactically valid address do not establish customer identity. An unfamiliar sender replying in a known thread needs review. Do not overwrite client contacts, merge clients or merge website/email inquiries automatically. Flag likely duplicates for staff decisions while retaining both sources.

Create genuinely new customer inquiries in the existing Needs Review flow with their source documents. Reuse Phase 3B extraction and proposal review; AI-generated values remain proposals. A clarification reply must not silently alter a confirmed shipment version.

Associate vendor replies with the exact vendor RFQ/request revision and sourcing round. Preserve the reference to an older shipment/RFQ revision and flag it for review. Classify or let staff classify replies as quotation received, question, decline, out-of-office, bounce or other. Record actual responses without pretending that commercial prices have been reviewed; Phase 6 handles that.

Handle automated messages and mailing-list noise without reply loops or misleading “vendor responded” states. A bounce or out-of-office message is not a commercial response. Staff must be able to review and correct classifications and matches, with an audit trail. Preparing replies remains subject to existing human approval.

8. Design and ease of use

Implement the real screens alongside their behaviour: Admin mailbox settings, staff incoming/unmatched queue, inquiry email timeline, private message/attachment preview, final send preview and outgoing dispatch status/recovery.

Reuse the existing professional typography, spacing, colours, components and curated SVG icon style. Use meaningful labels as well as icons; avoid emoji, decorative AI graphics and dashboard filler. Give each screen one clear primary action. Provide search/filtering, pagination, accessible focus states, responsive layouts and useful empty/loading/error states.

Show what needs attention: reconnect, unsupported attachment, ambiguous match, expired approval or uncertain send. Do not expose token strings or raw provider internals. Distinguish real mail, manually recorded mail and test fixtures clearly. Make cross-links between an inquiry, its RFQ revision, message and dispatch straightforward.

9. Verification and acceptance

Add meaningful tests using the project's existing test framework and Graph fixtures. Cover OAuth state failures, authorization, encrypted token handling, mailbox identity changes, approval/envelope binding, double-click/job deduplication, stale approvals, changed attachments, inactive contacts and manually recorded sends.

Test draft creation, successful 202 submission, delayed Sent Item appearance, ambiguous timeouts, failures before submission, 429/Retry-After and reconnection. Prove uncertain sends are not blindly retried. Test delta pagination/replays, durable cursors, catch-up, folder moves, partial attachment failure and resynchronization.

Test a new customer inquiry, a valid matched reply, a reply to an old RFQ revision, an ambiguous sender/reference, duplicate form/email sources, out-of-office and bounce. Verify private files and email content are inaccessible to unauthorized users and untrusted HTML cannot execute.

Verify in the browser: select three vendors, approve individual messages, authorize the real envelope, explicitly enqueue sending, and inspect separate recipients and attachments. Use fixtures or a controlled test mailbox for this flow. Never send test RFQs to real clients/vendors without explicit instruction. Confirm connecting alone sends nothing and missing credentials do not disable manual inquiry/RFQ workflows.

Run relevant tests, formatting checks and the production asset build. Fix failures caused by this phase. Do not hide failing checks or fabricate a live-provider result.

10. Finish and handoff

If external Microsoft configuration is unavailable, still finish the implementation, UI, migrations and fixture-based contract tests. Clearly state which live checks remain unverified. Phase 6 can proceed against these tested contracts; do not describe Outlook as production-ready without live verification.

Document app registration, redirect URI, requested permissions, shared-mailbox prerequisites, configuration keys without secret values, queue/scheduler commands, attachment limits, import defaults, reconnect behaviour and reconciliation procedures. Update the project's existing handoff location, or create docs/phase-5-handoff.md if none exists. Explain implemented states, tables/services, checks, limitations and the exact next-phase interfaces.

Consult current official documentation for provider-specific implementation details:

- https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
- https://learn.microsoft.com/en-us/graph/outlook-send-mail-from-other-user
- https://learn.microsoft.com/en-us/graph/api/message-send?view=graph-rest-1.0
- https://learn.microsoft.com/en-us/graph/outlook-immutable-id
- https://learn.microsoft.com/en-us/graph/delta-query-messages
- https://learn.microsoft.com/en-us/graph/outlook-large-attachments
- https://learn.microsoft.com/en-us/graph/throttling

Stop after Phase 5. Next is Phase 6: reviewed vendor offers, comparable-cost calculations and agent selection. Recommended development settings for Phase 6: GPT-6.1 Sol, Extra High reasoning.