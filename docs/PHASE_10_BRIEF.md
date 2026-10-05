Implement Phase 10 of LRS: client quotation outcomes, vendor reconfirmation and a controlled booking handoff.

Recommended Codex settings: GPT-6.1 Sol, Extra High reasoning (xhigh). Select these in Codex before running this prompt; the prompt cannot change the model. If unavailable, use GPT-6 Sol with Extra High reasoning. These settings concern development, not the application's AI API.

Run after Phase 9's relevant checks and handoff. Work in the existing repository, completing design and functionality together.

1. Inspect the actual lifecycle

Read AGENTS.md, project documentation and Phase 6–9 handoffs. Inspect confirmed shipment versions, selected offer/cost snapshots, client quotation revisions, incoming match/classification, approved sends, reminder cancellation, policies and audit history. Run relevant existing checks and reuse these contracts.

Keep Laravel/PHP, Blade, Tailwind CSS, PostgreSQL, small vanilla JavaScript and existing queue/scheduler/private document infrastructure. Reuse the professional design system. Do not add a client portal, separate backend or vendor booking API as a prerequisite.

LRS is one company's Admin/Agent app. Clients/vendors interact through existing email and request-form channels. Agents own commercial decisions and handoff approval. AI may suggest summaries but cannot accept, renegotiate or book.

2. Client decisions tied to exact quotations

Support Awaiting Decision, Accepted, Revision Requested, Declined, Expired and Superseded outcomes linked to the exact client quotation revision. Distinguish a client's commercial decision from quotation submission/delivery and from an actual booking.

Create a response-review task from matched incoming mail or let staff record a phone/offline decision with date, contact, channel and evidence/note. Preserve original messages and source identity. An AI label or the word “yes” does not automatically authorize acceptance.

Show the exact PDF/terms/total/revision beside the response. Require an authorized staff member to confirm the outcome and its scope. Ambiguous identity, conditional acceptance or acceptance of an old/superseded/expired quotation requires review. Record such evidence without treating it as acceptance of the current quote.

Capture client references/PO documents when supplied, decision time, reviewer and reason/notes. Do not require a PO universally; prerequisites depend on the company's confirmed workflow. Corrections retain audit/history and must not erase a previously recorded outcome.

Any substantive decision/question stops the corresponding Phase 8 follow-ups promptly. Final staff review and reminder cancellation are related but distinct: cancellation need not wait for the commercial outcome to be finalized.

3. Revision and decline paths

A revision request creates an actionable task and a new draft path; never edit an already approved/sent quotation in place. Determine whether only wording changed or shipment/service/cost assumptions changed, showing affected readiness checks.

Material shipment changes go through the existing confirmation gate, sourcing/offer review and selection before new client pricing. Invalidated approvals and pending reminders stop. Preserve old quotation PDFs, correspondence and reasons, and link the replacement revision.

Record declines with an optional structured reason plus notes. Closing an inquiry requires an explicit staff action and closure reason, not merely an email parser label. Permit controlled reopening with audit and renewed eligibility checks.

4. Vendor rate/capacity reconfirmation

After a confirmed client acceptance, create a vendor-reconfirmation task linked to the accepted quote, selected offer and confirmed shipment. Client acceptance alone must not mark a shipment booked.

Provide a concise reconfirmation draft covering reference, shipment/equipment, requested dates, quoted rate/charges, validity, service scope and required availability/conditions. Staff approves exact content, recipient/envelope and attachments before sending through the shared outbox. No automatic booking instruction is sent from an acceptance event.

Record the vendor's actual confirmation evidence, rate/scope agreement, available dates/capacity, outstanding conditions, confirmation time and staff review. Show Pending, Confirmed, Conditional, Changed or Unavailable statuses. Estimates or an acknowledgement are not capacity confirmation.

If rates, capacity, dates or scope changed, place the handoff on hold and route the difference to staff. Material commercial changes require an updated client quotation/approval and renewed client decision. Do not absorb a changed price automatically or silently replace the accepted version.

Use a configurable reconfirmation freshness/expiry rule where appropriate; do not invent a universal logistics validity period. Recheck it at handoff approval. Preserve all evidence and previous confirmation versions.

5. Handoff readiness and approval gate

Build a simple configurable checklist with practical fields: accepted current quotation, matching confirmed shipment, reviewed vendor confirmation, pickup/delivery contacts and addresses, cargo readiness, required shipment documents, operational owner and applicable company prerequisites.

Requirements must depend on the confirmed service and company configuration. Payment/deposit confirmation can be a manually evidenced prerequisite if required, but do not build payments or prescribe a deposit. Do not invent customs/legal-document requirements or mark missing documents as received.

Show each item as complete, missing, conditional or not applicable with evidence/reason. Missing prerequisites block “Approve handoff.” A generic override must not bypass unresolved price/scope/client acceptance; document permitted operational exceptions explicitly with authority and reasons.

An authorized agent approves a frozen handoff snapshot containing the accepted quote, selected cost basis, shipment, vendor confirmation, checklist evidence, contacts, owner and approval time. Recheck current eligibility and prevent concurrent stale approvals.

Material changes after approval invalidate current handoff release and require a new handoff revision/approval, preserving the old snapshot. Any queued booking instruction must recheck this handoff gate immediately before submission.

6. Handoff versus booking status

Generate a professional internal handoff summary/export using existing document infrastructure. Keep vendor cost and profit private. Explicitly approve any customer/vendor-facing message or shared attachment subset; never forward the whole internal record automatically.

Track Handoff Ready, Handoff Approved and Handed to Operations separately from Booking Requested and Booking Confirmed. A handoff approval is not proof that the vendor booked capacity.

Allow staff to record an externally completed booking with vendor booking reference, confirmed dates, evidence and recorder/time. If staff sends a booking instruction through this app, require an explicitly approved message via the existing mail workflow and wait for vendor booking evidence before confirming. Do not add vendor API automation.

7. Design and usability

Extend the inquiry workspace with clear Client Decision, Vendor Confirmation and Handoff panels. Show one current next action, blockers, responsible staff and relevant source links. Present a compact lifecycle timeline without mixing approval, sending, acceptance and booking into one status.

Provide focused response-review, reconfirmation comparison and readiness screens. Reuse accessible components, professional typography and curated SVG icons. Add responsive layouts, clear validation, version context, empty/error states and safe confirmation for material transitions.

8. Verify, document and stop

Test current versus old/expired quotation acceptance, ambiguous/conditional responses, manual evidence, duplicate reply processing, revisions, declines/reopening and reminder cancellation. Test reconfirmed versus changed/unavailable offers, missing prerequisites, stale confirmations, concurrent approvals and private handoff access.

Exercise the full browser path: send a fixture quotation, capture/review acceptance, request vendor reconfirmation, review evidence, complete checklist and approve handoff. Verify booking remains unconfirmed until explicit vendor evidence is recorded. Also exercise a rate change requiring a revised client quote.

Run relevant tests, formatting checks and production asset build. Use fictional data or explicitly authorized test mailboxes; do not create real bookings as tests. Document configured prerequisites, authority, freshness rules, exceptions and external/live checks still unverified.

Update the existing handoff location or create docs/phase-10-handoff.md. Define outcome timestamps, closure/reopen behaviour, readiness/booking distinctions and events for Phase 11 reporting.

Stop after Phase 10. Phase 11 covers reporting, pilot verification and production readiness. Recommended settings: GPT-6.1 Sol, Extra High reasoning.