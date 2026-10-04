You are continuing LRS, our logistics inquiry and quotation web application.

Implement **Phase 2: Clients and the Manual Inquiry Workspace** in the existing repository. Build polished design and working functionality together. Complete this phase, verify it, then stop.

## 1. Inspect the existing application

Read:

- AGENTS.md
- README.md
- docs/PROJECT_BRIEF.md
- docs/DESIGN_SYSTEM.md
- docs/PHASES.md
- docs/HANDOFF.md

Inspect the existing routes, migrations, models, policies, tests, layouts, and reusable components.

Phase 1 has been reported complete. Verify its authentication, Admin/Agent permissions, vendor directory, and design conventions before extending them. Fix directly blocking regressions and preserve unrelated changes.

Do not recreate the application, replace its visual identity, or run destructive database resets on a populated database.

Make routine implementation decisions independently. Explain actual blockers and clearly distinguish completed verification from checks you could not run.

## 2. Preserve the stack and architecture

Continue using:

- Laravel/PHP
- Blade templates
- Tailwind CSS
- PostgreSQL
- Small, modular vanilla JavaScript where necessary
- Vite/npm for frontend asset building only

Preserve installed compatible versions.

Use Laravel conventions: Eloquent relationships, Form Requests, policies, transactions, reusable Blade components, and small actions/services for substantial business operations.

Keep the architecture easy to maintain. Do not introduce another frontend framework, a separate backend, a paid UI kit, or speculative infrastructure.

Use PostgreSQL for meaningful database tests with a separate test database.

## 3. Phase 2 outcome and boundaries

By completion, staff must be able to:

1. Create a client and its contacts.
2. Manually capture a customer shipment inquiry.
3. Upload and view supporting documents.
4. Assign an owner, priority, and response deadline.
5. Identify missing shipment information.
6. Prepare and approve a clarification for manual communication.
7. Record the customer’s answer.
8. Confirm a shipment version as Ready for sourcing.
9. Revise shipment requirements while preserving earlier confirmed versions.

This remains a single-company staff workspace.

Admin and Agent can manage clients and inquiries. Only Admin retains staff administration and company settings privileges. Inquiry ownership identifies responsibility; it does not introduce a new access boundary within this shared workspace.

Clients and vendors do not receive accounts in this phase.

Implement manual intake only. AI/OCR extraction, mailbox connections, public inquiry forms, vendor outreach, quotations, markup, bookings, and automatic reminders belong to later phases.

## 4. Client directory

Implement working:

- List, search, filters, and pagination
- Create and edit
- Client detail
- Archive and reactivate
- Related contact management
- Actual inquiry history

Client fields:

- Company or client name
- Optional company/reference identifier
- Contact/billing address where known
- Internal notes
- Active status

Use separate related contact records containing:

- Name
- Email
- Optional phone and role
- Active status
- Primary inquiry contact flag

Allow multiple contacts. Enforce at most one active primary contact.

Allow incomplete client capture, but visibly identify missing usable contact information. A confirmed inquiry requires an explicitly selected usable contact belonging to that client.

Validate email syntax and normalize consistently with Phase 1. Do not describe an address as verified merely because its syntax is valid.

Warn about likely duplicate clients without blocking legitimate branches. Archive instead of destroying history. Archived clients should not appear in default selection for new inquiries.

## 5. Manual inquiry intake

Implement the inquiry list, creation form, detail workspace, draft editing, assignment, priority/deadline updates, hold/resume, and closure.

Generate a stable unique reference such as:

LRS-2026-000123

Use a concurrency-safe database-backed allocation method, not row count plus one.

Store:

- Client and selected contact
- Inquiry title
- Responsible active staff member
- Normal or urgent priority
- Received timestamp
- Response due timestamp
- Source channel
- Original source text
- Internal notes

Supported manual source channels:

- Email
- Phone
- WhatsApp
- Other

Selecting Email means the agent is manually recording an email. Do not imply that a mailbox has been connected.

Preserve original source text separately from later shipment edits. Render it safely as escaped text.

Support additional communication entries with channel, author, timestamp, notes, and supporting document references.

Default ownership to the creating agent. Allow explicit reassignment to active staff.

Allow incomplete drafts. Require a response deadline before readiness confirmation.

Store timestamps in UTC and display them using the existing configured timezone, defaulting to Asia/Kuala_Lumpur.

