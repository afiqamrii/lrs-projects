# LRS project brief
Baseline: 4 October 2026. Source: the attached [build baseline](../LRS_Phase_1_Codex_Prompt.md), read in full and preserved. This document carries its rules into subsequent implementation; company samples, terms, routes, billing responsibilities and mailbox permissions still need validation before a pilot.

## Product and participants
LRS is one company-operated logistics inquiry and quotation workspace, not a multi-tenant SaaS. Participants are clients, the company's agents and logistics vendors. Only staff have accounts initially. The company issues its own client quotation using markup on reviewed vendor costs.

Initial shipment scope is general cargo sea freight, LCL and FCL. Pickup, delivery, clearance and insurance are explicit requested services. Special cargo requires manual specialist review. Other logistics modes come later.

## Authorized implementation boundary
Phases 1–5 are implemented: Laravel/PHP, Blade, Tailwind/Vite and PostgreSQL; staff authentication/authorization, vendor/client/contact directories, company preferences, real overview queues and sanitized activity history; manual inquiries, explicit LCL/FCL shipment requirements, private original documents, deterministic manual clarifications, customer response records and immutable confirmed shipment versions.

The approved Phase 2 and Phase 3A briefs are preserved in [PHASE_2_BRIEF.md](PHASE_2_BRIEF.md) and [PHASE_3A_BRIEF.md](PHASE_3A_BRIEF.md). Admin and Agent share all client/inquiry operations. Owner identifies responsibility rather than exclusive access. Only Admin manages staff/settings. Clients/vendors have no accounts.

Draft information can remain unknown. General-cargo readiness requires active staff/client/selected contact, deadline, route/cargo/ready date, known mode/scope and conditional measurements/addresses. Specialist cargo remains in manual review/hold. Shipment confirmation records exact immutable snapshot/reviewer/time. Material changes require fresh review; non-shipment notes/deadline/owner changes preserve the version. Original requests and subsequent communications remain separate evidence.

Clarification text is deterministic and editable, with exact recipient/revision approval and clipboard fallback. Approval and copying do not count as sending; explicit approved Outlook dispatch and separate outside-LRS communication records preserve their own evidence. Uploads remain private originals, never silently extracted into confirmed fields.

Phase 3A adds anonymous website intake to this same inquiry workspace and a narrowly scoped, optional approved receipt/mailbox-confirmation email. Phase 3B adds local extraction/selective OCR, structured proposals, private evidence review and usage controls. Phase 4 adds vendor selection, professional RFQ preparation, exact approval and explicit manual-send evidence. Phase 5 adds a pinned delegated Microsoft Graph mailbox, exact sender-envelope authorization, explicit approved sending, incoming email cases/replies, audited matching and bounded recovery. Live Microsoft configuration/Exchange behavior remain unverified locally. Offer comparison, client quote/PDF, markup, automatic reminder and booking are unimplemented. The current authorization ends after Phase 5. Future workflow below describes the roadmap, not current capabilities.

## Phase 4 sourcing and approval rules

The complete authorized [Phase 4 request](PHASE_4_BRIEF.md) is preserved. Staff choose active vendors from the existing private directory. One round belongs to one confirmed shipment version; each vendor has a stable request and preserved revisions. All receive the same deterministic cargo/service baseline; labeled alternatives and vendor-specific wording cannot secretly override shipment facts.

Use one active primary To with explicitly chosen active same-vendor CC. Normalize/validate addresses and reject header injection/duplicate To–CC/cross-vendor recipients. Recorded capabilities/coverage/minimums remain facts or unknown. Mode limitations, shared emails and conflicting response deadlines require explicit staff decisions; inactive vendors/contacts, missing company reply details and missing readiness are hard gates.

No files are selected by default. Selected same-inquiry files freeze identity/version/checksum/name/MIME/size/classification and actual scan status. Reference freight quotes cannot be selected. Explicit disclosure governs client identity/required logistics addresses and sensitive files. Prepared/redacted uploads are separate private files linked to originals, never automatic redaction or automatically attached. File changes/unavailability block release until reviewed.

Admin/Agent can prepare and approve; the actual human is recorded without an invented two-person rule. Draft, Needs approval, Changes requested, Approved, Superseded and Cancelled are review states, separate from manual dispatch. Approval freezes the rendered subject/body/signature, intended configured reply contact, recipients and manifest plus digest/approver/time. Optimistic checks and transactional locks reject stale edits/approvals. Approval audit commits together.

