Recommended Codex model: GPT-6.1 Sol.
Reasoning effort: High.

Select these in the Codex picker before starting; this prompt cannot change the active model. If unavailable, use GPT-6 Sol / High if offered. Coding settings are separate from the application's runtime AI model.

Continue the existing LRS logistics inquiry application.

Implement Phase 4: agents select vendors, prepare professional vendor RFQs, choose recipients and attachments, and approve the exact request version.

Develop the design and working behavior together. Verify the actual Phase 3B handoff locally, complete this phase, update documentation, and stop.

## 1. Inspect and preserve the existing project

Read AGENTS.md, README.md, docs/PROJECT_BRIEF.md, docs/DESIGN_SYSTEM.md, docs/PHASES.md and docs/HANDOFF.md.

Inspect the existing vendor/contact directory, inquiry statuses, confirmed shipment snapshots, revision guards, private documents, AI service/cost controls, policies, audit system, reusable UI and tests.

Keep Laravel/PHP, Blade, Tailwind CSS, PostgreSQL and small vanilla JavaScript.

Preserve compatible installed versions, existing records and unrelated changes. Use additive migrations, Form Requests, policies, small actions/services and existing Blade components.

Node/Vite remains build tooling only. Never reset a populated database, recreate the app or introduce another frontend framework/backend.

Briefly verify Phase 2 readiness/version rules, Phase 3A public/manual intake and Phase 3B reviewed field application.

Fix directly blocking regressions, then extend the working application. Missing AI credentials must not block deterministic RFQ preparation.

## 2. Scope and business gates

The agent controls vendor choice. This phase must not automatically discover, select, contact or rank vendors using AI. Use the company-maintained vendor directory.

RFQs request vendor prices and conditions; they are not customer selling quotations, purchase orders or bookings.

No markup, commercial vendor-offer comparison, vendor portal or automatic follow-up belongs in Phase 4.

Create an RFQ only from the current human-confirmed shipment version of an inquiry eligible for Ready for sourcing.

Keep the sourcing area visible for incomplete inquiries with useful missing-readiness reasons, but do not bypass the existing gate.

Approval requires:

- Current confirmed requirements.
- An active responsible agent.
- Usable selected vendor contacts.
- A chosen response deadline.
- Reviewed attachments.

Confirmed special-handling cases must still follow the project's existing manual-review restrictions.

Live RFQ sending, mailbox connections and incoming reply tracking remain Phase 5.

Do not add RFQ mail jobs, call an SMTP/provider send API, open an email composer automatically or reuse Phase 3A transactional receipt mail for vendor outreach.

Preserve that existing receipt feature.

## 3. Agent-selected vendors and recipients

Add a sourcing workspace within the inquiry with a searchable, filterable selection from active company vendors.

Reuse known route/service/LCL/FCL capabilities, contacts and minimum-order notes where already stored. Add only small optional capability fields if needed; do not rebuild the directory into a CRM.

Show capability/coverage as recorded facts or unknown. Do not invent reliability ratings, MOQ, coverage or availability.

If a vendor's documented limitation conflicts with the request, flag it for agent judgement and require a reason for retaining that vendor.

Hard restrictions such as an inactive vendor or missing usable recipient cannot be overridden into approval.

The agent selects one or several vendors. Each gets its own RFQ and a separate recipient list.

Never combine competing vendors in one To/CC/BCC list or reveal other selected vendors in the content.

Choose one primary To contact per RFQ, with optional explicitly selected CC contacts belonging to that same vendor.

Validate contact/vendor relationships server-side, normalize email consistently and reject header-injection characters. Email syntax validity is not proof of mailbox access.

Flag the same email used by multiple selected vendor records for deliberate resolution. Do not silently merge distinct companies or generate unnoticed duplicate requests.

Do not expose a public vendor/contact search endpoint.

Reuse an existing contact-management action or add a small authorized contact editor when needed. Saving a draft must not modify shared vendor records implicitly.

Record why a vendor was selected when there is a meaningful exception; do not demand a long justification for every normal selection.

