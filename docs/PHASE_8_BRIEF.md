Implement Phase 8 of LRS: human-approved follow-up policies, automatic cancellation and staff escalation.

Recommended Codex settings: GPT-6.1 Sol, Extra High reasoning (xhigh). Select these in Codex before running this prompt; the prompt cannot change the model. If unavailable, use GPT-6 Sol with Extra High reasoning. These settings concern development, not the application's AI API.

Run after Phase 7's relevant checks and handoff. Work in the existing repository, implementing design and functionality together.

1. Read and reuse the actual contracts

Read AGENTS.md, project documentation and Phase 5–7 handoffs. Inspect actual RFQ/quotation revisions, approved envelopes, send outcomes, incoming classifications, eligibility checks, queue/scheduler, policies and audit events. Run relevant existing checks and fix necessary prerequisites.

Keep Laravel/PHP, Blade, Tailwind CSS, PostgreSQL and small vanilla JavaScript. Reuse the current design system and mail outbox. Do not introduce a new workflow platform, mandatory Redis service or AI agent that controls sending.

LRS is a single-company Admin/Agent app. Agents retain control of vendor selection and commercial decisions. Email, public-form and manual inquiry sources remain supported. Clients/vendors do not need accounts.

2. Scope and approval model

Support follow-ups for vendor RFQs awaiting a meaningful response and client quotations awaiting a decision. Use predictable templates and schedules. Build an attention queue when automation cannot safely continue or the permitted attempts are exhausted.

All automation starts disabled. Admin configures/version-controls company policy defaults; an authorized agent explicitly activates a policy for an exact vendor RFQ or client quotation revision. Activation must show and approve the recipients/envelope, template wording, permitted placeholders, attachments if any, schedule, maximum sends, expiry and stop conditions.

This activation authorizes only the bounded follow-ups described by that frozen policy. It does not authorize arbitrary AI-written messages. Material edits require new approval and cancel incompatible pending jobs. Preserve cumulative attempt counts when editing a policy; editing must not reset a sending cap.

Allow manual review for each follow-up as an alternative. Choosing that mode creates a review task/draft at the due time and sends only after explicit approval. Clearly distinguish it from approved automatic mode.

Do not add Gmail, bookings, payments, marketing campaigns or automatic negotiation. Do not change prices, quote validity or shipment terms in a reminder.

3. Scheduling that staff can understand

Use Laravel's existing scheduler and queued jobs. Persist reminder plans, stages, due times, policy versions, approval evidence and outcomes in PostgreSQL. Use UTC storage with calculations/display in the configured company timezone; use Asia/Kuala_Lumpur only as a proposed default if none exists.

Support business-day intervals, permitted sending hours, working weekdays and optional Admin-entered holiday dates. A disabled starter example may suggest first follow-up after two business days, a second three business days later and a maximum of two; these are editable examples requiring company approval, not universal logistics rules.

Show calculated dates before activation. Start intervals from the known submitted/accepted send event, clearly distinguishing it from delivery. Outcome-uncertain dispatches must not start automation. Manually recorded sends can participate only through explicit activation with their declared date/recipient evidence visible.

Compute each next stage from the actual preceding send event under the approved rule. After downtime, do not send several overdue stages together. Allow at most one eligible stage, then schedule the next normally. Never extend a quotation's validity to fit a reminder.

4. Cancellation, holds and escalation

Cancel or stop relevant vendor reminders when a substantive quote, question or decline arrives for that request. A question routes work to staff; it does not justify continued “no response” emails. Record the stop reason and let staff intentionally approve a new plan if appropriate.

An out-of-office reply is not a commercial response. Pause for review, or defer under an explicitly approved rule with supported return-date evidence. Bounces stop sending to that address and create a contact-review task. Do not automatically choose another recipient.

For client quotations, stop on an acceptance, decline, revision request or meaningful question associated with that exact revision. Phase 10 formalizes these outcomes; until then, reviewed response evidence must already be able to stop follow-ups without pretending a booking occurred.

Also stop or hold for expired/superseded quotes, changed shipment/selection, held/closed inquiries, inactive contacts, changed sender identity, disconnected mailboxes and revoked authorization. Pause when recent incoming sync is unhealthy or relevant reply processing is incomplete; do not follow up while known replies wait to be imported or reviewed.

Potentially related unmatched replies need staff triage before an affected reminder proceeds. Do not infer acceptance/decline from an AI label alone. Preserve the original evidence and classification review history.

When the cap is reached, show “No response after approved follow-ups” and create an internal staff task. Do not automatically close the case, select another vendor, discount the quotation or send indefinitely. Escalation uses the existing internal app notification/task pattern; external notifications need explicit configuration/authorization.

5. Idempotent execution and dispatch

Use database uniqueness and transactional claiming for each logical reminder stage. Scheduler overlap protection alone is insufficient. Dispatch jobs only after committed records exist. Bound retries, lock duration and failure recovery; do not keep database transactions open over provider calls.

Immediately before provider submission, recheck authorization, current parent revision, eligibility/expiry, response/hold state, contact/envelope, sync health, sending window and cumulative cap. Account for replies/cancellation arriving while a job is preparing. Clearly record the point after which a submitted message cannot be unsent.

Render only approved placeholders from frozen trusted data, then preserve exact content/recipient/attachment/envelope evidence per reminder. Replies should stay in the relevant thread when supported. Do not attach vendor commercial files to customer reminders. Client PDF attachments, if approved, must use the exact approved version/checksum.

Send through Phase 5's outbox and uncertain-outcome reconciliation. Duplicate jobs must not send twice. An ambiguous submission blocks the next stage and requires reconciliation; it must not be bypassed as a failed attempt. Separate submission-attempt retries from approved follow-up send counts.

6. Design and control

Add a readable follow-up panel to RFQs/client quotations: mode, approved policy, next date, attempts, latest response, stop reason and activation/pause/cancel controls. Provide a preview of the exact messages and schedule before activation.

Create an attention queue for questions, missing contact details, bounces, expired offers, unmatched replies, exhausted policies and uncertain sends. Give each task a clear owner, link to source evidence and next action. Use existing assignment rules without inventing a new role system.

Reuse professional components, typography and curated SVG icons. Use text labels for all statuses. Provide responsive layouts, accessible focus/keyboard behaviour, validation and useful empty/error states. Avoid exposing queue/provider internals as the normal staff workflow.

7. Verify and hand off

Use time-controlled tests for business-day/hour boundaries, holidays, timezone/expiry, duplicate scheduler runs, concurrent jobs, late replies, question/decline/OOO/bounce classification, parent revision changes and disconnected mailboxes.

Prove no mail is sent while disabled, activation is required, the exact approved schedule/template is honoured, caps survive policy edits, overdue stages do not burst after downtime and uncertain sends block further reminders. Verify manual-review mode never sends automatically.

Exercise the browser flow: preview/activate a policy, advance fixture time, inspect one reminder, import a response and verify later stages stop. Use provider fixtures or explicitly authorized test mailboxes, not real client/vendor addresses.

Run relevant tests, formatting checks and production asset build. Document policy defaults, timezone rules, stop/hold conditions, freshness thresholds, scheduler/worker commands and recovery procedures. Consult Laravel scheduling/queue documentation for the repository's installed version.

Update the existing handoff location or create docs/phase-8-handoff.md. Define the reminder/response hooks Phase 9's Gmail adapter and Phase 10's outcomes must reuse. Report remaining live-provider checks honestly.

Stop after Phase 8. Phase 9 adds Gmail while preserving these approval and dispatch rules. Recommended settings: GPT-6.1 Sol, Extra High reasoning.