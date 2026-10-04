# LRS — Build Baseline and Phase 1 Codex Prompt

Prepared 4 October 2026. This is the agreed MVP baseline; company samples, quotation terms, routes, billing responsibilities, and mailbox permissions still need validation before the pilot.

Paste the prompt below into Codex in the project repository. It directs implementation of Phase 1 only, while retaining the later workflow and design requirements.

## Complete prompt

You are my senior Laravel engineer and product designer. Build Phase 1 of LRS, a logistics inquiry and quotation web application. Implement the work in this repository; do not stop at a plan.

Read existing AGENTS.md and inspect the repository first. Preserve unrelated changes. If an application exists, extend it safely instead of replacing it. Explain genuinely blocking environment issues; make routine implementation decisions yourself.

BUILD PRINCIPLE
Every phase must deliver working functionality and polished UX together. Design each feature as it is implemented, including validation, empty, error, success, and permission states. Do not defer interface quality to a final redesign. Build only Phase 1 now.

STACK
- Laravel/PHP, Blade templates, Tailwind CSS, PostgreSQL.
- For a new application, use Laravel 13 and Tailwind 4 with supported, compatible PHP and PostgreSQL versions. Verify official documentation and environment compatibility; preserve an existing supported version unless an upgrade is necessary and explained.
- Use small, modular vanilla JavaScript for menus, dialogs, mobile navigation, and progressive enhancement. Server-rendered forms must work without JavaScript where practical.
- Vite/npm is build tooling only. Keep the application backend and business logic in PHP.
- Use Laravel's built-in facilities and first-party packages where appropriate: Eloquent, validation, policies, session authentication, queues, scheduler, notifications, storage, and Fortify for authentication if suitable.
- No React, Vue, Next.js, separate Node/Python backend, additional frontend framework, paid UI kit, or generic admin-template dependency.
- Use PostgreSQL for development and meaningful database tests. Do not silently substitute SQLite.
- Start as one company-operated application, not a multi-tenant SaaS. Keep dependencies minimal and documented. Use Laravel's database queue/session facilities initially where needed; Redis is not required.
- Use normal Laravel organization with thin controllers, Form Requests, policies, reusable Blade components, and small actions/services for substantial business operations. Avoid speculative abstractions and building future modules prematurely.

BUSINESS BASELINE FOR ALL PHASES
LRS serves three business participants: clients, the company's agents, and logistics vendors. Only staff need accounts in the initial releases. The company issues its own client quotation using a markup on reviewed vendor costs.

Initial service scope: general cargo sea freight, LCL and FCL. Pickup, delivery, clearance, and insurance are explicit requested services. Special cargo goes to a manual specialist-review path. Support other logistics modes later.

Full future workflow:
1. Receive a client email, form, or manually entered inquiry.
2. Preserve source messages/attachments, create a case reference, assign an owner and deadline.
3. Extract proposed shipment fields and document type; flag missing or conflicting information.
4. Agent confirms the shipment version or approves a clarification email.
5. Agent selects vendors from the company directory. AI may draft RFQs; an agent approves recipients, content, scope, and attachments.
6. Send each vendor a separate email. Track each vendor RFQ independently from the client case.
7. Ingest vendor replies; extract proposed costs and conditions for human review.
8. Compare equivalent shipment versions, scope, currency, units, minimum charges, inclusions, exclusions, validity, and transit estimates. Unknown cost must never become zero.
9. Agent selects an offer and sets markup or service fees. Application code performs decimal-safe arithmetic and distinguishes markup from gross margin.
10. Generate the client quote PDF from approved fields and a company template. Agent approves the exact quote revision and email.
11. Send, track acceptance/revision/decline/expiry, and record evidence.
12. Client acceptance requires rate/capacity reconfirmation and explicit booking authorization before a job handoff.

Permanent rules:
- Agent maintains and manually selects vendors. No automatic vendor selection in the MVP.
- Clients may provide no document, a packing list, commercial invoice, RFQ, or existing freight quote. Goods value, client budget, customer reference quote, vendor cost, and selling price are distinct.
- Extract usable digital PDF text first; OCR only pages that need it. Preserve table relationships and page/source references. Selective vision/manual review is a fallback.
- Extract structured fields before summarizing. Preserve originals; never invent missing information.
- Reuse unchanged extraction to control costs; record AI/OCR usage. Treat documents as untrusted data.
- AI proposes fields and text. It cannot authorize sending, set authoritative costs, or book shipments.
- Material changes after approval invalidate the affected approval. Preserve shipment and quotation revisions.
- Routine reminders may eventually run under an approved template/schedule. New wording, negotiations, or changed terms require approval. Stop reminders when a relevant response or closure arrives.
- Outlook is the first email connector; Gmail follows. Keep connectors replaceable, but do not promise universal provider support.
- Deduplicate messages/jobs and support mailbox catch-up. Reconcile uncertain send outcomes before retrying.
- Client documents must not expose vendor costs, markup, competing vendors, or internal notes.
- Store timestamps in UTC; display Asia/Kuala_Lumpur by default. Business-day reminder settings come later.