## 4. RFQ structure and professional content

Create a shared sourcing round linked to one immutable confirmed shipment snapshot, with separate requests/revisions for each vendor. Adapt names/tables to current conventions.

Give each request a stable unique reference and distinguish later revisions.

Use concurrency-safe allocation and idempotent creation; repeated selection must not create duplicate requests accidentally.

All vendors in the round receive the same core shipment/service requirements so later quotes can be compared.

Personalize the greeting/contact and approved vendor-specific notes.

An alternative service is explicitly labeled as an alternative; it must not silently change the common requested baseline.

Generate a professional subject, introduction, structured shipment section, quotation requirements, response deadline and company signature.

Include:

- RFQ reference and revision.
- Confirmed route and cargo description.
- LCL package groups/weight/volume or FCL container groups and weight, with units.
- Cargo-ready date and requested arrival target where supplied, without promising delivery.
- Requested service scope, relevant pickup/delivery details and additional services.
- Special requirements and stated Incoterm/named place where relevant.
- Quote currency, freight and relevant origin/destination/extra charges.
- Unit basis and minimum charges where applicable.
- Inclusions, exclusions and tax/surcharge treatment.
- Quote validity, estimated transit, availability/lead time and vendor constraints.
- Requested reply-by date/time and the configured company reply contact.

Request these commercial details; do not invent their values or calculate vendor rates.

The response deadline is agent-selected, normally allowing time before the client's response deadline. Flag conflicts and require acknowledgement rather than inventing a company SLA or treating a requested deadline as a vendor commitment.

Compose shipment facts and the request checklist deterministically from the confirmed snapshot and template.

Keep the facts section tied to that snapshot.

Editing a material shipment fact follows the existing inquiry revision/reconfirmation path rather than a private RFQ-only override.

Agents can edit the subject, professional opening/closing and vendor-specific request notes.

Show a complete plain-text email preview and a safely escaped HTML rendering where useful. Strip unsafe/header content.

A print-friendly Blade preview is sufficient; do not add a server PDF dependency merely for this phase.

Use real company settings and signature details. If essential company reply information is missing, show a clear configuration gap.

Do not invent credentials, volume claims, partnerships or guaranteed shipment dates.

## 5. Optional AI wording with a working template fallback

Reuse Phase 3B's configured provider, purpose-specific run records, schema validation, explicit paid-run action, input/output limits, reuse, usage/cost accounting and failure handling.

Do not introduce another provider integration or change the extraction schema for unrelated requests.

Add a separate RFQ-wording purpose/schema.

Send only the necessary confirmed shipment/request context and permitted draft wording.

No full attachments, raw inbox history, competing vendor identities, client budgets or internal commercial data are needed.

AI may propose a professional subject/opening/closing and a clearer quotation request.

Keep the core shipment section and checklist deterministic.

AI cannot alter confirmed facts, add commitments, choose recipients, select attachments, invent rates or grant approval.

AI results are draft suggestions requiring an explicit agent apply/edit action.

Bind them to the current RFQ draft version; a delayed result cannot overwrite newer edits or an approved version.

Treat source notes as untrusted data and give the AI no tools or sending authority.

If the provider is disabled, fails or reaches budget limits, the deterministic template and manual editing remain fully usable.

Label template and AI origins honestly.

Do not spend again for unchanged wording/context unnecessarily, and do not request a second call just for cosmetic formatting.

## 6. Attachment selection and disclosure

No document is attached by default.

The agent explicitly selects inquiry-associated files for each vendor and previews the intended disclosure.

Show classification, filename, size, source version/checksum and real scan status.

Preserve existing private access and untrusted-content handling.

Never fabricate a clean scan or use a public URL as an attachment download shortcut.

Exclude internal notes, cost/markup information, other vendors' offers and customer reference quotations from default RFQ disclosure.

Do not automatically forward the original customer email or all inquiry files.

Commercial invoices may contain goods values needed for a requested service; the agent decides whether that disclosure is necessary.

Allow staff to upload a separate prepared/redacted copy through existing private upload controls, retaining its relationship to the original.