Central server preflight governs approval, approved output/manifest download and manual-send recording. Material changes require inquiry reconfirmation/new sourcing round; changed recipient/file/deadline/wording requires a new draft and approval. Holds/closure, inactive responsibility/contact/vendor and expired/invalid deadlines block release. Nonmaterial internal notes retain confirmation. Old approvals and manual sends survive.

Optional AI suggests wording only through the existing configured provider, separate strict schema and shared conservative monetary caps. No source attachments/inbox history/client budget/competing vendors are sent. Staff apply reviewed suggestions into a new draft; deterministic facts/checklist cannot be replaced. Delayed results cannot overwrite newer/approved requests; unchanged successful context is reused without another paid call. Manual template preparation remains available without AI.

Copy/download never counts as sending. A deliberate manual record declares exact approved recipients/files/version, actual time/channel and optional evidence; duplicate records are prevented and labeled “Manually recorded as sent.” There is no provider delivery/read claim. Phase 5 explicitly binds and authorizes the actual sender/reply-to envelope, rechecks current eligibility and excludes already manually dispatched approvals. A resend requires a deliberate newly approved revision.

## Phase 3B extraction and proposal rules

The authorized [Phase 3B request](PHASE_3B_BRIEF.md) is preserved completely. Bounded PHP jobs orchestrate Poppler/Tesseract and DOCX/XLSX/CSV readers. Runs retain checksums/configuration/tool versions, selected pages, stable page/block/row/cell IDs, quality warnings and explicit omissions. Private originals remain unscanned. Processing/review outcomes are separate from inquiry business status.

One explicitly authorized Responses request receives selected evidence text and returns strict permitted shipment candidates plus an unreviewed summary. Runtime model/key/support/access/current rates/caps are explicit; live AI is disabled/unconfigured locally. Fixtures are labelled synthetic. Evidence IDs/locators/quotes/literals/numbers/units are checked locally; ambiguous roles, duplicate groups and contradictions require interpretation. Unknown values/cost remain unknown, and no self-reported confidence is treated as measured accuracy.

Staff compare current/proposed values, accept/correct/reject/leave unresolved, preview all effects and apply through existing validation/policies/version actions. Source/revision changes prevent stale overwrites. Replacements/conflicts/manual corrections require acknowledgement/explanation. Missing evidence never becomes source-verified data. Material confirmed changes create the next Draft preserving prior confirmation. Review never confirms identity, changes the directory, authorizes prices or sends messages.

Case-scoped reuse, persistent after-commit jobs, database identities/claims and conservative budget reservations prevent duplicate competing work/cap bypass. Ambiguous paid outcomes retain holds until evidence-based Admin reconciliation; retry is explicit and billing is not promised exactly once. Manual extraction/review remains available when AI is unavailable. [HANDOFF](HANDOFF.md) records actual setup, bounds, schema/prompt versions, states, source mapping, budgets, measured checks and limits.

## Phase 3A public-intake rules
Customers use `/request-quote` without an account. The four steps are Contact, Shipment, Documents and Review; an ordinary server POST and server row-add path remain usable without application scripts. Required contact name/email, route endpoints and cargo identify a useful request. Technical unknowns remain null/explicit unknown. LCL row weight is the total for that group; dimensions describe one package. Alternative LCL/FCL rows survive mode changes. Additional services, specialist flags, goods value, budget and freight reference price remain separate.

A successful submission creates one existing Inquiry with source `website` (“Website form”) and status `needs_review`. Public intake cannot approve a shipment, set staff workflow fields or create approved directory records. The original contact/company, shipment, notes, document references and exact privacy notice/version/acknowledgment time are preserved in a PostgreSQL-protected immutable source snapshot. Submitted email casing is retained in that snapshot; the working address is normalized for matching. System/public actors identify anonymous creation/uploads.

Public cases may have null client/contact/owner and an unresolved deadline. Manual staff-created inquiries still require the Phase 2 client/owner inputs. An active configured default intake owner is used; otherwise the queue says Unassigned. No company SLA is invented. Staff can defer the association and correct working data, or explicitly select/create an active client/contact after assessment. Existing-email matches and possible cross-channel repeats are private suggestions, never automatic attachment, directory mutation or case merging. Original evidence survives all staff corrections.

Website cases continue through existing owner/deadline assignment, clarification drafts, manually recorded answers, working revisions and human confirmation. Ready gates still require active owner/client/contact, deadline and complete general-cargo mode/scope requirements; specialist cargo requires human review. Email syntax, access to the submitted mailbox and staff assessment of identity are different facts. Mailbox confirmation never creates a customer account or grants readiness.