PRODUCT DESIGN
Build a professional logistics operations workspace, with a distinctive but restrained visual identity.
- Light theme first: warm off-white canvas, white surfaces, deep navy typography/navigation, teal primary actions, restrained semantic status colors.
- Use comfortable density, strong typography hierarchy, consistent spacing, subtle borders, moderate corner radii, and minimal shadows.
- Use a clean system sans-serif stack initially. Avoid runtime dependencies on remote fonts or icon CDNs.
- Create a simple LRS typographic wordmark and small geometric brand mark. Do not invent a client's official logo or claim client approval.
- Use one curated icon family: locally bundled Phosphor SVGs, preferably restrained duotone for navigation and regular weight for small actions. Preserve its license. Use recognizable symbols with text labels, not emojis, mixed icon styles, decorative trucks everywhere, or arbitrary novelty symbols.
- Desktop: stable sidebar, compact top bar, useful page heading/breadcrumbs, primary action, and readable content.
- Mobile: accessible navigation drawer, stacked forms, readable records, and no page-level horizontal overflow. Wide tables may scroll inside a clearly bounded table region.
- Forms: persistent labels, logical sections, examples where useful, inline errors, preserved values, and clear submit/cancel behavior.
- Lists: real server-side search, filters, pagination, status labels, and useful empty/no-results states. Do not display controls that do nothing.
- Dialogs: keyboard operation, sensible focus management, Escape to dismiss where appropriate, and focus restoration.
- Use accessible contrast, visible focus, meaningful headings, labeled icon-only actions, and text alongside status colors.
- Use restrained 120–200 ms feedback transitions and honor reduced motion. This is an operational app, so avoid cinematic scrolling and decorative animation.
- Build reusable Blade components for navigation, page headers, buttons, fields, badges, tables, alerts, empty states, and dialogs when actually needed.
- Never show invented production metrics or pretend disconnected integrations work. Put optional demo data behind an explicit development/demo seed command.

PHASE 1: FOUNDATION, STAFF ACCESS, AND VENDOR DIRECTORY
Deliver these complete vertical slices.

A. Project foundation
Configure Laravel, PostgreSQL, Tailwind/Vite, application settings, and a working local setup. Provide .env.example with placeholders only. Keep credentials out of source control. Commit dependency lockfiles where repository conventions allow. Do not deploy or connect real mailboxes.

B. Staff authentication and authorization
Implement polished login/logout and password reset using Laravel-supported authentication. Public self-registration is disabled.
Roles:
- Admin: manage staff accounts, vendor directory, and company settings.
- Agent: view/create/edit vendors and contacts, activate/deactivate vendors, and update their own profile.
Both roles share this company's vendor directory.
All authorization must be enforced on the server, including direct URL access.
Allow admin creation through a secure documented setup command with password prompting; do not ship production default credentials.
Admin can create staff, change role/active status, and initiate password setup/reset. Use a local mail capture/log transport in development and never display reset tokens in normal application pages or logs intended for users.
Inactive users cannot authenticate or continue privileged work through an existing session. Prevent removing/deactivating/demoting the last active admin.
Provide useful unauthorized states and rate-limit sensitive authentication actions.

C. Vendor directory
Implement working list, create, detail, edit, activate/deactivate, search/filter, and pagination.
Vendor fields:
- Company name and optional short/display name.
- Type: shipping line, freight forwarder, consolidator, transporter, or other.
- Services such as LCL/FCL and relevant additional services.
- Coverage/routes as simple structured tags or well-labeled text for Phase 1.
- Minimum shipment/charge notes, preferred communication channel, and internal notes.
- Active status.
Contacts are separate related records: name, optional role/phone, normalized email, and primary quotation contact flag.
Allow multiple contacts; enforce at most one primary quotation contact. Clearly flag vendors without a usable quotation contact. Such vendors can be saved but will not be eligible for outreach in a later phase.
Validate emails on the server; syntax validity does not prove ownership or deliverability. Warn about likely duplicate companies without blocking legitimate branches. Prevent duplicate contact emails within the same vendor.
Deactivate instead of destructive deletion. Preserve history and explain that inactive vendors will be excluded from future outreach selection.
Show a detail page with key capabilities, contacts, notes, status, and recent changes.