Do not silently modify originals, promise automatic redaction or include the original when a selected prepared copy was intended.

Validate selected documents belong to the inquiry and remain accessible/eligible.

Build a manifest containing document identity, immutable version/checksum, display name, MIME and size.

An additional inquiry attachment does not join approved RFQs automatically.

A selected attachment changing or becoming unavailable blocks current release eligibility until reviewed.

Show total selected size and configurable transport-size warnings, while preserving an honest distinction between a preparation limit and the future mailbox provider's actual limit.

No attachments are acceptable when confirmed facts suffice.

Client identity/addresses appear only where the agent explicitly approves the disclosure needed for the requested service.

Preserve necessary logistics facts; do not claim documents are anonymized merely because a company-name field was hidden.

## 7. Approval, revisions and current eligibility

Use a clear per-vendor draft/approval workflow, such as:

- Draft.
- Needs approval.
- Changes requested.
- Approved.
- Superseded.
- Cancelled.

Adapt to existing naming. Keep approval status separate from any manual/provider send status.

Authorized Admin/Agent staff can prepare and approve under existing project permissions.

Preserve an existing separation-of-duties rule if one exists. Do not introduce mandatory two-person approval for this small-agent workflow without a project requirement.

Record the actual human approver even when the same agent drafted it.

Provide a final review screen showing:

- Vendor.
- To/CC.
- Confirmed shipment version.
- Response deadline.
- Exact subject/body/signature.
- All selected attachments.
- Disclosure notes.

Approval must be a deliberate action on that exact revision.

Persist an immutable approved snapshot and a deterministic content/manifest digest with approver/time.

Freeze the actual rendered subject/body/signature, recipient addresses and attachment versions. Do not let future template/contact edits silently rewrite approved content.

Include the intended configured company reply contact in the snapshot.

Do not claim a transport From identity is connected or verified yet.

Document that Phase 5 must bind the actual sender/reply-to envelope through explicit staff authorization before automated dispatch. Changing it must not silently alter what was approved.

Changing recipients, subject/body, selected files, deadline or material requirements creates a new draft revision requiring approval.

Preserve prior drafts, approval evidence and shipment snapshots. Do not delete historical approval records.

If any of these occurs, block current release eligibility and explain why:

- Shipment requirements change.
- Confirmed readiness is withdrawn.
- The inquiry is held/closed.
- A selected vendor/contact becomes inactive.
- A selected recipient/attachment materially changes.

Reconfirm the inquiry and revise/reapprove affected RFQs.

Nonmaterial internal notes do not needlessly invalidate approval.

Use one central eligibility/preflight action on the server for approve, approved-copy/download and manual-send recording, ready for reuse by Phase 5.

Do not rely on disabled UI buttons alone.

Protect edits and approval with optimistic revision checks plus transactions/appropriate row locks.

If another agent changed the draft after preview, reject the stale approval and show the differences.

Approval and its audit event must commit together.

Repeated approval/creation requests must be idempotent.

## 8. Useful outputs before automatic sending

An eligible approved RFQ can provide:

- Copy actions for recipient, subject and exact plain-text body.
- Clipboard fallback.
- Authenticated downloads for only its selected attachment manifest.

Preparing/copying/downloading never marks it sent.

Allow an authorized agent to record that they sent that exact approved version outside LRS, with declared time/channel/recipient, confirmation of the version used and optional evidence.

Label it “Manually recorded as sent”; it is not provider-confirmed delivery or reading.

Prevent accidental duplicate manual-send records for the same dispatch.

Do not add a live Send button in Phase 4.

Clearly show “Approved — not sent” or the manually recorded state rather than a fake mailbox success.

Cancelling a draft affects future use; it cannot undo an already recorded external message.

Preserve historical manual-send evidence when requirements change and show that a revised request may be needed.

Prepare persisted approved records for Phase 5 to consume without rebuilding drafts.

A record already manually sent must not be automatically sent again when a mailbox is later connected.

Require a deliberate newly approved resend/revision for another dispatch.

Provider send IDs, incoming reply matching and reminders are not implemented here.