Receipts are limited to the submitting browser session; a reference is not a public lookup credential. Confirmation tokens are random, scoped to inquiry/address, stored only as hashes and initially expire after 24 hours. The email link uses a URL fragment and opens a minimal GET page; an explicit CSRF-protected POST confirms access atomically. Resend invalidates prior tokens and requires the original receipt session or authorized staff. Changing the working email invalidates that contact check.

The only new automated message is a fixed company-approved receipt with reference and confirmation action. It contains no shipment, attachments, quote or internal data. It is disabled by default, needs an explicitly selected direct transactional transport and runs on the database queue after commit. Log/array/fallback modes cannot claim external sending. Transport acceptance is separate from delivery/access. Failure retains the case; jobs do not blindly repeat an uncertain external send.

Public files are private staff-only evidence: PDF, JPEG/PNG, DOCX, XLSX and UTF-8 CSV under actual-content checks. Defaults are 5 files, 10 MiB each and 30 MiB combined, clamped to stricter existing/PHP limits. Generated paths, sanitized display names, checksums, suggested classification and provenance are retained. Files are visibly unscanned; format checks are not malware scanning. Executables, SVG/HTML, ordinary archives and macro/active-content Office structures are rejected.

Database/session-bound form keys prevent duplicate cases/files/mail on retries, including competing requests. Distinct intended inquiries may share an email. CSRF, database-backed rate limits, honeypot, bounded arrays/strings and default untrusted forwarded headers apply. Transactions compensate only new files on failure. Public responses are private/no-store/no-referrer/noindex; no source payload or confirmation secret is included in audit metadata.

Admin alone configures intake availability, public introduction/contact/privacy wording/version, active default owner and approved receipt wording/enablement. Branding and privacy language need company review before a public launch. A paused form gives a contact/unavailable state; staff/manual intake continues. See [HANDOFF](HANDOFF.md) for routes, migration, configuration, queue/run instructions, tests and the 11-step manual checklist.

## Full future workflow
1. Receive client email, a form or a manually entered inquiry.
2. Preserve source messages/attachments, create a case reference, assign an owner and deadline.
3. Extract proposed shipment fields and document type; identify missing/conflicting information.
4. An agent confirms the shipment version or approves a clarification email.
5. The agent manually selects directory vendors. AI may draft RFQs, but the agent approves recipients, content, scope and attachments.
6. Send a separate email to each vendor and track each RFQ independently from the client case.
7. Ingest replies and extract proposed costs/conditions for human review.
8. Compare equivalent shipment versions, scope, currency, units, minimum charges, inclusions/exclusions, validity and transit estimates. Unknown cost never becomes zero.
9. An agent selects an offer and sets markup or service fees. Application code calculates with decimal-safe arithmetic and distinguishes markup from gross margin.
10. Generate the client quote PDF from approved fields and a company template. The agent approves the exact revision and email.
11. Send the approved quote, track acceptance/revision/decline/expiry and retain evidence.
12. Client acceptance requires rate/capacity reconfirmation and explicit booking authorization before job handoff.

## Permanent business rules
- Agents maintain and manually select vendors. No automatic vendor selection in the MVP.
- Client source material may be absent, a packing list, commercial invoice, RFQ or an existing freight quote. Goods value, budget, reference quote, vendor cost and selling price remain distinct.
- Extract structured fields before summarizing; preserve originals, table relationships and page/source references. Never invent missing information.
- Use usable digital PDF text first; OCR only pages that need it. Selective vision/manual review is a fallback.
- Reuse unchanged extraction, account for AI/OCR usage and treat documents as untrusted data.
- AI proposes fields and text. It cannot authorize sending, establish authoritative costs or book shipments.
- Material changes invalidate affected approvals. Retain shipment and quotation revisions.
- Routine reminders may eventually use approved wording and schedules; new wording, negotiation or changed terms needs approval. Stop reminders on relevant responses or closure.
- Outlook is the first connector; Gmail follows. Connectors must remain replaceable without promising universal provider coverage.
- Deduplicate messages/jobs and provide mailbox catch-up. Reconcile uncertain send outcomes before retries.
- Client documents must exclude vendor costs, markup, competing vendors and internal notes.
- Store timestamps in UTC; display Asia/Kuala_Lumpur initially. Business-day reminder settings come later.

## Phase 1 access rules
Admins manage staff, directory and company settings. Agents view/create/edit vendors and contacts, activate/deactivate vendors and update their own name/password. Both share the company directory. Public registration is absent.

Inactive staff cannot sign in or continue using an existing session. The last active admin cannot be deactivated or demoted. Secure admin setup prompts for a password; no production default credentials. Staff creation uses an unpredictable initial password and an emailed, time-limited password setup link. Development mail is captured privately.