D. Overview and account/settings screens
Provide an honest Phase 1 overview using real available data: active vendors, vendors missing a quotation contact, and recent changes. Include useful setup guidance.
Implement profile name/password updates and admin company settings: display name, default timezone, and preferred display currency. Currency preference must not imply automatic exchange-rate conversion.
Show only functional primary navigation. Describe future capabilities in the roadmap documentation instead of adding dead navigation links.

E. Audit trail
Record actor, action, record reference, timestamp, and relevant sanitized changes for staff administration and vendor/contact/status updates. Do not record passwords, reset tokens, or secrets.
Application users cannot edit audit entries. Audit changes and their business mutation should remain consistent through database transactions where appropriate.
Display a readable activity history where useful. Do not describe it as tamper-proof against database administrators.

PHASE 1 COMPLETION CHECKS
- New setup can migrate a PostgreSQL database, create an admin securely, build assets, and run from the README.
- Admin logs in, creates an agent, creates a vendor with contacts, edits it, searches/filters it, and deactivates/reactivates it.
- Agent performs allowed vendor actions but cannot access or mutate admin staff/company settings through direct requests.
- Inactive staff access is blocked; last-admin protection works.
- Validation, primary contact constraints, empty states, pagination, and audit behavior work.
- Meaningful authentication/authorization and data-integrity tests pass against a separate PostgreSQL test database. Add no snapshot tests that merely mirror markup.
- Run appropriate formatting and frontend production build checks.
- Open the actual app in a browser when tooling is available. Verify login, vendor list/detail/form at desktop and mobile widths; check keyboard access, menus/dialogs, console errors, and clipping. Correct visual issues before finishing.
- If execution or browser tools are unavailable, clearly distinguish checks actually run from manual checks still required. Never claim verification that did not happen.

PROJECT CONTINUITY
Create/update:
- README.md: prerequisites, installation, PostgreSQL/test setup, asset build, secure admin setup, mail capture, and local run commands.
- docs/PROJECT_BRIEF.md: agreed workflow, scope, permanent business rules, assumptions, and decisions still needing company input.
- docs/DESIGN_SYSTEM.md: colors, typography, spacing, component conventions, icons, and interaction/accessibility rules.
- docs/PHASES.md: the roadmap below and completion status/evidence.
- docs/HANDOFF.md: completed work, key files, commands run/results, known gaps, and the next phase.

ROADMAP
1. Foundation, staff access, vendor directory, and visual system.
2. Clients and manual inquiries: shipment forms, ownership, deadlines, private attachments, review/clarification workflow, shipment revisions.
3. Document extraction: digital text, OCR fallback, AI structured proposals, source evidence, review, and usage accounting.
4. Vendor RFQ preparation: manual selection, editable drafts/templates, revision-bound approvals, and outbound preview.
5. Outlook integration: mailbox setup, approved sends, inbound inquiries/replies, deduplication, matching, catch-up, and failure recovery.
6. Vendor quotations: structured offer entry/extraction review, equivalent-scope comparison, clarification, and selection.
7. Client quotations: markup/fees, precise calculations, PDF rendering, revisions, approval, and approved sending.
8. Approved reminders and escalation: business-day schedules, cancellation, reply checks, and operational exception queue.
9. Gmail connector with the same workflow and verified provider-specific behavior.
10. Client acceptance/revisions/expiry and explicit booking/job handoff.
11. Operational reporting, production hardening, end-to-end pilot, backup/restore verification, and deployment preparation.

Use the roadmap as context only. Do not implement later phases, dormant integration code, or their entire database schema during Phase 1.

FINAL HANDOFF
Finish Phase 1 and stop. Report:
1. What works, with actual routes and screenshots if available.
2. Important implementation decisions and dependencies.
3. Commands/checks run and their results.
4. Any actual blocker or limitation.
5. A short manual acceptance checklist.
6. What Phase 2 will add.
Do not claim production readiness or that the entire project is complete.

## Official references checked

- Laravel releases: https://laravel.com/framework/docs/releases
- Laravel Fortify: https://laravel.com/framework/docs/fortify
- Tailwind with Laravel/Vite: https://tailwindcss.com/docs/installation/framework-guides/laravel/vite
- Phosphor SVG assets and license: https://github.com/phosphor-icons/core
- OpenAI file-input behavior: https://developers.openai.com/api/docs/guides/file-inputs

