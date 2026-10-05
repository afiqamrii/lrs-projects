Implement Phase 7 of LRS: agent-controlled markup, professional client quotation PDFs, exact approval and approved sending.

Recommended Codex settings: GPT-6.1 Sol, Extra High reasoning (xhigh). Select these in Codex before running this prompt; the prompt cannot change the model. If unavailable, use GPT-6 Sol with Extra High reasoning. These settings concern development, not the application's AI API.

Run after Phase 6's relevant checks and handoff. Work in the existing repository and complete design and functionality together.

1. Establish the actual foundation

Read AGENTS.md, project documentation and Phase 5/6 handoffs. Inspect the implemented selection snapshot, eligibility service, decimal calculations, private documents, email outbox, approvals, policies and UI. Run relevant existing checks. Reuse these contracts and fix necessary prerequisites rather than rebuilding them.

Keep Laravel/PHP, Blade, Tailwind CSS, PostgreSQL and small vanilla JavaScript. Node/Vite remains build tooling. Use existing queue/scheduler infrastructure and the current design system. Do not introduce a separate backend or frontend framework.

LRS serves one company with Admin/Agent staff. Customers submit inquiries through email, the public form or manual capture. Agents choose vendors and pricing; AI cannot approve, calculate commercial totals, send or book. Client/vendor accounts are unnecessary.

2. Scope and pricing readiness

Create company-branded client quotations from Phase 6's immutable selected vendor cost basis. Include a pricing workbench, customer-visible service lines, private cost/profit view, PDF generation, email drafting, approval and sending through Phase 5.

Require a current, complete, eligible selection. Block final approval and release for missing required charges, unresolved currency/tax treatment, expired vendor rates, changed shipment scope or stale selection. Recheck eligibility at approval, enqueue and worker release, not only when opening the screen.

Draft work can be saved with visible gaps, but cannot be sent. Do not silently refresh costs or replace an offer under an existing quotation. A material change creates a new quotation revision.

Do not implement automated follow-ups, Gmail, client acceptance automation, booking, payments, invoices or e-invoicing. These are outside this phase.

3. Deterministic agent-controlled pricing

For the initial implementation, support one explicit percentage markup on the reviewed eligible cost subtotal, with separately displayed optional services and stated tax. Show exactly which cost lines form that subtotal. Do not mark up tax or optional/unselected charges accidentally. Keep pricing simple rather than adding a general-purpose rules engine.

The agent explicitly enters/confirms the markup. An Admin default can prefill a proposal, but cannot approve pricing. Show vendor cost, markup amount, customer selling subtotal, applicable stated tax, final customer total, currency and estimated gross profit. Keep all costs, markup and profit staff-only.

Use Phase 6's exact decimal arithmetic and documented rounding. Markup is applied to cost: selling subtotal = eligible cost subtotal × (1 + markup percent / 100). Label gross margin separately as profit / selling subtotal; do not confuse it with markup. Handle zero selling subtotal without division errors. Validate percentage bounds and reject unsupported or negative inputs server-side.

Use fictional verification examples: cost 1,000 with 20% markup produces selling subtotal 1,200 and profit 200, with gross margin approximately 16.67%. This example excludes tax and is not a prescribed business rate.

Freeze currency, FX, quantities, charge bases and pricing inputs in each revision. Mixed currencies need reviewed conversion before customer pricing. Do not invent tax rates, recoverability or legal wording. Unresolved treatment requires staff review.

4. Client-facing document and email

Create a professional quotation with a unique human-readable reference and revision, company identity/logo/contact, client details, issue/expiry dates, shipment/service summary, customer selling lines, optional services, totals/currency, relevant inclusions/exclusions, conditions and response instructions.

Client validity must not extend beyond the selected vendor rate's validity. Define date-only expiry semantics in the company timezone and show the deadline clearly. Use staff-confirmed terms; do not guarantee capacity, sailing or arrival where the vendor only supplied estimates. Quote acceptance does not confirm a booking.

Do not expose vendor quotes, internal vendor identity/contact where unnecessary, cost lines, markup, profit, staff notes or AI evidence. Avoid deriving the client PDF by simply forwarding or restyling the vendor's original file.

