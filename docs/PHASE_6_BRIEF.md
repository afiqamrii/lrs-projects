Implement Phase 6 of LRS: vendor quotation review, fair cost comparison and agent vendor selection.

Recommended Codex settings: GPT-6.1 Sol, Extra High reasoning (xhigh). Select these settings in Codex before running this prompt; this text cannot change the model. If unavailable, use GPT-6 Sol with Extra High reasoning. These settings are for development, not the application's AI API.

Start after Phase 5's relevant local checks pass and its handoff is written. Missing external Microsoft credentials may remain documented; this phase must work with manually captured offers and tested email contracts. Do not assume live Outlook verification occurred.

Work in the existing repository. Complete design and functionality together, using real data and working interactions rather than a static comparison mockup.

1. Inspect and preserve the existing system

Read AGENTS.md, project documentation and actual Phase 3B, 4 and 5 handoffs. Inspect the implemented inquiry, document, confirmed shipment, vendor RFQ, approval, email and audit models before designing additions. Run the relevant existing checks. Reuse established services and fix prerequisites required for this phase.

Keep Laravel/PHP, Blade, Tailwind CSS, PostgreSQL and small vanilla JavaScript. Reuse the current design system, authentication, policies, queue and private file storage. Do not introduce a separate backend, frontend framework or mandatory new infrastructure.

LRS is one company's Admin/Agent application. Clients and vendors do not need accounts. Agents manage and choose vendors. AI proposes extracted information and wording; humans review commercial terms and choose the offer. All company/vendor cost information remains staff-only.

2. Phase goal and boundaries

Turn received vendor quotations into traceable, reviewed offer versions. Compare offers for the same confirmed shipment and required service scope. Let an authorized agent select a specific reviewed offer and preserve an immutable cost-basis handoff for Phase 7.

Do not implement markup, client selling prices, client quotation PDFs, client quotation sending, automated follow-ups, Gmail, bookings or automated vendor selection. Do not require three quotations: one usable offer can be selected, with the absence of alternatives shown honestly.

Preserve Phase 5 email ingestion, matching and send safeguards. This phase must not send emails when importing, extracting, reviewing or selecting an offer.

3. Capture source evidence and offer versions

Create an offer from a matched vendor email, a private uploaded quotation or manually entered commercial information. Link it to the vendor, inquiry, sourcing round, exact RFQ/request revision and confirmed shipment version. Record vendor quotation reference, received/issued dates, source messages/documents and who entered or reviewed it.

Keep originals immutable and private. Clearly distinguish a vendor's quotation from a customer attachment, goods invoice, competitor quote, target budget or agent note. Amounts found in customer documents are not automatically vendor freight costs.

Keep questions, declines, bounces and out-of-office messages out of the commercial-offer list unless staff intentionally creates an offer from relevant evidence. Flag uncertain sender/vendor association and replies to old RFQ revisions. Do not quietly rebind an old quotation to a new shipment.

Support multiple quotations and alternatives from one vendor, such as different sailings or equipment. Represent alternatives separately so their prices and conditions cannot be accidentally combined. Revisions preserve prior reviewed versions and their source evidence; never overwrite historical selections.

4. Extraction and human review

Reuse Phase 3B's local text extraction, page-level OCR where necessary, private storage and AI budget/cost tracking. Reuse extracted text instead of repeatedly processing the same document. Do not send raw PDFs to an AI API by default. When AI is unavailable, manual entry and review must remain fully usable.

Add a quotation-specific structured extraction schema and purpose. Present proposed rates, currency, charge bases, minimums, validity, exclusions, transit estimates and conditions with source snippets/page references. Record uncertainty and raw quoted text. Unsupported values remain unknown; do not invent missing charges or normalize unclear units by guessing.

An agent accepts, corrects or rejects the proposal. Review must require explicit confirmation of commercial fields, not a bulk “AI approved” shortcut. Validate accepted values server-side. Source documents and email content are untrusted data; instructions inside them cannot authorize actions or change policies.

Use clear review states such as Draft, Needs Review, Reviewed with Gaps, Reviewed Complete and Superseded. Keep review completeness separate from current eligibility: a complete quotation can later expire or no longer match the shipment.