## 6. Shipment requirements

Support one shipment per inquiry for this MVP, with multiple package groups or container rows where appropriate.

Make that scope clear when entering a request containing several unrelated shipments.

Capture:

- Cargo description
- Origin and destination countries
- Origin and destination ports or locations
- Pickup/delivery addresses where requested
- LCL, FCL, or Unknown while in draft
- Cargo-ready date
- Requested arrival date where known
- Timing flexibility
- Service scope: port-to-port, door-to-port, port-to-door, door-to-door, or Unknown
- Requested pickup, delivery, clearance, insurance, storage, and handling
- Special cargo/handling flags
- Client-stated Incoterm and named place, only when provided

Keep Incoterms separate from the requested logistics service scope.

For LCL, support package groups containing:

- Packaging type
- Quantity
- Gross weight
- Dimensions with explicit units

Also allow a known declared total volume with its source.

For FCL, support:

- Container type
- Container quantity
- Cargo gross weight

Keep these optional commercial values separate:

- Declared goods value and currency
- Customer budget and currency
- Existing freight reference quote amount and currency

None of these is authoritative vendor cost or company selling price.

Unknown measurements must remain null, not zero.

Validate positive measurements, quantities, explicit units, date relationships, and scope-dependent address requirements.

Use decimal-safe storage and calculations for measurement totals. Clearly distinguish calculated volume from client-declared volume and show the calculation basis.

Do not invent chargeable-weight formulas, container-capacity assumptions, shipment details, or delivery guarantees.

## 7. Review workflow and shipment revisions

Implement these Phase 2 statuses:

- Draft
- Needs review
- Needs client information
- Ready for sourcing
- On hold
- Closed

Define valid transitions centrally and enforce them server-side.

Display missing requirements and potential inconsistencies beside the relevant fields.

Before confirming Ready for sourcing, require:

- Active responsible owner
- Selected usable client contact
- Response deadline
- Cargo description
- Route endpoints
- Known service scope
- Cargo-ready date
- Relevant LCL/FCL requirements

For LCL, require package quantity, gross weight, and sufficiently documented dimensions or usable total volume with its source.

For FCL, require container type, quantity, and cargo gross weight.

Require pickup/delivery details only when the requested scope needs them. Do not require optional commercial fields for every inquiry.

Special cargo or unresolved special-handling requirements must enter a manual-review/hold path. Do not confirm them as ordinary general cargo.

Confirmation must record:

- Reviewer
- Timestamp
- Exact immutable shipment snapshot/version

Material changes to a confirmed shipment create a new working draft revision, preserve the earlier confirmed version, and remove current Ready for sourcing eligibility until reviewed again.

Changes to internal notes, response deadline, or assignment to another active owner should not unnecessarily create shipment revisions.

Prevent silent overwrites from concurrent editing through an optimistic revision check or equivalent.

On hold requires a reason and preserves context. Resuming re-evaluates completeness.

Closed requires a reason, such as withdrawn, duplicate, unsuitable, or no response. Reopening must be audited and re-evaluate readiness.

These statuses must not imply that an email, quotation, or booking exists.

## 8. Manual clarification workflow

Generate a deterministic clarification draft from missing fields and a company template. Do not call AI.

The draft must be editable and consolidate questions into one clear message.

The agent reviews and approves:

- Exact message
- Intended recipient
- Relevant shipment revision

Material changes invalidate the clarification approval.

Provide a working Copy action with an accessible fallback if clipboard access fails.

Do not send email.

Only record clarification as sent when staff explicitly confirms they communicated outside LRS, including recipient, channel, and timestamp. Clearly label it as manually recorded communication.

Allow staff to record the customer’s answer, update the working shipment, resolve gaps, and return the inquiry to review.

Preserve clarification and response evidence in the activity timeline.

## 9. Private supporting documents

Support:

- PDF
- JPEG/PNG
- DOCX
- XLSX
- CSV

Use actual server-side file type/MIME validation, configurable file-size/count limits, and safe filenames.

Allow staff to classify documents as:

- Packing list
- Commercial/pro forma invoice
- Client RFQ/specification
- Existing freight quotation
- Correspondence
- Other

Preserve originals and record:

- Uploader
- Upload timestamp
- Size and MIME type
- Checksum
- Inquiry association
- Classification

