# LRS phase tracker
Updated 5 October 2026. Build and verify functionality and polished UX together in every phase. The current authorization ends after Phase 11; planned local build phases are complete. Company/live production acceptance remains outstanding.

| Phase | Deliverable | Status |
|---|---|---|
| 1 | Foundation, staff access, vendor directory, visual system | Implemented; local verification recorded in HANDOFF |
| 2 | Clients and manual inquiries: shipment forms, ownership, deadlines, private attachments, review/clarification and shipment revisions | Implemented; verification and acceptance recorded in HANDOFF |
| 3A | Public customer inquiry page, shared staff intake, immutable submission evidence, contact assessment, private uploads and optional receipt/mailbox confirmation | Implemented; local verification recorded in HANDOFF |
| 3B | Digital text extraction, selective OCR, AI structured proposals, source evidence, review and usage accounting | Implemented locally; real OCR/fake-provider checks in HANDOFF, live AI unconfigured |
| 4 | Manual vendor selection, deterministic RFQ drafts/AI wording, exact approvals, private output and manual dispatch evidence | Implemented locally; verification and acceptance recorded in HANDOFF |
| 5 | Outlook mailbox setup, approved sends, inbound inquiries/replies, deduplication, matching, catch-up and recovery | Implemented locally; fixture verification recorded in HANDOFF; live Microsoft setup remains unverified |
| 6 | Structured vendor offers, extraction review, equivalent-scope comparison, clarification and selection | Implemented locally; reviewed costs/selection, fictional business preview, test/browser evidence and acceptance in HANDOFF |
| 7 | Client quotations: explicit percentage markup, precise calculations, private PDF/email, revisions, exact approval and approved sending | Implemented locally; pricing, immutable PDF/approval, fixture outbox and browser evidence recorded in HANDOFF; live Microsoft unverified |
| 8 | Approved reminders/escalation, business-day schedules, cancellation, reply checks and operational exception queue | Implemented locally; fixture/browser/test evidence and acceptance in phase-8-handoff.md; live Microsoft unverified |
| 9 | Gmail with the same workflow and verified provider-specific behavior | Implemented locally; shared/provider fixture tests, browser evidence, setup and acceptance in phase-9-handoff.md; live Google unconfigured |
| 10 | Client decisions/revisions/expiry, vendor rate/capacity reconfirmation and controlled handoff/actual booking evidence | Implemented locally; verification, manual acceptance and local commands in phase-10-handoff.md; live operations remain unverified |
| 11 | Reporting, production hardening, end-to-end pilot, backup/restore verification and deployment preparation | Implemented locally; defined reports/CSV, observed health/emergency pause, complete regression, isolated encrypted restore and runbook in phase-11-handoff.md; company/live/production verification remains pending |

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

## Phase 6 completion evidence