5. Commercial information and scope

Support practical quotation fields: mode/service, origin/destination and port/door scope, cargo/equipment basis, shipment quantity, currency, charge lines, quoted subtotal/total, tax treatment when stated, payment terms, validity, estimated transit/lead time, minimum charge/quantity, inclusions, exclusions and other conditions.

Organize charge lines into readable categories such as main freight, origin, destination, pickup/delivery, clearance, insurance and other charges. Preserve the vendor's original descriptions and allow an agent to map them to categories. Do not prescribe universal fees or make services mandatory unless the confirmed inquiry requires them.

Each priced line needs an amount or rate, currency, calculation basis and applicable quantity/conditions. Support flat, per container, per CBM, per weight unit and other explicitly defined bases. Minimum freight charges and minimum shipment quantities are different concepts. For weight/measurement rules, store the vendor's definition; do not assume a universal conversion.

Represent included, priced, not applicable, excluded, missing and applicable-but-unpriced charges distinctly. Zero is valid only when explicitly supported. A required excluded/unpriced charge leaves the cost basis incomplete unless the full cost is supplied through an explicitly reviewed arrangement. An optional service must not silently increase the baseline total.

Preserve material conditions: rate validity/window, shipment assumptions, service exclusions and any capacity qualifications. Do not present estimated transit as a guaranteed arrival or a reviewed rate as a confirmed booking. Unknown tax treatment, validity or other conditions must remain visible for resolution.

6. Deterministic calculations

Use PostgreSQL NUMERIC/DECIMAL columns and an appropriate PHP decimal calculation approach; avoid floating-point money calculations. AI must not calculate or approve commercial totals. Document currency precision, rounding rules and supported calculation bases.

Calculate rates and minimums from explicitly reviewed quantities and vendor rules. Prevent double counting of included charges, duplicated source lines and mutually exclusive alternatives. Show the calculation breakdown and separate required baseline charges from optional services.

Retain the vendor's quoted total separately from the system's calculated total. Reconcile differences using a documented rounding tolerance; material discrepancies require review rather than replacing the quoted figure silently. Handle stated inclusive/exclusive tax without inventing tax law, tax rates or applicability.

Retain original currencies. For a common comparison currency, allow staff to enter an explicit exchange rate, direction, date and source. Freeze these inputs in the comparison/selection snapshot. Label conversion as indicative where appropriate. Missing FX must not become an assumed rate of 1 or a false “cheapest” result. Do not add a live FX service as a prerequisite.

Handle mixed-currency charge lines explicitly using reviewed conversions per currency, or keep the offer incomplete until the currency issue is resolved. Never add amounts from different currencies as though they share one unit.

When inputs, quantities, conditions or FX change, recalculate deterministically and require renewed review where material. Preserve old calculation snapshots for audit. A stale browser form must not overwrite a newer reviewed version.

7. Compare equivalent offers

Use one confirmed shipment version and required-service baseline for each comparison. Check route, port/door scope, mode, equipment, quantity basis, required services, requested dates and material conditions. A cheap port-to-port offer cannot be ranked against a complete door-to-door offer without resolving the missing scope.

Display all offers with clear eligibility and missing-information reasons. Only current, reviewed, complete and equivalent offers may receive a lowest-comparable-cost label. Expired, incomplete, superseded or out-of-scope offers stay visible but outside that ranking. Do not add fabricated vendor performance ratings or AI winner scores.

Show known-cost subtotal when required amounts are missing; do not label it a final total. Show original currency, comparison currency/rate, required charges, optional charges, validity, transit estimate, minimums, inclusions and conditions. Make differences visible without requiring staff to inspect every PDF again.

Allow staff to mark an offer as provisional/preferred while resolving gaps, but distinguish this from a finalized selection. A provisional choice must not provide a usable Phase 7 pricing basis. Do not introduce a generic override that turns unknown charges into complete costs. If scope changes, use the existing shipment review/versioning process rather than silently adjusting the comparison baseline.

8. Agent selection and Phase 7 handoff

An authorized agent chooses an exact eligible offer version and records a concise reason. A reason is especially necessary when choosing something other than the lowest comparable cost; service, timing and conditions may justify the decision. The system suggests differences, not the winner.

