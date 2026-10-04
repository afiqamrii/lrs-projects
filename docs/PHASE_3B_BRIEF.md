Recommended Codex model: GPT-6.1 Sol.
Reasoning effort: Extra High (xhigh).

Select these in the Codex picker before starting; this prompt does not change the active model. If unavailable, use GPT-6 Sol / Extra High if offered. The coding setting is separate from the application's runtime AI model.

Continue the existing LRS Laravel application.

Implement Phase 3B: text-first document extraction, OCR fallback, structured AI field proposals, source evidence, human review and usage/cost tracking.

Build polished screens and working behavior together. Verify the existing Phase 3A handoff before extending it. Complete this phase, verify it, update the documentation, and stop.

## 1. Read and preserve the project

Read AGENTS.md, README.md, docs/PROJECT_BRIEF.md, docs/DESIGN_SYSTEM.md, docs/PHASES.md and docs/HANDOFF.md.

Inspect the real Phase 2/3A models, policies, inquiry statuses, immutable source records, private documents, revision checks, public form, queues and tests.

Retain Laravel/PHP, Blade, Tailwind CSS, PostgreSQL and small vanilla JavaScript.

Preserve compatible installed versions and existing records. Use additive migrations, existing components, small services/actions and Laravel jobs.

Keep Node/Vite as build tooling only.

Do not reset a populated database or introduce another frontend framework, Python service, microservice, vector database or agent framework.

Briefly verify the manual and website inquiry flows, private file access and human readiness confirmation.

Fix directly blocking regressions, preserve unrelated changes, then implement this phase.

Email mailbox integration remains Phase 5; vendor RFQs remain Phase 4; commercial comparison/pricing remain later phases.

## 2. Required result and boundaries

An agent can:

1. Select source documents and inquiry text.
2. Extract readable content locally.
3. Request AI proposals and a short summary.
4. Inspect the supporting evidence.
5. Accept, correct or reject selected proposals.
6. Confirm the shipment using existing Phase 2 rules.

Extraction and AI cannot approve an inquiry, verify customer identity, change the client directory, choose vendors, set prices, send messages or book logistics.

Keep processing states separate from the inquiry's business status.

Applying reviewed proposals does not automatically make an inquiry Ready for sourcing.

Use the original manual email/source text and website submission as evidence.

Website fields are already structured; attachments add evidence and may reveal conflicts, but must not silently replace submitted values.

Do not require a quotation or attachment to use an inquiry.

The supported sources remain PDF, JPEG/PNG, DOCX, XLSX and CSV.

Preserve originals and human document classifications.

Packing lists, goods invoices, customer RFQs and existing freight quotes are distinct document types. A quoted amount is never automatically authoritative vendor cost or selling price.

## 3. Local extraction before AI

Orchestrate extraction from PHP using established tools and compatible libraries. Prefer existing dependencies.

Use Poppler for PDF text/page rendering and Tesseract for local OCR when needed. Document these as operating-system dependencies, not separate services.

Invoke binaries through argument arrays with controlled paths, never shell strings assembled from filenames or source content.

Implement these extraction paths:

- **Digital PDFs:** Extract text by page, preserving line/column layout and page numbers as far as possible. Detect blank/poor text and suspicious layouts using documented heuristics.
- **Scanned/mixed PDFs:** OCR only pages without usable digital text or pages staff explicitly selects for retry. A PDF may need OCR for only some pages. Do not OCR every PDF by default.
- **JPEG/PNG:** Bounded local OCR with orientation handling. Preserve original images and OCR page/word evidence where available.
- **DOCX:** Bounded paragraph/table extraction with block/table/row identifiers. Parse safely without external XML entities or fetching remote resources. Flag unsupported drawings/complex layouts.
- **XLSX:** Use an existing compatible reader or PhpSpreadsheet with sheet/cell provenance and bounded rows/cells. Do not evaluate formulas, refresh external connections or execute embedded content. Distinguish formulas, cached results, labels, units and number formats.
- **CSV:** Bounded encoding/delimiter-aware parsing with row/column provenance. Treat spreadsheet-like formulas as text.

Retain page/block identifiers, extraction method, tool/version/configuration, source checksum, timestamps and quality warnings.

OCR scores, if available, are engine indicators rather than calibrated probabilities of business correctness. Do not invent confidence percentages.

