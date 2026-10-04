Recommended Codex model: GPT-6.1 Sol.
Reasoning effort: High.

Select these in the Codex model picker before starting; this prompt does not change the active model. If unavailable, use GPT-6 Sol with High if your client offers it.

You are continuing LRS, our logistics inquiry and quotation application. The user reports Phase 2 complete. Implement Phase 3A: a professional, branded customer inquiry page that agents can share, connected to the existing staff inquiry workspace.

Develop the design and working behavior together. Complete this phase, verify it, update the handoff, and stop.

## 1. Inspect and preserve the existing application

Read AGENTS.md, README.md, docs/PROJECT_BRIEF.md, docs/DESIGN_SYSTEM.md, docs/PHASES.md and docs/HANDOFF.md.

Inspect the actual Phase 2 routes, models, migrations, policies, inquiry statuses, completeness rules, revisions, private documents and tests. Resolve small directly blocking regressions before extending them. Preserve unrelated changes and existing records; never reset a populated database or recreate the project.

Use the existing Laravel/PHP, Blade, Tailwind CSS, PostgreSQL and small vanilla JavaScript stack. Keep installed compatible versions and Node/Vite as build tooling only.

Use Laravel conventions, reusable Blade components, Form Requests, policies and small actions/services. Do not introduce another frontend framework, separate backend or paid UI kit. Check documentation matching the installed Laravel version when APIs differ.

Phase 3A adds website intake. Email continues to be captured manually until Phase 5 connects Outlook. Phase 3B will add document text extraction, OCR fallback and AI-assisted review.

Do not implement those integrations, vendor outreach, pricing, a client portal or bookings in this phase.

## 2. Business result and public entry point

Build a public /request-quote page within this same Laravel application. Clients do not need an account.

Agents can copy the generated link and share it through email or WhatsApp; an existing company website can link to it. Preserve existing root/login routing unless an additive public route is needed.

The page should serve as a small, credible company inquiry landing page:

- Existing company branding.
- A short explanation of supported logistics services.
- The guided inquiry form.
- Company contact details.
- A privacy notice.

The initial service scope remains general cargo sea freight, LCL and FCL. Special cargo can be disclosed and routed for staff assessment; do not promise specialist service or instant rates.

Use real configured company details. Do not invent company credentials, certifications, client logos, testimonials, shipment volumes, response guarantees or prices.

In development, label placeholder branding/configuration honestly. Show a concise notice that submission requests an assessment and quotation; it does not confirm a price or booking.

Add a working “Copy customer inquiry link” action in a sensible staff location, with clipboard fallback.

Generate the URL from configured application settings. Clearly distinguish a local preview link from a deployed public URL; do not claim localhost is shareable externally.

## 3. Design and guided form

Extend the existing navy/teal/light visual system, typography, spacing and icon family.

Give the customer page its own calm layout without exposing the staff sidebar, staff navigation or internal actions. Use purposeful icons, clear hierarchy, restrained motion and accessible focus states. Avoid decorative dashboards, emoji icons and generic filler sections.

Use four short steps with a visible progress indicator:

1. **Contact:** contact name, email, optional company name and phone.
2. **Shipment:** origin/destination country and port or location, cargo description, LCL/FCL/Not sure, requested service boundaries, readiness date and technical details where known.
3. **Documents and notes:** optional attachments and additional requirements.
4. **Review and submit:** readable summary, edit links, privacy acknowledgement and a clear submission action.

Require contact name, valid email syntax, route endpoints and a cargo description at submission.

Accept uncertainty in technical details. Offer “Not sure” or “To be confirmed” for shipment mode, service scope, readiness date and special-handling questions. Store unknowns as nullable/explicit unknown values rather than zero.

Do not force customers to understand logistics terminology to submit a useful inquiry.

For LCL, support package groups, packaging type, quantity, gross weight, dimensions with explicit units or a client-declared volume.

For FCL, support container type/quantity rows and cargo gross weight.

Reuse Phase 2 field meanings and validation. Show plain-language help for LCL/FCL and weight/volume. Do not invent container capacities or chargeable-weight rules.

Pickup/delivery addresses become relevant when the requested service includes them. If the customer cannot provide complete details, retain the inquiry and show the gap to staff rather than declaring it ready.

Additional service requests and special cargo flags must remain distinct from ordinary general cargo.

Retain goods value, budget and existing freight reference price as separate optional values if already supported; none becomes vendor cost.

Preserve valid inputs when navigating steps and after errors. Show errors next to fields and focus the first error. Keep the review summary synchronized with current inputs.

Handle repeat package rows, back navigation, unknown selections and changes between LCL/FCL without silently deleting entered details.

Warn before leaving an edited form. Do not persist sensitive form contents in localStorage. A basic server-side submission path must remain usable if JavaScript fails.

Attachments are optional. Do not require an existing quotation, packing list or invoice.

Explain accepted types/limits before selection. If browser restrictions prevent restoring selected files after a failed submission, clearly ask for reselection; never show a file as uploaded when it was not stored.

## 4. One shared inquiry pipeline

A successful submission creates exactly one normal inquiry in the existing staff workspace, using the existing concurrency-safe reference generator, with:

- Source channel: **Website form**.
- Status: **Needs review**.

It must never become Ready for sourcing automatically, even if all fields are supplied.

Preserve an immutable original submission snapshot, received time, submitted contact details, structured shipment fields, source-document references and privacy acknowledgement/version.

Keep submitted values distinct from later staff corrections and confirmed shipment revisions.

Reuse the existing audit/timeline and add a clear system/public-submission actor; never attribute an anonymous submission or upload to a staff member.

Do not allow the public request to set owner IDs, client/contact IDs, deadlines, internal notes, statuses, approvals or verified flags. Derive trusted workflow fields on the server.

Use an existing valid company intake-owner setting where available, or add a small Admin-only default intake-owner setting.

If no active owner is configured, retain the case as visibly unassigned in the staff queue.

Use existing deadline rules when configured; otherwise leave the deadline unresolved and flag it for staff. Do not invent a company SLA.

Readiness still requires a valid owner, selected usable contact and response deadline under Phase 2 rules.

Preserve submitted contact/company data separately until staff resolves the client association.

If the current schema requires a client/contact immediately, make the smallest additive change to permit an unresolved association for public intake. Continue enforcing the existing requirements on staff-created inquiries and all Ready for sourcing transitions.

Do not create a second operational inbox or automatically pollute the approved client directory with untrusted submissions.

Offer staff possible existing client/contact matches, but require an explicit authorized selection or creation.

Never automatically attach a public inquiry to an existing company, expose existing contact information, or overwrite records just because someone supplied the same email.

Record the resolution and retain the original submission evidence.

Display website inquiries in the existing list, with a source filter/badge, contact-check state, ownership, missing details and next action.

Staff can resolve the contact, assign/deadline the case, use existing clarification records, edit the working shipment and perform the same human review/confirmation as manual inquiries.

Preserve Phase 2 revision and stale-edit safeguards.

## 5. Receipt, contact checks and limited transactional email

After a successful submission, show the receipt reference, honest next steps and company contact details.

Display receipt information only to the submitting browser through a limited session-bound receipt flow.

A reference number alone must not unlock a public record, shipment, attachment or status lookup. No client dashboard is needed.

Distinguish:

- Email syntax validation.
- Mailbox-access confirmation.
- Staff assessment of customer identity.

Do not label an address verified because syntax passed or an email was queued.

Implement mailbox confirmation without creating a customer account.

When an explicitly enabled, configured transactional mail transport exists, send one company-approved receipt/verification message to the submitted address.

Its fixed template may include the inquiry reference and confirmation action, but no quotation, commercial promise, attachments, full shipment details or internal data.

This limited transactional message is the only new automatic email behavior in Phase 3A; use the existing mail setup rather than building Outlook/Gmail connectors.

Use cryptographically random, scoped, expiring verification tokens stored as hashes, bound to the submitted email and inquiry.

Reuse compatible Laravel signing tools where helpful; a signed URL alone is not single-use.

Set a documented configurable expiry, initially 24 hours. Keep secrets/tokens out of logs and analytics.

Rate-limit sends, resends and confirmation attempts. Resending invalidates prior active tokens; confirming must be atomic and idempotent.

The email link opens a minimal confirmation page; an explicit POST confirmation completes verification.

Do not consume the token on GET because mail-link scanners can visit links.

Handle valid, expired, already-used and invalid links with clear safe messages, without exposing private case data.

Resend actions require the original receipt session or an authorized staff action; do not offer an unrestricted public email lookup.

Verification proves access to that submitted mailbox only.

Changing the working email invalidates the corresponding contact-check state. Do not mutate an existing client's approved contact data or verification status without staff resolution.

When outbound mail is disabled, unavailable or only captured by a development/log driver:

- Accept the inquiry.
- Show its reference.
- Retain an honest unverified/not-externally-sent state.

Do not block the phase waiting for Outlook or credentials.

Track queued, transport-submitted and failed states accurately; provider acceptance is not proof of delivery or reading.

A mail failure must not lose the saved inquiry.

Send jobs only after the inquiry transaction commits, with bounded retries and staff-visible failures. Avoid sending duplicate receipt messages on HTTP/job retries.

Clarifications, vendor RFQs and client quotations retain their separate agent approval requirements and remain outside this phase's sending scope.

## 6. Public uploads, abuse controls and data integrity

Reuse the private document system and supported Phase 2 formats: PDF, JPEG/PNG, DOCX, XLSX and CSV, subject to a configurable public allowlist.

Validate actual content/MIME, allowed extensions, counts, per-file size and aggregate request size on the server.

Reject executables, SVG/HTML, macro-enabled Office files and archives supplied as ordinary uploads. An Office file's internal ZIP packaging is not a reason to accept arbitrary ZIP files.

Use existing stricter limits where configured; otherwise propose defaults of:

- 5 files.
- 10 MiB per file.
- 30 MiB combined.

Show limits consistently in the UI. Document compatible PHP/web-server request limits and render useful upload-limit errors.

Store files privately with generated storage names and sanitized display names. Retain checksum/provenance/document class and association.

Classifications selected by customers are suggestions staff can correct.

