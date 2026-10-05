Implement Phase 11 of LRS: useful operational reports, pilot verification and maintainable production readiness.

Recommended Codex settings: GPT-6.1 Sol, Extra High reasoning (xhigh). Select these in Codex before running this prompt; the prompt cannot change the model. If unavailable, use GPT-6 Sol with Extra High reasoning. These settings concern development, not the application's AI API.

Run after Phase 10's relevant checks and handoff. Work in the existing repository, finishing design and functionality together.

1. Establish the real completion state

Read AGENTS.md, project documentation, all available phase handoffs, tests and existing operational setup. Build a short evidence-based checklist of implemented, locally verified, live-verified and blocked capabilities. Previous prompts are requirements, not proof that features work.

Run relevant baseline checks and close defects needed for the complete workflow. Keep Laravel/PHP, Blade, Tailwind CSS, PostgreSQL, small vanilla JavaScript and current deployment/queue choices. Do not redesign the app, add a new analytics platform or perform speculative framework upgrades.

LRS remains one company's Admin/Agent system. Preserve human approval of shipment facts, selected vendors, outgoing content, client quotation and operational handoff. Email/public-form/manual workflows must remain usable when AI or a provider is unavailable.

2. Reporting with clear definitions

Build practical staff reports from real records: inquiries by source/status/owner, aging and pending next actions, vendor RFQ response times, unanswered requests, quotation activity, client decisions, booking/handoff outcomes, selected vendor cost and quoted selling/profit, plus AI usage/cost where recorded.

Define each metric and its date basis. Count business inquiries and current quotation outcomes without inflating totals through revisions, duplicate imports or multiple alternatives. State the win-rate denominator; show pending/expired outcomes separately. Vendor response time excludes bounces/OOO and uses documented actual send/meaningful-response timestamps.

Label markup/profit on quotations as quoted or estimated, not realized revenue. This system has no invoicing/payment ledger unless already implemented. Never imply that client acceptance or handoff alone proves earned revenue.

Preserve currencies; convert reporting amounts only through an explicit documented rate/basis, otherwise group by currency. Missing historic costs/timestamps remain unknown rather than fabricated zeroes. Clearly distinguish manually recorded sends and live-provider evidence.

Support date/owner/status/source/vendor filters, drill-down links, pagination and CSV exports with authorization. Neutralize spreadsheet-formula injection in exported untrusted text. Avoid fake performance scores and decorative charts; use tables and a small number of genuinely useful charts with definitions.

3. Access, data and query checks

Verify server-side Admin/Agent permissions across all actions, reports, settings and exports. Verify public form visitors cannot inspect inquiries, mail, vendor costs, client contacts or private documents by changing URLs/IDs. CSRF protection, validation and appropriate login/public-form rate limits must work.

Check private storage/downloads, HTML sanitization, remote-content handling, upload/OCR limits, audit history and token encryption. Keep secrets and personal/commercial content out of debug output and routine logs. Preserve source evidence and approved snapshots.

Inspect slow report/inbox queries with realistic fictional volumes. Add targeted indexes, fix avoidable N+1 queries and bound expensive imports/exports. Use measured results, not invented performance claims. Preserve exact decimal commercial calculations and existing eligibility gates.

Use the repository's existing dependency checks. Report vulnerabilities/checks unavailable because of environment limits honestly; do not make major upgrades without a demonstrated need and compatible verification.

4. Operational health and recovery

Provide an Admin health/attention view covering queue backlog/failed jobs, scheduler heartbeat, mailbox sync age, reconnect needs, uncertain dispatches, paused follow-ups, extraction failures and recorded AI usage/budget. Health must reflect observed timestamps/state rather than a permanently green indicator.

Document supervised workers, scheduler installation, timeouts/retries, logging/rotation and restart commands for the actual environment. Use existing database queue/cache support where appropriate; do not make Horizon/Redis or a paid monitoring service mandatory.

Retain per-dispatch and per-import correlation identifiers. Recovery actions must respect approval/idempotency rules. A “retry all” tool must not resend accepted/uncertain emails, obsolete quotes or old reminder stages. Test bounded catch-up and escalating exhausted failures.