Set configurable page, pixel, text, row/cell, archive-expansion, memory and execution-time limits.

Track skipped/unprocessed content explicitly; never present a truncated document as fully processed.

Password-protected, corrupt, oversized, unsupported or unreadable files get clear recoverable states and a manual-entry path.

Missing binaries/language packs are configuration failures, not successful extraction.

Use a documented OCR language configuration, initially English. Enable other installed languages only through configuration.

Complex tables, handwriting and diagrams may require manual review.

Keep this release focused on local text/OCR. Record image-model escalation as a future enhancement rather than building another paid processing path now.

Store extracted text, rendered pages and evidence privately under existing policies. Protect derived content as carefully as originals.

Keep working files in controlled temporary directories and clean up only this run's temporary files.

## 4. Durable runs, reuse and background jobs

Create maintainable document-processing and AI-run records linked to the inquiry, selected document versions/checksums and source snapshot.

Reuse existing conventions rather than creating a parallel platform.

Expose honest document-processing states such as:

- Not processed.
- Queued.
- Processing.
- Extracted.
- Partial.
- Needs manual review.
- Failed.
- Unavailable.

AI requests separately record Not requested, Queued, Processing, Needs review, Failed or Unavailable, plus staff review outcomes.

Define states/transitions centrally.

Use the existing persistent Laravel queue. PostgreSQL/database jobs are sufficient if no other driver is installed.

Dispatch after transaction commit.

Record errors safely, timestamps, attempts and retry reasons. Configure bounded retries/timeouts and recover abandoned processing records after worker failure.

Combine database constraints and job locks to prevent duplicate concurrent work.

Queue locks alone must not be the only protection for run identity or paid-request accounting.

Do not hold a database transaction open while running OCR or calling the provider.

Reuse successful extraction within the case when checksum, extractor version/configuration and selected page range are unchanged.

Reuse AI results only for an identical authorized input snapshot, model, prompt/schema version and relevant settings.

Do not reuse another client's result through a global content lookup.

A retry/reprocess is explicit and preserves prior runs.

Run local extraction only through authorized staff actions in this phase. Public uploads never directly trigger paid AI.

Give staff a real retry option where meaningful, retain successful pages on partial failure, and explain whether an operation incurs another API request.

## 5. One bounded AI integration

Use an existing compatible AI provider integration if present.

Otherwise add one backend OpenAI Responses API service using Laravel's HTTP client and dependency injection.

Do not add a provider marketplace or another runtime language.

Keep API keys in server-side environment/secrets configuration. Add placeholders to .env.example, never real secrets.

Configure the runtime model explicitly. Do not automatically use the expensive coding model or hard-code an outdated model name.

Confirm its current structured-output support and configured account access through official documentation before choosing request parameters.

Keep live AI disabled until credentials, runtime model and spend limits are configured.

Implement the complete adapter, UI and deterministic test fixtures regardless.

Disabled/missing-key states must leave extraction and manual review usable. Fixture outputs are clearly test/demo data, never labeled live AI results.

Do not send real client documents merely to test the integration.

Default model input is selected extracted text with stable source-block IDs, plus necessary inquiry/source context.

Do not upload the whole PDF or images to the API by default.

Do not send unrelated case history, credentials, internal pricing or other clients' records.

Show the selected sources and processing scope before the agent starts a paid run.

Make one bounded structured request that returns:

- Field candidates.
- Source references.
- Conflicts/missing details.
- A concise draft summary.

Avoid a second model call just to summarize the same data.

When documents exceed the configured input limit, require a deliberate page/source selection or bounded chunking with preserved evidence and ambiguity checks.

Never silently discard content or flatten unrelated shipments together.

Use a versioned strict JSON Schema compatible with the runtime model.

For Responses, use the current text.format structured-output configuration.

Parse and validate server-side.

Handle refusals, incomplete output, invalid structures, timeouts, rate limits and provider failures as real failures/review states.

A valid schema does not prove correct facts.

Treat email/document text as untrusted data.

The AI instruction must extract from evidence and ignore instructions embedded inside sources.

Give this request no tools, web access, filesystem access or authority to execute application actions.

Reject output fields outside the permitted proposal schema.

Where supported, disable provider response storage and document applicable provider handling without claiming this guarantees zero retention.

