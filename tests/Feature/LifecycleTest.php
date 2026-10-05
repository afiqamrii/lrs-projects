<?php

namespace Tests\Feature;

use App\Actions\MailOutbox;
use App\Actions\ManageLifecycle;
use App\Actions\ManageOperationalMail;
use App\Actions\ManageQuotation;
use App\Jobs\DispatchMail;
use App\Models\ClientDecision;
use App\Models\HandoffApproval;
use App\Models\VendorReconfirmation;
use App\Support\HandoffPdf;
use App\Support\LifecycleEligibility;
use App\Support\MailRelease;
use App\Support\Processing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    use QuotationFixture, RefreshDatabase, \Tests\Fixtures\LifecycleFixture;

    public function test_current_manual_acceptance_is_exact_idempotent_and_does_not_book_or_send(): void
    {
        $q = $this->quote();
        $input = $this->decisionInput();
        $a = app(ManageLifecycle::class);
        $d = $a->decision($q, $this->staff, $input);
        $this->assertSame('accepted', $d->outcome);
        $this->assertSame($d->id, $a->decision($q, $this->staff, $input)->id);
        $this->assertSame(1, ClientDecision::count());
        $this->assertSame(1, VendorReconfirmation::count());
        $this->assertSame('Booking unconfirmed', LifecycleEligibility::statuses($this->case)['booking']);
        $this->assertDatabaseCount('mail_dispatches', 0);
        $this->assertDatabaseCount('handoff_events', 0);
        $this->get(route('lifecycle.index', $this->case))->assertOk()->assertSee('Accepted');
        $this->get(route('lifecycle.decision', [$this->case, $q]))->assertOk()->assertSee('Commercial terms');
        $this->get(route('followups.index'))->assertOk()->assertSee('Reconfirm vendor');
        $this->blocked(fn () => $a->decision($q, $this->staff, array_replace($input, ['notes' => 'Different evidence'])), 'different evidence');
    }

    public function test_conditional_ambiguous_superseded_and_expired_evidence_is_retained_for_review(): void
    {
        $q = $this->quote();
        $d = app(ManageLifecycle::class)->decision($q, $this->staff, $this->decisionInput(['conditional' => true]));
        $this->assertSame('review_required', $d->outcome);
        $this->assertSame(0, VendorReconfirmation::count());
        $this->blocked(fn () => app(ManageLifecycle::class)->decision($q, $this->staff, $this->decisionInput()), 'decision changed');
        $d2 = app(ManageLifecycle::class)->decision($q, $this->staff, $this->decisionInput(['expected_decision' => $d->id, 'corrects_id' => $d->id, 'identity_confirmed' => false]));
        $this->assertSame('review_required', $d2->outcome);
        $this->assertSame($d->id, $d2->corrects_id);
        app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput(1));
        $d3 = app(ManageLifecycle::class)->decision($q, $this->staff, $this->decisionInput(['expected_decision' => $d2->id, 'corrects_id' => $d2->id]));
        $this->assertSame('review_required', $d3->outcome);
        $this->assertSame('Superseded', LifecycleEligibility::outcome($q));
        $this->travel(60)->days();
        $latest = LifecycleEligibility::quote($this->case);
        $d4 = app(ManageLifecycle::class)->decision($latest, $this->staff, $this->decisionInput());
        $this->assertSame('review_required', $d4->outcome);
        $this->assertSame('Expired', LifecycleEligibility::outcome($latest));
        $this->assertSame(4, ClientDecision::count());
    }

    public function test_vendor_price_dates_conditions_and_acknowledgement_hold_release(): void
    {
        $q = $this->quote();
        $d = $this->accept($q);
        $r = VendorReconfirmation::where('client_decision_id', $d->id)->firstOrFail();
        $this->travel(2)->seconds();
        $a = app(ManageLifecycle::class);
        $changed = $a->confirmation($r, $this->staff, $this->vendorInput($r, ['rate_total' => '1400']));
        $this->assertSame('changed', $changed->status);
        $this->assertFalse(LifecycleEligibility::readiness($this->case)['ready']);
        $pending = $a->confirmation($r, $this->staff, $this->vendorInput($r, ['capacity_confirmed' => false]));
        $this->assertSame('pending', $pending->status);
        $conditional = $a->confirmation($r, $this->staff, $this->vendorInput($r, ['conditions' => 'Subject to equipment release.']));
        $this->assertSame('conditional', $conditional->status);
        $date = $a->confirmation($r, $this->staff, $this->vendorInput($r, ['available_date' => now()->addMonths(6)->toDateString()]));
        $this->assertSame('changed', $date->status);
        $this->assertSame('1300.00000000', $r->selection->revision->complete_total);
        $this->get(route('lifecycle.vendor', [$this->case, $r]))->assertOk()->assertSee('Reconfirmation message');
    }

    public function test_handoff_and_actual_booking_are_separate_private_immutable_steps(): void
    {
        [$q,$d,$r,$h] = $this->ready();
        $this->assertSame([], LifecycleEligibility::revisionReasons($h));
        $this->get(route('lifecycle.handoff', $this->case))->assertOk()->assertSee('Approve exact handoff');
        $a = app(ManageLifecycle::class)->approveHandoff($h, $this->staff, $h->digest);
        $this->assertSame($a->id, app(ManageLifecycle::class)->approveHandoff($h, $this->staff, $h->digest)->id);
        $this->assertSame('Handoff approved', LifecycleEligibility::statuses($this->case)['handoff']);
        $this->assertSame('Booking unconfirmed', LifecycleEligibility::statuses($this->case)['booking']);
        $this->travel(2)->seconds();
        $ops = ['action_key' => (string) Str::uuid(), 'kind' => 'handed_to_operations', 'occurred_at' => now()->toIso8601String(), 'notes' => 'Internal operations owner received the approved simulated handoff.'];
        app(ManageLifecycle::class)->event($a, $this->staff, $ops);
        $booking = ['action_key' => (string) Str::uuid(), 'kind' => 'booking_confirmed', 'occurred_at' => now()->toIso8601String(), 'notes' => 'Fictional external vendor booking evidence reviewed.',
            'vendor_reference' => 'SIM-OCEAN-1025-093', 'contact_id' => $r->fresh()->current()->contact_id, 'pickup_date' => $this->case->shipment['cargo_ready_date'], 'arrival_date' => $this->case->shipment['arrival_date'], 'scope_confirmed' => true];
        $e = app(ManageLifecycle::class)->event($a, $this->staff, $booking);
        $this->assertSame($e->id, app(ManageLifecycle::class)->event($a, $this->staff, $booking)->id);
        $this->assertSame('Booking confirmed', LifecycleEligibility::statuses($this->case)['booking']);
        $this->assertStringStartsWith('%PDF', HandoffPdf::bytes($a));
        $this->get(route('lifecycle.pdf', [$this->case, $h]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->staff)->post(route('settings.handoff.save'), ['expected_revision' => 1, 'reason' => 'Agent cannot set company requirements'])->assertForbidden();
        $this->staff->update(['is_active' => false]);
        $this->get(route('lifecycle.pdf', [$this->case, $h]))->assertRedirect();
        $this->assertSame(1, HandoffApproval::count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_missing_requirements_freshness_and_material_changes_revoke_current_release(): void
    {
        [$q,$d,$r,$h,$e] = $this->ready(['freshness_hours' => 1, 'require_po' => true, 'require_deposit' => true]);
        $this->blocked(fn () => app(ManageLifecycle::class)->approveHandoff($h, $this->staff, $h->digest), 'Client reference');
        $this->assertSame(0, HandoffApproval::count());
        $this->travel(2)->hours();
        $this->assertStringContainsString('freshness', implode(' ', LifecycleEligibility::readiness($this->case, $e)['blockers']));
    }

    public function test_approved_vendor_mail_uses_shared_outbox_and_stale_booking_is_blocked(): void
    {
        [$q,$d,$r,$h] = $this->ready();
        $life = app(ManageLifecycle::class);
        $mail = app(ManageOperationalMail::class);
        $a = $life->approveHandoff($h, $this->staff, $h->digest);
        $this->travel(2)->seconds();
        $life->event($a, $this->staff, ['action_key' => (string) Str::uuid(), 'kind' => 'handed_to_operations', 'occurred_at' => now()->toIso8601String(), 'notes' => 'Fictional handoff received by operations.']);
        $data = $mail->defaults($h) + ['reason' => 'Explicit simulated booking instruction, no real booking.'];
        $data['reason'] = 'Explicit simulated booking instruction, no real booking.';
        $m = $mail->save($h, $this->staff, $data);
        $this->assertStringNotContainsString('PRIVATE PROFIT', $m->content['body']);
        $this->assertSame([], $m->content['manifest']);
        $approval = $mail->approve($m, $this->staff, Processing::hash($mail->preview($m, $this->staff)));
        $source = app(MailRelease::class)->source('operations', $approval->id, $this->staff);
        $e = app(MailOutbox::class)->authorize('operations', $approval->id, $this->staff, Processing::hash(app(MailRelease::class)->preview($source, $this->connection)));
        $dispatch = app(MailOutbox::class)->enqueue($e, $this->staff, (string) Str::uuid(), $e->digest);
        $life->confirmation($r, $this->staff, $this->vendorInput($r, ['rate_total' => '1400']));
        (new DispatchMail($dispatch->id))->handle();
        $this->assertSame('failed', $dispatch->fresh()->status);
        $this->assertNull($dispatch->fresh()->submission_started_at);
        $this->assertSame('Release on hold', LifecycleEligibility::statuses($this->case)['handoff']);
        $this->assertSame('Booking unconfirmed', LifecycleEligibility::statuses($this->case)['booking']);
        $this->get(route('lifecycle.pdf', [$this->case, $h]))->assertOk();
    }

    public function test_revision_decline_and_correction_preserve_history_and_never_close_inquiry(): void
    {
        $q = $this->quote();
        $a = app(ManageLifecycle::class);
        $d = $a->decision($q, $this->staff, $this->decisionInput(['outcome' => 'revision_requested', 'change_scope' => 'shipment', 'notes' => 'Client asks for changed delivery scope; renewed shipment review required.']));
        $this->assertSame('Revision requested', LifecycleEligibility::outcome($q));
        $this->assertDatabaseHas('attention_tasks', ['client_quotation_revision_id' => $q->id, 'kind' => 'client_revision_requested']);
        $no = $a->decision($q, $this->staff, $this->decisionInput(['expected_decision' => $d->id, 'corrects_id' => $d->id, 'outcome' => 'declined', 'decline_reason' => 'timing']));
        $this->assertSame('Declined', LifecycleEligibility::outcome($q));
        $this->assertSame('ready_for_sourcing', $this->case->fresh()->status);
        $this->assertSame($d->id, $no->corrects_id);
    }
}