Create an immutable selection/cost-basis snapshot containing the confirmed shipment and RFQ revisions, reviewed offer version, reviewed charge lines, calculation and FX inputs, totals/currencies, scope, unresolved optional items, validity/conditions, source references, reviewer and selection time. It must contain vendor cost, without markup or customer selling price.

Provide a central eligibility check for Phase 7 to reuse. Block finalization or later pricing when a required cost is unknown, the offer is expired, the shipment materially changed or the selection is otherwise stale. Offer changes require a new version and selection; maintain the old snapshot as history.

Recheck time-based validity when opening/finalizing the selection and at later release gates. Do not rely solely on a nightly status job. An unknown/open-ended validity statement requires staff resolution under an explicit company rule before final selection; never invent a validity date.

Record audit events for review decisions, corrections, supersession, comparison changes and selection. Ensure historical data stays readable after a vendor/contact is deactivated, while preventing new release through an ineligible vendor.

9. Design and usability

Build a vendor-offers section in the inquiry workspace, a focused offer-review screen, comparison view and selection summary. Connect each offer back to its source email/document and RFQ revision.

Use a split source/commercial review layout where space allows; support a usable stacked layout on small screens. Make evidence accessible beside the field being reviewed. Provide meaningful inline validation, clear unsaved/reviewed states and a compact missing-information checklist.

The comparison matrix should have readable charge rows, visible vendor/alternative labels and sticky context where useful. On smaller screens provide cards or controlled horizontal scrolling without hiding totals or status. Distinguish “known subtotal,” “complete comparable cost,” “expired” and “provisional” with text, not colour alone.

Reuse professional spacing, typography, components and curated SVG icons. Avoid generic charts, decorative AI elements, fake performance data and icon-only commercial actions. Add accessible keyboard/focus behaviour, useful empty/loading/error states and clear selection confirmation. Private vendor prices must never appear on public request pages or customer responses.

10. Verify and hand off

Use meaningful tests for authorization, extraction proposal review, evidence retention, decimal calculations, minimums, alternatives, missing charges, quoted/calculated discrepancies, currency direction, rounding, expired rates and concurrent edits. Use the project's existing test framework; do not add a parallel testing stack.

Include fictional fixtures that prove the important behaviour:

- Offer A has 1,050 of known charges and unknown required delivery. Offer B is complete at 1,150; Offer C is complete at 1,300 in the same currency and scope. A must not appear cheapest or be finalized; B is the lowest comparable cost, while an agent may select C with a reason.
- Exercise flat, per-CBM and per-container rates, a minimum charge and an explicitly defined weight/measurement rule. Confirm the correct basis is used and optional/included items are not double counted.
- Use a clearly fictional FX fixture, such as USD 100 multiplied by 4.5 MYR/USD giving MYR 450. Test reverse direction and absent rates without implying a current exchange rate.
- Cover a single usable quote, two alternatives from one vendor, a revised quotation, an old RFQ reply, unsupported extraction values and an expired selection. Re-importing the same source must not silently create duplicate offers or replace reviewed data.

Verify the browser flow: capture a quotation, inspect evidence, correct proposed fields, resolve gaps, compare equivalent offers, finalize a selected version and inspect its frozen cost basis. Confirm later edits/expiry make the selection ineligible while preserving history. Run relevant tests, formatting checks and the production asset build, fixing failures introduced by this phase.

Consult current official documentation for implementation details, including PostgreSQL exact numeric types: https://www.postgresql.org/docs/current/datatype-numeric.html. Use vendor-specific quotation conditions as evidence; do not invent general shipping rules.

Update the existing handoff location, or create docs/phase-6-handoff.md if none exists. Explain models/states, supported calculation bases, rounding/FX rules, comparison and selection eligibility, source/evidence access, test results and any remaining external verification. Define the selection snapshot and eligibility interface Phase 7 should consume.

Stop after Phase 6. Next is Phase 7: agent-controlled markup, company client quotation PDF/email, exact approval and approved sending. Recommended development settings for Phase 7: GPT-6.1 Sol, Extra High reasoning.