Keep selected input/result evidence private and out of ordinary application logs.

## 6. Proposal schema and evidence

Propose only existing supported shipment fields:

- Cargo description.
- Route.
- LCL/FCL.
- Package/container groups.
- Quantity.
- Weight.
- Dimensions/volume with units.
- Readiness/requested arrival dates.
- Service scope.
- Pickup/delivery requirements and addresses.
- Requested extras.
- Special handling.
- Stated Incoterm/named place.

Submitted contact details can be flagged for review, never treated as verified identity.

Represent missing values as null/unknown.

Keep raw source values alongside normalization.

Use decimal-safe numeric representations and existing application conversions. Calculations stay in application code.

Dates, currency symbols, decimal separators or units that cannot be resolved from context remain ambiguous.

Keep package rows and container groups distinct.

Flag multiple shipments, conflicting totals, reused headers and likely duplicated rows.

Do not assume an invoice seller address is the shipment origin.

Do not confuse requested arrival with confirmed transit or goods value with freight cost.

Client budget, goods invoice value and reference freight price retain their separate meanings.

Each candidate needs:

- A field identifier.
- Proposed value.
- Raw value.
- Permitted source IDs.
- Source locator.
- Supporting text.
- Any warning/conflict.

Locators mean PDF page, image/OCR block, DOCX paragraph/table row, XLSX sheet/cell or CSV row/column.

Use supplied IDs rather than letting AI invent files/pages.

Validate IDs against the selected run snapshot, check supporting text against that source, and verify numeric/unit consistency where deterministic.

Flag unverifiable evidence and exclude it from default selection.

Keep contradictory candidates visible with their sources. Never silently pick whichever document was processed last.

Use honest labels such as Supported by source, Ambiguous, Conflicts with current value and Missing evidence.

Do not present a model's self-assessed confidence as a measured accuracy score.

Preserve OCR-quality warnings even when the model returns a plausible value.

The draft summary must identify missing/uncertain information and remain labeled unreviewed.

Preserve it as run evidence. Mark it stale when relevant inputs change.

A staff-confirmed shipment summary should reflect accepted current values, using a deterministic template where practical.

## 7. Human review and safe application

Add an agent review action for each proposal:

- Accept.
- Correct and accept.
- Reject.
- Leave unresolved.

Allow selection of multiple supported proposals with a readable change preview.

Do not preselect overwrites of populated fields or unresolved conflicts.

Show current value, proposed value, units, evidence and warnings together.

Require explicit acknowledgement of replacement/conflict. Record an explanation for a corrected or conflicting critical value.

Missing evidence cannot become a source-verified AI value. An agent may instead enter a documented manual correction.

Applying reviewed values uses the existing validation, policies and Phase 2 mutation/version actions.

Recheck the inquiry revision and selected source snapshot in a transaction.

If material data changed during processing/review, stop application and show the stale result with a compare/review path.

Never silently overwrite a newer agent edit.

If a confirmed shipment is materially changed:

- Create the next draft revision.
- Preserve the confirmed snapshot.
- Require reconfirmation.

Do not weaken the existing Ready for sourcing gate.

Changing nonmaterial internal notes should not generate unnecessary shipment revisions.

Record reviewer, time, accepted/corrected/rejected candidates, before/after values, run/source references and resulting revision.

Reapplying the same reviewed action is idempotent.

Extraction completion never advances the business workflow on its own.

## 8. Usage controls and honest cost reporting

Before a paid run, display source/page count, input-size estimate, configured model and a cost estimate when supported by configured current rates.

Character counts are not exact token counts; label estimates.

Unknown price or usage stays unknown, not zero.

Configure per-run output/input limits, per-inquiry/daily spend limits and bounded paid retries.

Use conservative cost reservations with database-safe accounting so simultaneous requests cannot bypass caps.

If a hard monetary estimate cannot be made, block paid submission until the required pricing/budget configuration is available.

Keep manual extraction/review usable.

Record actual provider request ID, model, status, attempts and reported token usage, including reported cached/reasoning usage where relevant.

Calculate estimated cost using a versioned, dated rate configuration and decimal arithmetic.

Distinguish this calculation from an actual provider invoice. Include failed charged attempts when usage is reported.

An ambiguous network timeout may have reached the provider.