Use a maintained PHP-compatible PDF solution compatible with the repository. Prefer a focused Blade PDF template with local assets and predictable pagination. Do not assume Tailwind's browser rendering transfers perfectly to PDF. Avoid a mandatory separate rendering service. Disable unnecessary remote resource fetching and save generated PDFs privately.

Render and visually inspect short and multi-page samples: wrapping descriptions, long client names, totals, page breaks, headers/footers, logo and currency symbols. A successful PDF command alone is insufficient.

Provide a professional editable email with the quotation reference, short shipment summary, expiry and clear instructions to reply with acceptance or requested changes. Deterministic wording must work without AI. Optional AI polishing reuses existing budgeted services and may not change price, scope or terms.

5. Exact approval and immutable revisions

Add the client quotation approval gate: an authorized staff member reviews the exact pricing, PDF, email, recipient addresses, attachment manifest and actual sender/Reply-To identity. Admin and Agent permissions follow the existing company policies; do not invent a second reviewer requirement unless configured.

Store the approved revision, pricing/source snapshot, PDF file/version/checksum, email subject/body, exact recipients/envelope, terms, reviewer/time and canonical approval digest. Approval covers the actual generated PDF bytes. Do not regenerate a different PDF during sending.

Edits to customer-facing content, commercial inputs, recipients, sender, terms or attachments invalidate approval and create a draft revision. Preserve previous revisions and send history. Changes to company branding/terms must not alter an already approved document invisibly.

Use understandable states such as Draft, Needs Review, Approved, Submission Pending, Provider Accepted, Failed, Outcome Uncertain, Expired and Superseded, mapped to actual existing dispatch states. Avoid showing “delivered” based on provider acceptance.

6. Approved sending and recovery

Release only via an explicit authorized action using Phase 5's shared dispatch/outbox service. Reuse its idempotency, envelope authorization, attachment integrity, reconciliation and uncertain-outcome safeguards. Never bypass it through a convenience SMTP call.

Check approval digest, source selection, current shipment, validity and private PDF availability/checksum immediately before sending. Connecting/reconnecting a mailbox or generating a quotation must not send anything automatically. A manually recorded send must not be resent on connection.

Show separate preparation/submission failures and uncertain results. Do not blindly resend after a timeout. A deliberate resend needs the existing explicit resend authorization and an honest link to the earlier dispatch. Preserve browser usability when live provider credentials are missing.

7. Design and usability

Build a pricing workbench and quotation tab inside the existing inquiry workspace. Show source selection, pricing breakdown, customer preview and readiness checklist together. Make “Save draft,” “Review and approve” and “Send approved quotation” distinct actions.

Use a focused PDF preview/download, revision selector, email preview and timeline. Present selling totals prominently and internal cost/profit in a clearly staff-only area. Do not label potential profit as realized revenue.

Reuse professional typography, spacing, components and curated SVG icons. Provide accessible labels, keyboard/focus states, responsive layouts, clear validation and useful empty/loading/error states. Do not use fake charts or decorative AI graphics.

8. Verify, document and stop

Test markup versus margin, decimal rounding, zero amounts, currency/tax gaps, eligibility and expiry, approval invalidation, concurrent edits, immutable PDF bytes, recipient authorization, private access, duplicate jobs and uncertain send outcomes. Verify customer documents contain no internal costs or notes.

Run a browser flow from a reviewed selection through draft pricing, PDF/email review, approval and fixture-based sending. Exercise a later shipment/rate change and prove release is blocked. Use fictional data or explicitly authorized test mailboxes; do not send to real clients/vendors as a test.

Run relevant tests, formatting checks and production asset build. Document supported pricing rules, PDF dependency, expiry semantics, authorization, configuration and live checks still unverified. Update the existing handoff location or create docs/phase-7-handoff.md. Define quotation snapshots, send events and eligibility hooks Phase 8/10 must consume.

Stop after Phase 7. Phase 8 adds explicitly approved follow-up policies and escalation. Recommended settings: GPT-6.1 Sol, Extra High reasoning.