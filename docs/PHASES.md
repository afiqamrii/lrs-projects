# LRS phase tracker
Updated 5 October 2026. Build and verify functionality and polished UX together in every phase. The current authorization ends after Phase 5.

| Phase | Deliverable | Status |
|---|---|---|
| 1 | Foundation, staff access, vendor directory, visual system | Implemented; local verification recorded in HANDOFF |
| 2 | Clients and manual inquiries: shipment forms, ownership, deadlines, private attachments, review/clarification and shipment revisions | Implemented; verification and acceptance recorded in HANDOFF |
| 3A | Public customer inquiry page, shared staff intake, immutable submission evidence, contact assessment, private uploads and optional receipt/mailbox confirmation | Implemented; local verification recorded in HANDOFF |
| 3B | Digital text extraction, selective OCR, AI structured proposals, source evidence, review and usage accounting | Implemented locally; real OCR/fake-provider checks in HANDOFF, live AI unconfigured |
| 4 | Manual vendor selection, deterministic RFQ drafts/AI wording, exact approvals, private output and manual dispatch evidence | Implemented locally; verification and acceptance recorded in HANDOFF |
| 5 | Outlook mailbox setup, approved sends, inbound inquiries/replies, deduplication, matching, catch-up and recovery | Implemented locally; fixture verification recorded in HANDOFF; live Microsoft setup remains unverified |
| 6 | Structured vendor offers, extraction review, equivalent-scope comparison, clarification and selection | Not started |
| 7 | Client quotations: markup/fees, precise calculations, PDF rendering, revisions, approval and approved sending | Not started |
| 8 | Approved reminders/escalation, business-day schedules, cancellation, reply checks and operational exception queue | Not started |
| 9 | Gmail with the same workflow and verified provider-specific behavior | Not started |
| 10 | Client acceptance/revisions/expiry and explicit booking/job handoff | Not started |
| 11 | Reporting, production hardening, end-to-end pilot, backup/restore verification and deployment preparation | Not started |

## Phase 1 completion evidence
- Fresh PostgreSQL application migration completed on 17.11; separate lrs_test migrations exercised by feature tests.
- Secure prompted admin command exercised by tests and local browser setup. Standard seeder creates no accounts.
- Full login/reset, staff/vendor/contact/profile/settings screens and server-side authorization are implemented.
- PostgreSQL constraints, transactional audit changes, primary-contact transfer, inactive access and last-admin protection are covered.
- 28 PostgreSQL feature tests passed with 202 assertions in the final run; see HANDOFF for all recorded checks.
- Production assets, Blade compilation and PHP formatting checks were run.
- Actual desktop/mobile Chrome inspection included directory, forms, details, navigation and clipping; a measured mobile table overflow was corrected.
- The accepted screen structure was refined with an Apple-inspired light visual system. A real-data directory health graphic links to matching server filters; inactive/contact category semantics are tested.
- Manual company acceptance remains a human checklist in README; no production deployment or pilot signoff is claimed.

## Phase 2 completion evidence
- Additive application migration preserved Phase 1 records; the isolated PostgreSQL tests exercise constraints, sequence allocation and immutable version protection.
- Complete client/contact and manual inquiry screens, real filters/queues, conditional LCL/FCL forms, private original uploads and audited workflows are implemented.
- Exact-message approval and explicitly recorded manual communication remain separate. Uploads never populate shipment fields.
- Server tests cover shared roles, nested relationships, missing/conditional data, status reasons/transitions, stale edits, metadata versus material revisions, reconfirmation, private downloads, actual file types/limits/deduplication and audit rollback.
- Final PostgreSQL suite: 48 tests / 469 assertions passed. Production build, Pint, Blade and Composer checks passed.
- Complete Chrome LCL/revision and FCL acceptance flows, private PDF/guest denial, clipboard fallback and measured 320px/390px layouts passed. Human pilot acceptance remains separate.

## Phase 3A completion evidence
- Additive migration `2026_10_04_073826_add_public_intake_foundation.php` ran as batch 3; existing business records and Phase 1/2 migrations were preserved.
- Anonymous four-step /request-quote and the server-only row-add/submission path, receipt, explicit mailbox-confirmation page, copy link, Admin settings, staff Website/Unassigned filters, original-evidence and client-assessment screens are implemented.
- Public cases enter Needs review with unresolved client/contact and optional default owner; manual requirements and Phase 2 confirmation gates remain enforced. No automatic existing-email association or client-directory mutation.
- PostgreSQL tests cover original immutability, competing same-key submissions, case/file/mail deduplication, unknown/conditional values, private type/count/size limits, CSRF/persistent throttling, rollback/file compensation, staff assessment/stale edits, hashed expiring one-use confirmation and disabled/log/failing transport.
- Final combined PostgreSQL suite: **71 tests / 768 assertions passed**. Pint, production Vite build, Blade compilation and Composer validation passed. Browser evidence and incomplete manual coverage are recorded in [HANDOFF](HANDOFF.md). No real customer message or production deployment was used.
- Public receipt mail remains disabled locally. Source files remain unscanned. Company branding/contact/privacy and controlled transport approval remain launch decisions, not unimplemented Phase 3A workflow.
- The 11-step company acceptance checklist and local/queue instructions are in [HANDOFF](HANDOFF.md) and [README](../README.md).