Record uncertain usage/cost and avoid blind repeated paid calls.

Document reservation reconciliation and retry policy.

Do not claim exactly-once provider billing or invent zero usage for failed calls.

Give Admin simple configuration/status and usage totals. Show agents per-run usage/cost/state.

Reuse existing settings components. Do not expose API keys or add decorative analytics.

## 9. Professional review workspace

Extend the current inquiry workspace and navy/teal/light design.

Add a clear Documents/Extraction review area with processing progress, selected sources, run history, errors and the next action.

Use a two-column desktop arrangement:

- Source preview/page/text on one side.
- Field proposals and review actions on the other.

Keep page/sheet navigation and evidence links functional.

Highlight the supporting passage where possible. Otherwise open the correct page and show the exact text instead of claiming precise highlighting.

Stack cleanly on mobile with an easy switch between source and proposal.

Use the existing icon family, accessible labels, keyboard focus and readable tables/units.

Show partial, empty, disabled, failed, stale and reviewed states honestly.

Preserve selected review decisions after recoverable validation errors.

The primary sequence is:

Extract source → Request AI proposals → Review changes → Apply selected values → Confirm shipment through existing review.

Keep paid requests explicit, explain unresolved gaps, and avoid exposing implementation jargon in customer-facing pages.

Public receipt/form pages get no AI output or staff actions.

## 10. Verify, hand off and stop

Create small synthetic fixtures covering digital PDF, scanned PDF, mixed pages, images, DOCX, XLSX and CSV, with known fields and source locations.

Include absent/ambiguous units, contradictory quantities, an invoice goods value versus a freight reference price, malformed documents and source text containing hostile instructions.

Never include real client data/secrets in fixtures.

Run real local extractor/OCR checks where dependencies are available.

Add meaningful PostgreSQL-backed tests for:

- Private derived-file access.
- Source lineage.
- Reuse/concurrency.
- Failed/partial jobs.
- Server-side proposal validation.
- Invalid/refused AI results.
- Cost caps under concurrency.
- Stale revisions.
- Confirmed-shipment reconfirmation.

Test that the AI cannot mutate records or trigger outgoing actions.

Use deterministic fake provider responses for repeatable application tests.

If live credentials are configured, use only controlled synthetic input within the configured budget for an integration check.

Report live and fixture checks separately.

Without credentials/dependencies, state exactly what remains unverified and provide setup instructions. Do not claim a live AI/OCR pass.

Demonstrate this acceptance story:

1. Open an incomplete inquiry and extract a digital packing list. Verify ordinary text did not use OCR.
2. Process a scanned/mixed document. Verify OCR was limited to the necessary pages and evidence points to the correct source.
3. Request one AI result containing proposals and summary. Inspect usage and source evidence.
4. Find a conflict or unknown measurement. Correct/reject it and retain unresolved gaps.
5. Apply selected reviewed values. Verify client data, existing fields and readiness were not changed automatically.
6. Confirm the shipment using existing human review rules.
7. Start another run, edit the shipment before applying, and verify stale proposals cannot overwrite the newer revision.
8. Modify a confirmed material field through review. Verify a new draft needs reconfirmation.
9. Reuse unchanged extraction/results. Verify no unnecessary paid request. Test budget, missing-key, unreadable-document and provider-failure paths.
10. Verify public/unauthorized users cannot access originals, extracted text, page images, AI results or review actions.

Run relevant existing tests, formatting and the production asset build.

Visually verify real screens at desktop/mobile widths, keyboard review, errors and browser console. State any unperformed checks.

Update README.md and docs/PROJECT_BRIEF.md, DESIGN_SYSTEM.md, PHASES.md and HANDOFF.md with:

- Actual dependency/install instructions.
- Supported extraction limitations.
- Schema/prompt versions.
- Source mapping and policies.
- Run/retry states.
- Queue setup.
- Human revision rules.
- Runtime-model configuration.
- Budget/rate configuration.
- Measured fixture outcomes.

Report changes, checks, limitations and a short manual acceptance checklist.

Stop after Phase 3B.

The next phase is Phase 4: agent-selected vendors, professional RFQ drafts, recipient/attachment selection and approval of an exact outreach version.

Recommend GPT-6.1 Sol / High for that coding prompt. Live mailbox sending remains Phase 5.