## Phase 1 vendor rules
Each vendor records company/display name, one of the five agreed types, explicit service tags, coverage/routes text, minimum notes, preferred communication, internal notes and active status. Related contacts record name, optional role/phone, normalized email and primary quotation flag.

PostgreSQL enforces normalized email uniqueness per vendor and at most one primary quotation contact. Multiple contacts are supported through dedicated server-rendered forms. Selecting a new primary contact atomically clears the previous one and records both changes. A record without a primary contact can be saved, but is visibly incomplete for future outreach. Email syntax does not prove ownership or deliverability.

Potential duplicate company names are warned about after save without blocking legitimate branches. Vendors are deactivated instead of deleted; history remains available. Inactive vendors will be excluded from future outreach selection.

## Decisions made for this foundation
- Laravel 13.34 / PHP 8.5, Tailwind 4 and PostgreSQL 17, with provided dependency lockfiles.
- Native Laravel session guard and password broker; no separate auth/frontend framework or Fortify dependency is needed for this slice.
- Database sessions/cache/queue; Phase 1 password notifications are synchronous.
- A singleton company settings row also serializes staff changes that could remove the last admin.
- Staff email is the fixed account identity in Phase 1. Profile updates cover name/password. Admins manage role/status.
- Services are a small explicit array; routes are well-labeled multiline text. No speculative shipment or offer schema.
- Newly created vendors are active; status changes have a dedicated confirmation screen.
- Audit snapshots use a field whitelist. Passwords, hashes, remember/reset tokens and secrets are excluded. Activity is append-only through the application, but is not tamper-proof against database administrators.
- Preferred currency is a display preference, initially MYR; no automatic conversion or quotation arithmetic is implied.
- Demo fixtures are explicit and clearly fictional. There are no invented business metrics.
- Current local setup uses a portable, loopback-only PostgreSQL instance and a separate test database because Docker's engine was unavailable.

## Visual direction confirmed during Phase 1
The user accepted the screen structure and requested an Apple-style visual refinement, allowing color and useful professional graphics. The current system uses silver navigation, graphite text, white rounded surfaces, blue actions and restrained semantic colors. The overview health graphic uses only persisted directory counts. This overrides the initial navy/teal visual concept; it does not expand Phase 1 business scope. See DESIGN_SYSTEM.md.

## Company input needed before pilot
Confirm legal/display name, official branding, staff roster and real directory examples; actual service/routes/cargo restrictions and specialist responsibility; vendor contact ownership/deliverability; quote terms, exclusions, billing responsibility and approval thresholds; quote templates and markup/fee policy; approved mailbox permissions, reminder wording/schedules and booking evidence requirements.

These decisions are not silently filled in by Phases 1–5. Public branding/contact/privacy and receipt wording also need company approval before launch; no legal approval or external delivery has been assumed.

## Phase 5 real intake and approved Outlook release

The full authorized [Phase 5 brief](PHASE_5_BRIEF.md) is preserved. The user selected new website submissions and connected Outlook for actual records. Preserve existing labelled examples; real queues are the default. Do not invent real clients/vendors/requests, delete old work or automatically merge same-email sources.

A company-pinned delegated Graph connection is Admin-only. Authorization-code OAuth/PKCE, one-use session-bound state, expected tenant/account/target checks, encrypted tokens and serialized refresh protect identity. Shared access requires actual Exchange Full Access plus Send As/on-behalf rights and verified Sent Items behavior; recorded administrator checks and controlled live verification are separate from OAuth scopes.

Human content approval, actual sender-envelope authorization and explicit enqueue are distinct. Immutable outbox/evidence, exact separate vendor recipients/manifests, the existing central current-release preflight and provider draft verification guard release. Provider accepted/Sent Item observed are evidence states, not delivery/reading. Unknown outcomes permit reconciliation only. Connecting sends no backlog; manually sent approvals are excluded.

Only configured incoming folders create Needs review cases/replies. Durable per-folder delta pages/cursors, immutable mailbox/message deduplication, private validated files, source/classification/match audit, bounded catch-up and operational recovery preserve history. Known threads also require expected sender context; unknown/ambiguous/automated evidence needs assessment. Clarification replies cannot silently change shipment versions. No quotation prices are parsed or approved in this phase.

Implementation is locally fixture-verified; Microsoft configuration/live Exchange checks remain unavailable. Phase 6 begins reviewed offers/comparison only after separate authorization. See current [HANDOFF](HANDOFF.md) for exact interfaces, limits, configuration, run instructions and acceptance.