## Phase 3B completion evidence

- Additive PostgreSQL schema preserves prior cases, directory, website sources and confirmed versions.
- Real Poppler/Tesseract/Office/CSV extraction retains private lineage/evidence, partial checkpoints and honest limits/unavailable states.
- One bounded Responses adapter uses current text.format strict schema, no tools and selected private text. Live model/key/rates remain unset; no real client/provider call.
- Staff review uses existing validation/version rules: idempotent apply, blocked stale overwrite, confirmed corrections require reconfirmation.
- Competing PHP processes exercised case-scoped identity and daily budget reservations; uncertain cost requires evidence-backed Admin reconciliation.
- 104/104 PostgreSQL tests (1,032 assertions), followed by 33 affected source/review tests (382 assertions) and the 10-assertion stopped-worker cleanup check; final Pint, Composer validation, Blade compile and Vite production build passed. Desktop/390px/320px browser evidence navigation/review/privacy checks passed.
- Synthetic Chrome acceptance includes source navigation, conflict validation, draft apply, human confirmation and a corrected next Draft preserving version 1. Human pilot signoff remains separate.
- Maintained documentation and the full Phase 3B brief record installation, scope, versions, queues/recovery, policies, rates/budgets, fixture outcomes and acceptance.

## Phase 4 completion evidence

- Additive migration `2026_10_04_110741_create_sourcing_foundation.php` applied as batch 5; prior records and confirmed versions preserved. No dependency added.
- Agent-selected active directory vendors share one immutable shipment round while each retains separate references, recipients, revisions and review state. Creation and approval are idempotent and concurrency guarded.
- Deterministic LCL/FCL facts/checklist, editable wording, explicit empty/selected manifest, prepared-copy lineage, disclosure notes, company reply/signature gaps and deadline/capability/shared-email recovery are implemented.
- Frozen approved snapshot/digest and transactionally committed audit evidence; central server preflight guards approval, copy/download and manual recording. No RFQ email/provider-send job or mailbox connector.
- Separate RFQ-wording purpose/schema reuses the existing provider/caps/accounting; human apply creates a new draft; stale/failed/disabled output preserves manual preparation. Unchanged successful wording can be rebound without extra paid work.
- Full final PostgreSQL regression: **132/132 tests, 1,457 assertions** (including separate-process RFQ creation/approval/edit and existing concurrent AI budget checks). Pint, Composer validation, Blade compilation and Vite production build passed.
- Desktop and 390px/320px browser inspection recorded in [HANDOFF](HANDOFF.md); QA-browser file saving was canceled for both approved and synthetic files, so normal-browser saving remains a manual check. Synthetic examples exercise three separate LCL vendors and historical manual evidence; no real client/vendor message or live AI call.
- Company pilot acceptance remains a human checklist; no production deployment or delivery claim.

## Phase 5 completion evidence

- Additive batches 6–7 preserve prior data, original sources, confirmed shipment versions and RFQ approvals; batch 7 only labels older reserved-test-address inquiries. Pinned delegated OAuth/PKCE, encrypted tokens/refresh leases, Admin mailbox settings and explicit private selected-folder intake are implemented.
- Frozen content plus actual sender-envelope authorization precedes every explicit outbox action. Separate vendor drafts, exact recipient/attachment verification, central current-release checks, accepted/observed/uncertain states and bounded recovery are implemented.
- Incoming email uses durable encrypted pages/cursors and mailbox/immutable-ID deduplication. New customer sources enter Needs review; exact old/ambiguous/unfamiliar/automated replies remain auditable without price parsing or automatic identity/shipment changes.
- Real inquiry/incoming queues and overview inquiry counts are the default. Website and Outlook sources remain distinct; labelled examples are separately filterable. User selected new website submissions and connected Outlook for genuine data. Fixture-to-real release and association are blocked; seven retained local acceptance inquiries remain explicitly synthetic. The temporary fixture connection was disconnected and its flag disabled.
- Final complete PostgreSQL regression: **175/175 tests, 1,831 assertions**. Pint, Composer strict validation, Blade compilation, additive migration/status and production build passed. Desktop/390px/320px fixture browser flows, exact separate dispatches, incoming source review, audited matching and real default empty states were verified. Full and affected results, browser evidence, queue/scheduler/run instructions and the twelve-step manual checklist are in [HANDOFF](HANDOFF.md). No live Microsoft call, real imported email, real client/vendor send, deployment or pilot signoff is claimed.

## Next phase — only when authorized

Phase 6: reviewed vendor offers, comparable-cost calculations and agent selection. Consume exact MailMessage → RFQ revision → sourcing round/confirmed shipment lineage and private evidence. A received-quotation classification does not approve commercial prices. Later phases remain unstarted.

Recommended Codex coding setting: **GPT-6.1 Sol / Extra High**, when available. Runtime AI configuration remains a separate decision.