- Additive batches 8–9 preserve prior evidence and enforce immutable source/commercial/comparison/selection contracts. BigDecimal and NUMERIC calculations use existing dependencies.
- Exact vendor quotation capture, separate alternatives, idempotent import, reviewed charge/quantity/minimum/tax/FX handling, gap resolution, equivalent-cost cards and reasoned final/provisional selection are implemented together with responsive screens.
- Quotation-only extraction/proposals reuse local evidence and shared budget controls. Manual review works with live AI off; provider contracts are fake-response tested.
- Phase 7 receives schema lrs-cost-basis-1 only through current OfferEligibility::pricingBasis. Expiry, revised source/offer, shipment/RFQ/comparison changes, unavailable evidence and inactive vendor/contact block pricing without rewriting history.
- Per latest user instruction, professional .example companies/contacts, website/email inquiry samples and practical vendor quotes replace legacy QA records in the default preview. Originals remain immutable; genuine website and future connected-Outlook records remain separate.
- Verification: **202 PostgreSQL tests / 2,003 assertions passed**, including simultaneous PHP offer saves/selections, exact decimal/FX rules, source replacement and tampering, stale forms and explicit AI review. Production build, formatting, Blade and Composer checks are recorded in HANDOFF.
- Actual browser acceptance covers capture/evidence/proposal correction/review, A/B/C ranking, reasoned selection/frozen history, later edit invalidation/renewed review, and desktop/390px/320px layouts. Live Outlook/AI and production pilot remain unverified.
- The current [manual checklist and local run instructions](HANDOFF.md#phase-6-manual-acceptance-checklist) are ready for company acceptance.


## Phase 7 completion evidence

- Additive migrations in batches 10–11 retain existing commercial/source data and enforce immutable quotation revisions, approvals, manual declarations and outbox source lineage.
- One explicit percentage markup, reviewed FX/tax separation, currency precision, cumulative half-up selling-line allocation, separate optional prices and estimated profit versus margin are implemented through exact decimals.
- Dedicated local Dompdf 3.1.6 rendering creates private one-file customer PDFs with whitelist projection and immutable SHA-256 approval. Every saved edit produces a new revision; no regeneration occurs at release.
- Exact approval covers pricing, source, PDF, email, active client recipients, actual sender/Reply-To and human reviewer/time. Shared Phase 5 outbox provides separate explicit enqueue, fresh worker checks, idempotency, uncertain reconciliation and linked deliberate resends.
- Complete PostgreSQL regression passed: **223 tests / 2,142 assertions**. After the final stale-form recovery fix, affected quotation/pricing tests passed again: **20 tests / 134 assertions**. Browser fixture sending, stale-rate release blocking, 390px/320px layouts and short/multi-page PDF visual inspection are recorded in [HANDOFF](HANDOFF.md), with the manual checklist and local run instructions. Live Microsoft/AI, production deployment and company pilot remain unverified.
- Stop after Phase 7. No Phase 8 reminders, Gmail, acceptance automation, booking, payment or invoice work was added.

## Phase 8 completion evidence

- Additive batch 12 preserves existing source/commercial/quotation evidence and adds versioned disabled policies, exact immutable activations/stages, cumulative counts and owned internal attention tasks.
- Automatic and review-each-message modes share UTC/company-timezone business calendars, explicit working hours/weekdays/holidays, expiry and fresh incoming-response checks. Human reviewed exact client responses stop reminders without any booking transition.
- PostgreSQL uniqueness/short claims, after-commit jobs and final submission checks reuse the Phase 5 outbox. Duplicate/concurrent claims, late responses, cancellation, conservative uncertain counts, policy cap preservation and actual-acceptance scheduling are covered.
- Final complete PostgreSQL regression: **266 tests / 2,357 assertions passed**, then **3 final affected tests / 37 assertions passed**. Formatting, production assets, Blade syntax and Composer checks passed. Fixture browser sending → question import → human review → stopped plan, desktop/390px/320px layouts and keyboard/error states were verified. Full evidence, limitations, manual checklist, local commands, recovery and Phase 9/10 contracts are in [Phase 8 handoff](phase-8-handoff.md).
- No Gmail, booking, payment, campaign or automatic negotiation work was added. Local acceptance uses realistic fictional records; previous immutable history remains.

## Final planned phase

Phase 11 is now implemented and locally verified using the immutable outcomes/timestamps and release-versus-booking distinctions from Phase 10. See [Phase 11 handoff](phase-11-handoff.md) for current evidence, metrics, manual pilot checklist and remaining live/production checks. Stop after Phase 11; further work requires a defect or explicit enhancement request.


## Phase 9 completion evidence

- Additive mailbox provenance/routing, opaque Gmail IDs, exact MIME/draft/SENT evidence, encrypted OAuth/refresh and bounded Gmail history sync preserve Outlook and existing business records.
- Admin connection/default/incoming/alias controls and shared staff review/outbox/reminder screens use the existing visual system and permissions.
- Shared business approvals, private attachment integrity, pinned threads, conservative cross-source duplicates and human reminder stop/hold rules apply to both providers.
- Complete PostgreSQL regression passed **292 tests / 2,558 assertions**, followed by **28 final affected tests / 208 assertions**. Pint, Composer, 169 compiled Blade views, production assets and browser fixture flows passed; desktop/390px/320px, keyboard, error/success and stopped-plan states were inspected. Fixture connections/policies are disabled, and all histories remain.
- Complete test/browser results, local commands, exact scopes, operational recovery, manual acceptance and remaining live checks are recorded in [phase-9-handoff.md](phase-9-handoff.md). No live Google configuration or provider send was claimed in the Phase 9 record. Phase 10 is recorded below.


## Phase 10 completion evidence

- Additive lifecycle/lineage migration batches 17–18 preserve earlier sources, shipment/offer/quote versions, mailbox provenance, approvals and history. Immutable append-only decisions, confirmations, handoffs, exact operational messages and booking events use PostgreSQL lineage/unique/version guards.
- Complete PostgreSQL regression passed **310 tests / 2,712 assertions**, then **29 final affected tests / 246 assertions**. Pint, Composer, 180 compiled Blade views and production build passed. Actual fixture acceptance/reconfirmation/handoff/booking and revised-rate/renewed-decision browser paths, desktop/390px/320px, keyboard, empty/error/success and private PDF pages were inspected. Fixture connections and polling are disabled, with all evidence preserved. Details and remaining live/manual checks are in [Phase 10 handoff](phase-10-handoff.md#verification-record).
- Formal acceptance, vendor reconfirmation, private handoff approval, actual operations handoff and vendor booking evidence are separate human actions. Realistic .example data remains fictional; no live email or booking occurred.
- Current manual acceptance and Windows run instructions are in [Phase 10 handoff](phase-10-handoff.md). This historical Phase 10 record precedes the completed local Phase 11 package below.


## Phase 11 completion evidence

- Additive batch 19 preserves existing records/batches and adds audited emergency outgoing controls, observed heartbeats, per-work authorization generations and targeted report/reply/AI indexes. No dependencies were added/upgraded.
- Staff reports define received-date/current-state/outcome denominators, meaningful RFQ response times, approved exact native-currency economics and recorded AI usage/unknowns. Drilldowns, 25-row pagination, five-per-minute and 5,000-row formula-safe private CSV are implemented.
- Measured guarded-test volume: 12,000 fictional inquiries, 3,000-case report cohort, 1,600 incoming messages and 40 RFQ revisions. Final page measurements were 126.12 ms / 14 SQL queries for reports and 1,186.74 ms / 15 queries for inbox. These are local measurements, not an SLA.
- Admin health shows observed queue/scheduler/worker/mailbox/uncertainty/follow-up/extraction/AI evidence. Both providers and reminders honor emergency pause at the final boundary; resume cannot release old backlog, and ambiguous provider evidence remains reconciliation-only.
- Actual AES-256-GCM/DPAPI-protected local restore into an isolated PostgreSQL/private namespace passed 52 private file hashes, 34 linked artifacts, 65 encrypted records, 19 business/audit counts and exact outbox-state preservation. Complete measured drill 11.520 seconds; source remained intact. Off-device production restore/retention/RPO/RTO remain pending.
- Complete regression passed 324 tests / 2,862 assertions, followed by 27 affected tests / 259 assertions. Final export checks passed 5 tests / 64 assertions; browser evidence is in [Phase 11 handoff](phase-11-handoff.md). Pint, Composer validation/platform/audit, npm audit, Blade compilation and Vite build passed; audits reported no vulnerabilities.
- The [operations runbook](OPERATIONS_RUNBOOK.md) covers local Windows commands, correct queues, supervised worker/scheduler preparation, secrets/private storage, backup policy decisions, recovery, rollout and compatible rollback. No hosting target or live provider/company pilot was supplied. The system is not production-ready while those material checks remain.
- Stop after Phase 11. No speculative new phases, payments, invoices, automatic acceptance or vendor API bookings were added.


## Final quality review and publication

Authorized maintenance after Phase 11 corrected incomplete website-source rendering, inactive-contact readiness, provider status, audit navigation and CSV NUL handling. Shared inquiry tabs, reachable desktop account controls, calmer silver/blue surfaces, compact phone summaries and labelled scrolling tables/history complete the existing Apple-inspired direction. No additional phase or dependency change was introduced.

Complete PostgreSQL regression passed **329 tests / 2,931 assertions**; final AI/extraction template checks passed **30 tests / 247 assertions**. Formatting, Blade compilation and production assets passed. Actual desktop/390px/320px rendering, native form/charge/menu behavior and remaining manual/live limits are recorded in [Phase 11 handoff](phase-11-handoff.md#final-workspace-quality-review).

Code is published on [GitHub](https://github.com/afiqamrii/lrs-projects). At the user's request, a temporary [Vercel sharing preview](https://lrs-sharing-preview.vercel.app) is now READY and anonymously accessible: 240 frozen read-only fictional screens, with mutation/download limits clearly labelled. [The runbook](OPERATIONS_RUNBOOK.md#temporary-vercel-sharing-preview) records actual deployment, checks and refresh instructions. Full Laravel/PostgreSQL hosting, persistent storage, supervised processes and the company live pilot remain outstanding.