Provide an Admin emergency pause for outgoing business mail across both providers and reminder jobs while incoming capture continues. All relevant worker release checks must honour it. Explicit resumption must still recheck stale approvals, expiry, caps and responses rather than releasing a backlog indiscriminately.

Keep external alerts behind explicitly configured recipients/channels. Internal health reporting must work without them. Do not send setup/test alerts to unspecified people.

5. Backups and a safe restore rehearsal

Define backups covering PostgreSQL business records, private source documents/generated PDFs and the secure configuration/key material required to restore encrypted data. Handle application keys/OAuth secrets separately through protected configuration; never package plaintext secrets into a public export.

Document backup frequency, retention, encryption/access, verification and company-chosen recovery objectives. Do not invent an SLA. Where execution is available, perform a restore rehearsal into an isolated test database/storage namespace without overwriting the working system.

Disable provider sends, external notifications and reminder execution in the restored environment. Verify record/document relationships, approved PDF checksums, audit history, encryption usability and representative inquiries. Restored accepted/uncertain dispatches must remain paused until reconciliation; never replay an outbox blindly.

Record what was restored, measured timing, limitations and evidence. If backup infrastructure/access is unavailable, finish scripts/runbook and clearly mark the live restore rehearsal as unverified.

6. End-to-end pilot acceptance

Create a repeatable pilot checklist and fictional fixtures covering:

- Email, public-form and manual inquiry capture, duplicates and private attachments.
- Local text/OCR/AI proposals, human correction and confirmed shipment versioning.
- Agent-chosen vendors, exact RFQ approval and separate approved sends.
- Vendor replies, incomplete versus comparable offers and agent selection.
- Markup, private cost view, client PDF/email approval and immutable revisions.
- Approved follow-ups stopping on replies, changes, expiry and uncertain mail outcomes.
- Outlook/Gmail mailbox routing and reconnection without duplicate sends.
- Current quotation acceptance, changed vendor terms, reconfirmation, blocked/approved handoff and separately evidenced booking.

Also exercise AI/provider outages, stale approvals, failed attachments, late replies and concurrent staff edits. Reuse meaningful existing tests; add coverage for demonstrated gaps rather than superficial tests that mirror implementation.

Inspect key desktop and mobile screens for consistency, focus/keyboard navigation, readable totals/statuses, empty/loading/error states and clear next actions. Fix defects alongside visual issues. Do not leave critical flows as placeholders or fabricate records to make dashboards look finished.

A controlled live-provider pilot uses only explicitly authorized test mailboxes/recipients. Complete local verification when credentials are missing, while listing live-provider limitations. Do not create real logistics bookings as tests.

7. Deployment and maintenance preparation

Prepare a runbook for the actual hosting approach: supported PHP/extensions, Composer/build steps, PostgreSQL, private storage permissions, HTTPS/session/cookie settings, environment configuration without secret values, migrations, cache commands, worker/scheduler setup and health verification.

Use APP_DEBUG=false in production and prevent demo/test users or fixtures from being seeded there. Preserve the application encryption key appropriately. Explain mailbox OAuth redirects, extraction/PDF dependencies and provider configuration.

Document rollout, pausing outgoing automation, migration/back-up preparation and rollback compatibility. A code rollback must not assume the database can be destructively rolled back. Production deployment occurs only when a target and authorization are already provided; otherwise finish the reviewable deployment package and record the missing target.

Consult current official Laravel documentation matching the installed version and [PostgreSQL backup guidance](https://www.postgresql.org/docs/current/backup.html). Do not assume a new framework major version is required.

8. Final verification and handoff

Run the appropriate full test suite, formatting/static checks already configured and production asset build. Summarize actual results and unresolved failures. Separate local test success from external OAuth, delivery, backup and production checks still awaiting real infrastructure.

Update the existing handoff location or create docs/phase-11-handoff.md. Include metric definitions, pilot results, known limitations, configuration/runbooks, recovery procedures and maintainers' next actions. Provide short staff guidance for normal work and common exceptions, using real UI labels.

Finish with a readiness report: verified capabilities, blocking issues, nonblocking limitations and remaining live checks. Do not label the system production-ready while material checks remain unresolved. Do not add speculative features to fill the report.

Stop after Phase 11. This completes the planned build phases; continue only with defects or explicitly requested enhancements.