All file access remains authorized staff access. Public receipts and verification links grant no document download rights.

Never trust client-supplied storage paths, expose permanent public URLs or render submitted HTML.

Do not fabricate malware-scan success. Reuse real scanning if already configured; otherwise show files as unscanned and document the limitation. Keep active/untrusted content out of inline previews.

Add CSRF protection, persistent server-side rate limits for submissions/upload attempts and verification/resends, a nonintrusive honeypot, and bounded inputs/array lengths.

Basic controls must work without a mandatory paid CAPTCHA service.

Use a trusted proxy configuration when resolving client IPs; do not accept arbitrary forwarded headers.

Keep abuse responses accessible and avoid trapping genuine customers without a recovery path.

Implement database-enforced submission idempotency, using a server-issued token bound to the form session.

A double click or retry with the same token must return the same saved reference and not create extra cases/files/emails.

Concurrent submissions must not bypass the constraint.

Distinct intended inquiries may share an email, so never deduplicate solely by email or cargo text.

Flag possible repeat/cross-channel inquiries for staff review without automatically merging or discarding them.

Keep checksum duplicate checks within the case and never reveal another customer's files.

Use transactions for related records and compensate private file writes if persistence fails.

Clean up only temporary files owned by the failed submission; never remove historical documents.

Avoid sensitive payloads/tokens in application logs. Preserve UTC storage and the configured display timezone.

## 7. Small, maintainable staff configuration

Reuse company settings. Add only settings needed for this flow:

- Public intake enabled/disabled.
- Public contact/service introduction.
- Privacy notice.
- Default intake owner.
- Approved receipt-template/transactional-email enablement where absent.

Only Admin edits company-wide settings; Agents can copy links and review cases under existing policies.

A disabled intake page gives a helpful unavailable/contact state rather than an error or a functioning submit button.

Keep any privacy notice accurate to implemented processing and editable by the company; do not claim legal approval or copy another company's policy.

Record acknowledgement without adding marketing consent by default.

Use the existing private inquiry states instead of inventing a parallel status system.

Keep email-verification and transport states separate from the shipment-review status.

Document every additive migration and the unresolved-client rules.

## 8. Verification and acceptance

Add meaningful PostgreSQL-backed tests for:

- Public validation, unknown technical values and conditional shipment fields.
- Original evidence, unresolved contact association and staff resolution.
- Existing-client spoofing protection.
- Private file access/type/size limits.
- CSRF/rate limits.
- Database idempotency under competing requests.
- Valid/expired/reused verification tokens.
- GET not consuming a verification token.
- Disabled/log mail modes, transport failures and receipt retry behavior.

Use mail fakes or local capture, never uncontrolled real customer sends. Use a separate test database.

Run relevant existing Phase 1/2 tests, formatting and the production asset build.

Inspect actual customer and staff screens at desktop and mobile widths. Check keyboard operation, errors, preserved inputs, file recovery, readability, clipping and browser console errors.

Use browser tooling if available and state anything not verified.

Do not replace tests with screenshots alone or claim a working connection without evidence.

Demonstrate this acceptance story with clearly labeled local demo records:

1. Agent copies the company inquiry link; an anonymous customer opens the branded page.
2. Customer submits an incomplete LCL request with a packing list and unknown volume.
3. Customer receives one reference; repeating the same submission returns that same reference.
4. Staff sees one Website form inquiry in Needs review, its original source/files and any missing owner/deadline/contact association.
5. Entering an existing contact's email in a separate submission neither exposes nor changes that existing client.
6. Staff resolves the client/contact, assigns the case/deadline, records clarification and later confirms readiness using Phase 2 gates.
7. Mailbox confirmation works through a controlled transport; GET leaves it unused, POST confirms, expiry/reuse behave correctly and it never approves shipment readiness.
8. Disabled or failed email leaves the inquiry available with truthful contact/send states.
9. An anonymous user cannot open staff records/documents using a reference or verification link. Invalid and oversized files are rejected cleanly.
10. An FCL/Not sure request and a special-handling disclosure retain the right details and require the appropriate human review.
11. Closing or disabling public intake does not break the staff workspace or manual email intake.

## 9. Handoff and stop

Update README.md and docs/PROJECT_BRIEF.md, DESIGN_SYSTEM.md, PHASES.md and HANDOFF.md with actual routes, screens, settings, source mapping, unresolved contacts, idempotency, upload limits, private access, verification expiry, mail behavior, queue requirements, tests and limitations.

Include a short manual acceptance checklist and explain how the company can configure branding, share the deployed link and enable controlled transactional mail.

Report what changed, why, migration/dependency requirements, checks and results, and any unresolved issue.

Complete the authorized implementation before requesting input about optional production settings; use existing configuration and safe local defaults where practical.

Do not deploy or enable real customer messages merely to prove the development flow.

Stop after Phase 3A.

The next phase is Phase 3B: text-first document extraction, OCR fallback, structured AI field proposals, source evidence, human review and usage/cost tracking within this same inquiry workspace.

Its recommended coding setting is GPT-6.1 Sol / Extra High when available; the runtime AI provider/model is a separate later decision.