Store documents on a private Laravel disk outside public web paths.

Every preview/download must require authorization. Never expose filesystem paths or permanent public URLs.

Provide authenticated PDF/image previews where supported. Offer a clear download option for other types.

Identify duplicate files within the same inquiry by checksum. Do not expose documents from another client through duplicate detection.

Uploading a document must not silently modify confirmed shipment fields.

Show an honest “Extraction not performed” state. Do not fabricate extracted data, confidence scores, or malware-scan results.

Preserve evidence/history; use an audited archive approach if document removal is necessary.

## 10. Professional interface and UX

Extend the existing Phase 1 design system and icon family.

Deliver complete screens for:

- Client list
- Client detail and editing
- Inquiry list
- Inquiry creation/editing
- Inquiry review workspace

The inquiry list needs real server-side search and filters for:

- Status
- Owner
- Priority
- Client
- Overdue response deadline

The inquiry form should use clear sections and conditional LCL/FCL fields rather than presenting every possible field at once.

The workspace must prominently show:

- Inquiry reference
- Client/contact
- Status
- Owner
- Response deadline
- Shipment revision
- Clear next action

Use a readable two-column desktop layout with source/shipment information and supporting review/actions. Stack sensibly on mobile.

Use Overview, Shipment, Documents, and Activity sections or tabs where they help navigation.

Include:

- Persistent field labels
- Useful helper text
- Inline validation
- Preserved form values after errors
- Empty and no-results states
- Success/error feedback
- Accessible keyboard interactions
- Visible focus
- Status text alongside colors
- Unsaved-change protection
- Responsive tables and forms

Use actual attachment counts, deadlines, activity, and completeness information. Avoid invented metrics or disconnected controls.

Add Clients and Inquiries to navigation when functional. Update the overview with real inquiry queues and next actions.

Design quality is a Phase 2 acceptance requirement.

## 11. Audit, integrity, and verification

Reuse Phase 1’s audit system for:

- Client/contact changes
- Inquiry ownership, priority, deadline, and status changes
- Shipment confirmation and revisions
- Clarification records
- Document changes

Use transactions for dependent changes and audit entries. Do not log secrets or unnecessary document contents.

Validate all client/contact/inquiry relationships on the server.

Add meaningful PostgreSQL-backed tests for:

- Permissions
- Valid/invalid status transitions
- Unique reference allocation
- Conditional shipment completeness
- Confirmed version preservation and reconfirmation
- Stale-edit protection
- Private document access and file validation
- Clarification approval versus manually recorded sending

Use explicit optional demo seeds. Do not populate existing records with fake production data.

Run appropriate existing tests, formatting, and the frontend production build.

Inspect actual screens in a browser when tooling is available. Check desktop/mobile layouts, keyboard navigation, console errors, and the complete form/review flow. Fix discovered issues.

Report unavailable checks honestly.

## 12. Acceptance scenario

Verify this complete story using clearly labeled local test records:

1. Create a client and primary contact.
2. Create an incomplete LCL inquiry.
3. Assign its owner and deadline.
4. Upload a packing list.
5. Confirm that missing information is shown.
6. Prepare and approve a clarification.
7. Copy it and explicitly record manual communication.
8. Record the customer’s answer and complete the shipment.
9. Confirm Ready for sourcing with reviewer/version evidence.
10. Change a material shipment detail.
11. Verify a new draft revision requires reconfirmation.
12. Reconfirm and inspect client history and audit records.
13. Verify unauthorized private-document access is blocked.
14. Verify the FCL readiness requirements.

## 13. Documentation and final handoff

Update:

- README.md
- docs/PROJECT_BRIEF.md
- docs/DESIGN_SYSTEM.md
- docs/PHASES.md
- docs/HANDOFF.md

Document actual permissions, status transitions, readiness requirements, reference allocation, revision behavior, private storage, upload limits, and manual communication semantics.

Report:

1. Completed features and actual routes/screens.
2. Important implementation decisions.
3. Migrations and dependencies added.
4. Checks run and their results.
5. Remaining limitations.
6. A short manual acceptance checklist.

Finish Phase 2 and stop.

Phase 3 will add document extraction and AI-assisted review to this existing workspace.

For continuity, every subsequent phase handoff should include its recommended Codex model and reasoning level. Keep these coding settings separate from the runtime AI model used by LRS.