## 9. Professional sourcing workspace

Extend the existing navy/teal/light design, typography, icon family and Blade components.

Build actual screens/actions together:

- Inquiry sourcing overview with readiness gaps, round/shipment revision, chosen vendors and next action.
- Searchable vendor selection with capability/contact information and useful empty states.
- Per-vendor RFQ editor with structured facts, wording, recipients and attachments.
- Exact-version review/approval page and revision comparison/history.
- Approved output/manual-send recording and real audit timeline.

Use a simple step sequence:

Confirmed inquiry → Select vendors → Prepare requests → Review and approve.

Each selected vendor has its own visible draft/approval state. Show real counts only.

Use a readable editor/preview desktop layout and stacked mobile layout.

Preserve inputs and recoverable selections, warn about unsaved edits, focus field errors and support keyboard operation.

Keep primary actions specific, such as “Review RFQ for Vendor B” and “Approve revision 2”.

Show missing configuration, unavailable AI, inactive recipients, changed attachments, stale edits, blocked readiness and cancelled requests with useful recovery actions.

Avoid decorative charts, fake response metrics, dead buttons and implementation jargon in product copy.

## 10. Verify, hand off and stop

Add meaningful PostgreSQL-backed tests for:

- Policy enforcement.
- Readiness gating.
- Vendor/contact relationships.
- Duplicate request creation.
- Per-vendor recipient isolation.
- Attachment ownership/manifests.
- Approval snapshots.
- Stale concurrent approvals.
- Material-change invalidation.
- Revision preservation.
- Truthful manual-send records.
- Future automatic-resend prevention.

Test deterministic draft generation, disabled/failed AI fallback and delayed AI output against newer drafts using fake provider responses.

Confirm RFQ actions produce no mail/provider-send jobs. Keep existing Phase 3A receipt behavior working.

Use synthetic demo data and a separate test database, never real customer/vendor sends.

Demonstrate this acceptance story:

1. Open an incomplete inquiry and verify it cannot create/approve an eligible RFQ.
2. Complete and confirm an LCL inquiry, then select three existing vendors with their own contacts.
3. Generate three separate professional drafts containing the same confirmed shipment baseline and no competing vendor identities.
4. Select only necessary documents, inspect the disclosures, and edit one vendor's wording/deadline.
5. Generate optional AI wording or demonstrate the deterministic fallback. Verify facts/recipients/attachments remain controlled.
6. Review and approve each exact request. Confirm all are initially Approved — not sent and no RFQ email is sent.
7. Copy one eligible approved request and manually record an external send. Verify honest evidence/state and that copying alone did not mark it sent.
8. Change a recipient/body/attachment. Verify a new revision requires approval and history remains intact.
9. Change confirmed cargo quantity. Verify new shipment review and RFQ reapproval are required while the historical send remains recorded.
10. Have two agents edit/review concurrently. Verify stale approval cannot authorize the newer content.
11. Check inactive vendors/contacts, unauthorized private files and cross-vendor recipient injection are blocked.
12. Repeat with FCL and verify its container details, units and requested service scope remain accurate.

Run relevant existing tests, formatting and the production asset build.

Visually check actual desktop/mobile screens, keyboard flow, forms, previews, clipping and browser console errors.

State any checks not performed.

Update README.md and docs/PROJECT_BRIEF.md, DESIGN_SYSTEM.md, PHASES.md and HANDOFF.md with:

- Actual routes.
- Data/revision rules.
- Recipient/disclosure policy.
- States and approval eligibility.
- Manual-send distinctions.
- AI configuration/cost behavior.
- Tests and limitations.

Include a short manual checklist and describe exactly how Phase 5 will consume approved snapshots without resending manual dispatches.

Report what changed, why, migrations/dependencies, checks/results and known limitations.

Complete Phase 4 and stop.

Next is Phase 5: Outlook connection, approved outbound sends, incoming customer inquiries/vendor replies, matching/deduplication, catch-up and failure recovery.

Recommended next coding setting: GPT-6.1 Sol / Extra High when available.