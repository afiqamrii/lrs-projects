# LRS design system
Phases 1–11 · refined 4–5 October 2026 following the user's Apple-style design direction. Preserve the accepted screen structure while continuing the lighter visual language.

## Direction and identity
Use a light, calm workspace with clear hierarchy, generous white space, soft depth and selective color. The Apple inspiration is translated to a browser application: silver navigation, graphite text, white surfaces, blue actions and rounded controls. Do not copy Apple branding or introduce proprietary fonts. The LRS wordmark and blue geometric mark remain concept branding pending company approval.

Reference: [Apple Human Interface Guidelines](https://developer.apple.com/design/human-interface-guidelines/) and [Apple's web visual language](https://www.apple.com/). These are design references, not a claim of native Apple platform conformance.

## Palette
| Token | Value | Use |
|---|---|---|
| Canvas | #F5F5F7 | Cool silver page background |
| Surface | #FFFFFF | Forms, lists and content cards |
| Ink | #1D1D1F | Main text and headings |
| Accent | #0066CC | Links, focus and supporting icons |
| Primary action | #0071E3 | Primary buttons and selected navigation |
| Sidebar | #F1F2F6 → #EDEEF1 | Quiet silver navigation |
| Border | #DEDEE3 | Hairline panels and separators |
| Field border | #BFC0C6 | Clear input boundaries |
| Secondary text | #6E6E73 | Descriptions and metadata |
| Blue tint | #EDF4FF | Directory icons and supporting context |
| Green tint / text | #EAF6EE / #24723D | Active status and positive feedback |
| Amber tint / text | #FFF4DF / #855600 | Missing contact and review warnings |
| Danger | #B42318 | Destructive status confirmations |

Tailwind slate tokens are mapped to neutral gray values to avoid blue-gray text. Semantic states always have readable text or labeled icons; color alone never carries meaning. Use color sparingly around actions and operational status.

## Typography, space and shape
Use `-apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, sans-serif`. Apple devices use their local system font; Windows uses Segoe UI. No remote font call or bundled San Francisco font.

Page headings are 30px mobile / 36px desktop, with tight tracking and a clear hierarchy. Section headings are 18px; body/forms 14px; field labels 13px; supporting content generally 12px. Secondary timestamps and dense metadata may use 11px. Eyebrows are sentence case and 12px, without decorative uppercase tracking. Metric counts use tabular numerals.

Spacing follows a 4px rhythm. Pages use 20px mobile / 36px desktop padding. Panels use 20–28px, section gaps 24px. Main actions are at least 44px high; compact secondary actions may use 36px. Inputs are at least 46px high. Cards/tables use 20px radii, inputs 10px, main actions/status pills fully rounded, native confirmations 24px. Shadows are subtle; avoid stacking elevated boxes or heavy outlines.

## Layout and responsive behavior
Keep the accepted 248px fixed sidebar, compact 72px top bar, heading/action area and practical list/form/detail structure. The sidebar is light silver; selected navigation is blue with white text. The top bar has restrained translucency with a solid fallback. Context/activity side panels use real records.

Below 1024px, enhanced navigation is a native dialog drawer. Without JavaScript, ordinary navigation remains above content. Forms stack, headings/actions wrap and long values break within panels. Tables scroll in an explicitly labeled, keyboard-focusable region. The table region must remain positioned relatively to contain accessible offscreen labels and prevent page overflow.

Desktop sign-in keeps the two-column structure with large typography and a faint blue/lilac wash. Mobile sign-in uses a compact brand header and immediately reachable form. Background color never reduces form readability.

## Data graphics
The overview includes one compact stacked directory health bar. Its categories are mutually exclusive: active with a primary contact, active without a primary contact, and inactive. Counts come from persisted vendor/contact records. The labels link to the matching server-side directory filters.

Keep a caption, exact text counts and non-color labels alongside the graphic. The bar is decorative to assistive technology because the accessible legend contains the data. Zero records render a neutral track, zero counts and an explicit empty message; never divide by zero. The general missing-contact metric includes inactive records, while the amber health segment includes active records only.

Do not add fabricated revenue, shipments, quotation trends or analytics for later phases. Charts should answer an operational question using available data. No chart dependency is required for this Phase 1 graphic.

## Shared components and icons
Reusable Blade components are in `resources/views/components`: layout, guest, navigation, brand, page-header, button, field, badge, flash, empty, pagination, activity, directory-health, icon and dialog. CSS lives in `resources/css/app.css`, with Tailwind 4 tokens and component utilities. Do not introduce another frontend framework, generic admin kit or paid UI dependency.

Use only curated local Phosphor SVG assets in `resources/icons`. Navigation uses restrained duotone and actions regular weight. The component has an explicit allowed-name list. Preserve `licenses/PHOSPHOR-LICENSE` (MIT). Decorative icons are hidden; icon-only actions have meaningful labels. No emojis or mixed icon families.

## Forms, feedback and accessibility
Persistent labels, logical numbered sections, required markers and useful hints remain. Server validation is authoritative. Preserve non-secret input after errors and never repopulate passwords. Show an error summary and inline errors. Cancel returns to the appropriate record/list.

Vendor status changes use a standalone confirmation screen that works without JavaScript. Staff access edits add a native confirmation dialog when JavaScript is available. Directory search/filter/pagination stays server-side. Empty directory and no-results search are distinct; missing contacts and inactive status are explicit.

Small vanilla JavaScript enhances menus, password visibility and dialogs. Native dialogs contain focus; Escape/backdrop/cancel dismiss; focus returns to the trigger. Keep a skip link, descriptive headings, labeled inputs, alert/status roles and visible 3px blue focus indicators. Feedback transitions are 150ms. Reduced-motion disables them; reduced-transparency removes blur from supporting surfaces where the browser supports that preference.

Persist timestamps in UTC and display the company timezone. Browser evidence and remaining acceptance checks are in HANDOFF.md and README.md.

## Phase 2 workspace patterns
Keep the same light Apple-inspired system. Client/inquiry tables share Phase 1 panel, pagination and filter controls. Blue marks actionable navigation; green marks eligibility/approval, amber marks gaps/waiting/hold, and all states include text.

Inquiry header presents reference/title/client, status, active owner, local deadline and revision. Overview/Shipment/Documents/Activity use rounded link tabs with aria-current. Main evidence/forms and a 320px review column sit side by side at xl, then stack. Tables scroll inside their bounded container; page content must not overflow the viewport.

The editor separates client/responsibility, preserved original request, route/cargo, services/addresses, LCL or FCL rows, specialist requirements, optional commercial context and notes. JavaScript reveals the selected mode, filters contacts by client, manages up to 20 rows with unique labels/IDs, warns before leaving unsaved edits, and provides clipboard fallback. Server validation and non-JavaScript row-add/navigation remain available. Unknown measurements display as Unknown; calculated and declared volume have distinct labels and sources.

Clarification approval confirms saved text/recipient/revision. The approved text is read-only and Copy gives an aria-live result. The manual-communication form requires an explicit checkbox and actual time/channel. Document previews use authenticated URLs with visible download fallback and truthful extraction/scan state. Immutable version pages are read-only, with reviewer/time/contact and a calculation basis.

Overview queues use real records. Preserve the existing vendor-health graphic with linked filters. No invented performance graph or later-phase commercial metric is appropriate yet.

## Phase 3A customer-page patterns
Use the same Apple-inspired silver/white/graphite/blue system and local Phosphor icons. The customer layout has a compact brand header, restrained blue wash, a short credible general-cargo sea-freight introduction, company contact details and the inquiry form. It has no staff sidebar, fabricated metrics, customer logos or price/booking guarantees. Local/placeholder configuration is labeled; a local link is never presented as externally shareable.

The visible four-step indicator links Contact → Shipment → Documents → Review. JavaScript progressively shows one section, focuses each section heading on navigation, validates required fields before advancing and brings the first server/client error into view. Without scripts all sections and the server submission remain available. Native server POST buttons add LCL/FCL rows and preserve text; selected attachments must be reselected after any server round trip.

Explain LCL as grouped/shared-container cargo and FCL as full-container cargo without inventing capacity rules. Choice cards offer Not sure. Optional details say To be confirmed/Unknown. Package groups distinguish row-total gross weight from per-package dimensions; declared volume has its own source. Hidden alternate-mode fields remain enabled and preserved. Row removal asks before discarding entered details. Address fields follow scope/service selections while retaining previously entered values.

Review uses text nodes, readable grouped values and Edit links back to each section; both entered LCL/FCL rows remain visible as retained evidence. Selected files say selected, never uploaded, until successful persistence. Privacy acknowledgment is an explicit required checkbox, separate from marketing. Submit gives a busy state; failures retain safe inputs, show inline and summary errors and explain file reselection. Unsaved changes use the browser leave warning; sensitive contents are never stored in localStorage.

The narrow layout uses 18px page padding, stacked choices/fields and wrapped review/contact text. Keep at least 44px primary controls, visible focus, semantic fieldsets/legends and useful status/alert regions. At 320px/390px, no customer-page horizontal scrolling is intended. File inputs and long names must shrink inside their field container. The skip link appears when focused.

Receipt shows a session-bound reference, honest assessment next steps, company contact and distinct email state. Confirmation is minimal: a one-time code, explicit Confirm action and safe invalid/expired/already-confirmed states. It must not expose private shipment or file links.

Staff additions fit the existing workspace: copy-link field/action with text-selection fallback and Local preview label; Website/source and Unassigned filters; immutable original-evidence panel; client-assessment page with working corrections, explicit existing/new association and private possible-match hints. Mailbox access and staff identity selection use separate text badges. Unknown ownership/deadline/contact and specialist gaps remain amber and actionable. No new decorative chart is justified by this phase.

## Phase 3B evidence-workspace patterns

Continue silver/white/graphite/blue surfaces and restrained amber/green labels. Sequence: Extract sources → Request proposals → Review & apply → Confirm shipment. Processing/review status and inquiry business state remain distinct.

Desktop uses a sticky source panel beside proposal cards. Page/row/cell navigation, private original links, rendered previews and exact quoted passage are functional. Literal source text highlights when possible; otherwise show correct source and quotation without claiming precise image highlighting. URLs use stored candidate/source IDs, not private quote content.

Mobile stacks panels and provides Source / Proposals switch links. Long text wraps; source text is a focusable labelled bounded region; cell tables scroll within their panel. Current/proposed values, units, raw evidence, warnings and review actions stay together. Native labelled selects disclose corrections; validation preserves selected decisions. No decision is preselected. Optional bulk selection includes only supported empty unambiguous/unconflicted values.

Change preview shows every before/after effect, explanation and explicit application acknowledgement. Stale results retain evidence/current comparison and block application. Empty/disabled/queued/partial/unavailable/failed/reviewed/uncertain-cost states state what happened and the next action. Synthetic output and unreviewed/stale summaries are clearly labelled.

Scope and usage display real sources/pages, characters, conservative token bound, model/output, estimate/held uncertainty and reported usage. Admin uses existing form/table components. Do not add decorative analytics, fake zero-cost graphs or invented accuracy percentages. Public intake/receipt pages expose no staff or AI controls.

## Phase 4 sourcing patterns

Continue the accepted Apple-inspired light palette, blue primary actions, restrained semantic color, system typography, shared outline icons, generous spacing and quiet white panels. The Phase 4 attachment’s older navy/teal wording does not reverse the user’s explicit accepted design direction. No fake response charts or ratings: sourcing counts come from real saved vendor requests; future offer metrics await actual data.

Keep the four-step sequence visible: Confirm inquiry → Select vendors → Prepare requests → Review & approve. Readiness gaps precede directory selection and link to recovery. Vendor cards show only recorded services, coverage, minimum notes and active contacts; unknown facts are labeled. Show each request’s actual review state and distinct “Approved — not sent” / “Manually recorded as sent” text.

Editor sections are recipients, wording/deadline, attachments/disclosure and exceptions/signature. The exact saved preview appears beside the form on wide screens and below it on phones; make its saved state explicit so unsaved edits cannot be mistaken for approved content. Keep optional AI and prepared-copy controls secondary. No automatic vendor contact or live Send button.

Exact review includes full recipient/content/signature, shipment/revision, deadline, selected file identity/version/size/checksum/scan status and disclosure. Approval requires an explicit checkbox and revision-specific action. Prior/current payload differences, history, human approver and real audit entries remain visible. Historical approved vendor identity comes from the frozen snapshot even after directory edits.

Approved output uses selectable plain text and progressive copy actions, same-manifest authenticated downloads and a separate manual declaration form. Clipboard failure selects/focuses text and explains Ctrl+C/Command+C. Copy/download stays separate from communication status. Print CSS excludes navigation/actions; Blade renders an escaped text preview without a PDF dependency.

Desktop uses minmax columns and a compact secondary rail; phones stack cards/controls, wrap long addresses/references/checksums and use the existing navigation drawer. Server errors preserve inputs and receive keyboard focus; forms use unsaved-change warnings. Semantic badges/text supplement color. Desktop, 390px and 320px checks and screenshots are recorded in HANDOFF; no full assistive-technology audit is claimed. Approved downloads passed server/byte/checksum checks, while the QA browser canceled physical saving (including an independent synthetic file); verify saving in a normal browser before pilot.

## Phase 5 email-workspace patterns

Continue the Apple-inspired silver/white/graphite palette, restrained blue primary actions, amber attention and meaningful status text. Mail does not need decorative graphs or invented response/delivery rates. Directory health remains backed by real persisted counts.

Settings have one pinned company identity, supported personal/shared send mode, explicit administrator rights/size acknowledgment, a bounded import start and readable connection/folder recovery. Label every fixture. Incoming and inquiry queues default to real records, with separately selected fixture/all-labelled views. Counts and attention links follow their data selection. Preserve unknown/error/empty states; missing Microsoft setup links to Admin configuration while manual work remains available.

Final preview shows exact From, actual sender, Reply-To, one vendor's To/CC, approved subject/body, request/shipment revision and manifest. **Authorize envelope** is followed by the separate **Explicitly enqueue** action. Render a single primary action for the current step. Provider states use honest labels: accepted/observed have unconfirmed delivery/reading; uncertain directs reconciliation. Existing RFQ review/history/output cards show the actual dispatch label and cross-link, hiding conflicting manual-record actions.

Incoming messages use readable escaped text and optionally escaped original HTML source, without active links/images. Sender/recipients/thread IDs/dates/source digest and exact old-request links remain inspectable. Private attachments expose format/size/unscanned status and partial/unsupported/retry states. Staff classification/association requires a reason and records history; quote receipt does not approve prices. Timeline keeps Outlook, manually declared communication and fixture evidence visibly distinct.

Desktop uses minmax main/secondary panels. Phones stack them, wrap long subject/address/checksum/provider IDs, retain labelled native forms and private-source focus regions, and use the existing navigation drawer. No token strings, raw provider error bodies or signed upload URLs appear in screens. Recovery explains the effect before the explicit action; submitted mail cannot be recalled.

Phase 5 data consistency: overview inquiry metrics, review queue and inquiry activity follow the real-record default used by the inquiry workspace. A visible link keeps labelled test fixtures accessible. Fixture release/association cannot cross into real inquiry data; no empty real queue is filled with fabricated customer activity.


## Phase 6 quotation review and comparison patterns

Keep the existing Apple-inspired silver canvas, white surfaces, graphite text and blue actions. Comparison uses readable vendor/alternative cards and tabular costs; it does not add a generic chart, invented vendor score or AI winner. Display known subtotal, reviewed baseline cost, lowest comparable cost, outside-ranking reasons and provisional/final state in text as well as color. Optional cost is separate; original and indicative comparison currencies stay labelled.

Commercial review uses numbered scope, quantity, charge, currency and terms sections with a source panel alongside at wide widths. Small screens stack source evidence before the form with a visible source anchor. Private originals, extracted row/page locators, immutable versions and actual review authors remain accessible. Calculations use a keyboard-focusable controlled scroll region. Charge-row add/remove retains stable source-reference keys and unique field IDs. FX changing target clears confirmations and updates direction context; no conversion is guessed.

Explicit per-field proposal decisions create a draft. Separate human section/charge checkboxes are never prechecked from a previous revision. Inline errors, focusable error summary, persisted stale lock values and unsaved-change feedback protect corrections. Final/provisional actions use a keyboard-accessible native confirmation dialog that retains the submitted action. Saved cost-basis summary displays the frozen decision and eligibility checked against current facts.

Business preview has a persistent fictional-data banner, plus permanent sample labels on client/vendor/inquiry/email records. Professional names and plausible rates improve realism without implying real operations. Legacy immutable originals and audits keep their historical wording. Source evidence and all vendor costs stay staff-only; public intake never displays comparison or commercial amounts.

## Phase 7 pricing and client document patterns

Retain the Apple-inspired silver canvas, white surfaces, graphite type and restrained blue actions. The inquiry's Client quotation tab groups source selection, customer line descriptions, explicit markup/tax, contacts/terms and editable email into four numbered sections. The saved selling total and estimated private economics sit alongside the editor on desktop; phones stack panels with wrapped references and readable native inputs. Mark saved values clearly, and reset human confirmations for every edit.

Save draft, save for review, approve exact quotation and explicitly enqueue are separate steps. Review places the exact private PDF and email beside staff-only pricing/readiness; show actual sender/Reply-To and the one-file checksum manifest. Revision links retain superseded content, author/time, reason and linked resend evidence. Empty source selection links to vendor review. Visible validation/gaps preserve manual drafting when credentials are absent. No invented analytics, AI winner or realized-revenue chart is added.

The customer PDF uses a dedicated A4 Blade template, local DejaVu Sans and restrained blue/graphite styling, not browser Tailwind. Repeated company monogram/ref/revision headers and numbered footers, wrapping identity/service descriptions, separate optional prices, a prominent blue selling total and booking qualification support predictable short/multi-page output. Customer projection excludes internal financial/evidence data. Exact bytes are saved privately and reused for review, download and sending.

## Phase 8 follow-up and attention patterns

Reuse the Apple-inspired silver canvas, quiet white panels, graphite typography, blue primary actions and curated outline icons. Status text distinguishes Approved automatic, Review each reminder, Active, Held, Paused, Stopped, Cancelled, Exhausted, Accepted and Delivery unconfirmed. Color supplements these labels. No reminder-response graph is justified without real operational data.

The RFQ/client quotation panel summarizes approved mode/policy, cumulative count, next date, latest response and stop reason. Full activation pairs the exact actual envelope and immutable original baseline with every rendered message, projected date, working calendar, sync threshold, optional manifest, expiry and explicit approval. An accepted reminder links to its exact message/outbox evidence. A held plan explains the next human action; it does not imply automatic resumption. Manual review uses a distinct exact-message approval screen.

Attention cards show the responsible staff member, source evidence, concrete next action and separate assignment/resolution forms. Each field has a unique ID even across repeated task cards. Resolution requires a reason and explicitly does not resume reminders. Useful no-task/no-plan/disabled-policy states retain recovery/navigation links.

Desktop uses existing minmax main/secondary columns. Phones stack surfaces, wrap long addresses/references and retain labelled native fields, visible focus, skip navigation and the established drawer. Activation and pause/cancel reasons use distinct IDs. The Phase 8 handoff records actual browser and responsive verification; no full assistive-technology audit is claimed.


## Phase 9 connections and provider context

Retain the Apple-inspired silver canvas, white panels, graphite type, blue actions and curated icons. Use small red Gmail and blue Outlook badges with provider text; keep default, incoming paused, fictional and connected/health states explicit. Multiple connection cards belong to one workspace. Show verified From identities, actual approved sender/Reply-To and a concise explanation that existing messages stay on their authorized mailbox.

Connection setup uses labelled native controls, visible no-credentials states and an explicit Admin rights/import-boundary confirmation. Raw Google IDs remain in technical evidence only where staff needs them; tokens and secrets never appear. Preview/history/outbox/attention reuse existing forms and empty/error/success states. Desktop separates main controls from setup guidance; phones stack and wrap long emails/references. No chart is justified by fictional mail activity. Actual responsive inspection and remaining accessibility checks are recorded in the Phase 9 handoff.


## Phase 10 decision and operations patterns

Extend the Apple-inspired silver canvas, quiet white surfaces, graphite typography, blue actions and restrained green/amber states. Color accompanies explicit text. Current lifecycle cards distinguish Client decision, Vendor confirmation, Handoff and Booking; one next action and responsible agent lead. Keep older quotations collapsed and historical evidence accessible. Fictional records retain their banner. No operational chart is invented from fictional activity; reporting belongs to Phase 11.

Decision review places exact saved PDF/terms/total/revision and original response beside explicit staff confirmations. Vendor review compares native selected cost, shipment dates and actual capacity evidence, with a concise editable message and separate approvals. Checklist items show Complete, Missing, Conditional or Not applicable with reason/source. Policy belongs to Admin; operational evidence and actual events belong to authorized staff. Original vendor booking emails are named selectable evidence rather than raw IDs.

Desktop uses bounded main/secondary columns; phones stack panels and wrap long source/reference/contact text. Retain native labelled controls, visible errors with entered values, keyboard focus, skip link, private-file context and useful no-policy/no-confirmation/no-evidence states. Current blockers accompany preserved exports; approval and booking controls remain separate. The A4 private internal PDF uses restrained blue/graphite, repeated confidential reference headers, numbered footers, readable shipment/checklist rows and financial groups kept together across pages. See Phase 10 handoff for actual browser/PDF verification and remaining manual checks.


## Phase 11 reports and operational health

Preserve the existing structure, white panels, silver navigation, graphite typography and blue actions. Operational reports uses a compact receipt/filter panel, four defined metrics, one real status-count bar chart with accessible textual links, and current evidence tables. Avoid decorative charts, fake performance scores and an invented revenue dashboard. Fictional provenance, unknown values, native currencies, recorded versus estimated amounts and actual versus unconfirmed booking remain explicit.

Use the existing .table-wrap and .table components for report/health tables: internal horizontal scrolling, shared cell padding/headers, rounded edges and keyboard-focusable labelled regions. New pages were checked at 1440px, 390px and 320px; they do not expand the document width. Pass the already-loaded company timezone into timestamp display rather than performing settings queries for each row.

Health uses observed No observation / Recently observed / Stale observation, with timestamps and a five-minute attention threshold. Empty queues are not a green worker assertion. Outgoing pause has an amber workspace banner, human reason/acknowledgement, explicit success feedback and separate resume wording; use current business release gates after the control changes. Preserve uncertainty and historical evidence labels. Public and Agents never see Admin control actions.

Filters and recovery retain entered data on validation errors and use shared error summary/field messages. Desktop/mobile focus, menu Escape, meaningful empty states, real invalid-period errors and actual pause/resume feedback are recorded in [Phase 11 handoff](phase-11-handoff.md). Assistive technology, physical native date-picker and normal-browser file saving/printing remain company manual acceptance.


## Final workspace quality review

Preserve the established Apple-inspired light structure: quiet silver navigation, soft white surfaces, graphite type and restrained blue active states. Keep status colors alongside clear labels. Shared panels can shrink inside their grid without expanding the page; financial tables use tabular numbers and omit insignificant quantity zeroes without changing stored decimal calculations.

Every inquiry screen uses the same five-stage Shipment workflow component. Desktop stages wrap; phones show a compact two-column grid with the current stage clearly indicated. Supporting files, email and history use a separate disclosure. The primary Inquiries link stays active through sourcing, commercial review, pricing and handoff. Desktop navigation has its own viewport-height scroll so Profile and Sign out remain reachable; the mobile drawer retains Escape and focus behavior.

Compact paired inquiry summaries reduce phone scrolling. Historical revisions and health attention lists keep all evidence in bounded, keyboard-focusable regions. AI usage and cell provenance use the existing labelled, internally scrolling table components. Public information banners use the same quiet blue treatment.

Display each actual incoming mailbox/provider state independently from the new-work outbound default. Missing earlier source fields have explicit unknown/not-retained text; presentation fallbacks never rewrite immutable originals. New draft charge rows start blank with unchecked human confirmations, valid unique labels and cleared prior field errors. See the final review record in [Phase 11 handoff](phase-11-handoff.md#final-workspace-quality-review) for actual browser evidence and remaining manual checks.


## Guided shipment workflow · 5 October 2026

Use five numbered stages consistently: Shipment details, Ask vendors, Compare prices, Customer quote, Confirm & book. A state-aware next-step card leads the inquiry summary. It checks the confirmed shipment version, eligible cost basis, exact quotation approval/send and actual lifecycle evidence. Older prices, uncertain sending, handoff approval and actual booking remain distinct.

Default screens prioritize one action and a short purpose sentence. The daily sidebar shows Home, Inquiries, Email and Tasks & follow-ups; contacts/reports and Admin settings use labelled disclosures. Files, email and history are supporting inquiry tools. Historical reviews, technical evidence, optional configuration and long activity lists stay in native details elements. Missing checks open when relevant; entered error fields reveal their containing disclosure and form step.

Long forms use short named groups, numbered buttons, Back/Next and a draft action. Values remain in the same form DOM across steps, final submission uses existing server validation, and human confirmations are never inferred. All sections remain visible without JavaScript. Each charge has an expandable summary; new charges open with blank values and unchecked confirmations. Actual booking fields appear only for a confirmed-booking event. Keep the existing Apple-inspired silver/white/graphite/blue system, responsive stacking, bounded table scrolling, native controls and visible focus.

The shared How it works dialog explains the five stages, separates approval/sending/booking, and provides a collapsed glossary for logistics terms. The Home sample opens the current fictional inquiry; avoid pretending it starts with a blank shipment or completed booking. See the [usability verification and manual acceptance](phase-11-handoff.md#guided-shipment-workflow--5-october-2026). Earlier phase design entries above describe historical layouts; these five-stage rules take precedence for current navigation.
