<?php

namespace Tests\Feature;

use App\Actions\MailIngest;
use App\Actions\MailOutbox;
use App\Actions\ManageFollowups;
use App\Actions\ManageLifecycle;
use App\Actions\ManageOffer;
use App\Actions\ManageOperationalMail;
use App\Actions\ManageQuotation;
use App\Actions\TransitionInquiry;
use App\Jobs\DispatchMail;
use App\Models\AttentionTask;
use App\Models\ClientDecision;
use App\Models\HandoffEvent;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\MailboxFolder;
use App\Models\MailMessage;
use App\Models\User;
use App\Models\VendorReconfirmation;
use App\Support\LifecycleEligibility;
use App\Support\OfferEligibility;
use App\Support\Processing;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\LifecycleFixture;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class LifecycleEvidenceTest extends TestCase
{
    use LifecycleFixture, QuotationFixture, RefreshDatabase;

    public function test_matching_never_accepts_and_reviewed_source_changes_hold_release(): void
    {
        $q = $this->quote();
        $m = MailMessage::factory()->create(['is_demo' => true, 'inquiry_id' => $this->case->id, 'client_quotation_revision_id' => $q->id,
            'sender_email' => $q->payload['to']['email'], 'match_state' => 'matched', 'classification' => 'acceptance', 'attachment_state' => 'complete']);
        $a = app(ManageLifecycle::class);
        $a->incoming($m);
        $a->incoming($m);
        $this->assertSame(1, AttentionTask::where('kind', 'client_response')->count());
        $this->assertSame(0, ClientDecision::count());
        $review = $a->decision($q, $this->staff, $this->decisionInput(['channel' => 'email', 'mail_message_id' => $m->id]));
        $this->assertSame('review_required', $review->outcome);
        app(MailIngest::class)->review($m, $this->staff, ['lock_version' => 0, 'decision' => 'associate', 'classification' => 'acceptance',
            'inquiry_id' => $this->case->id, 'client_quotation_revision_id' => $q->id, 'reason' => 'Human reviewed exact source, identity and terms.']);
        $d = $a->decision($q, $this->staff, $this->decisionInput(['channel' => 'email', 'mail_message_id' => $m->id, 'expected_decision' => $review->id, 'corrects_id' => $review->id]));
        $this->assertSame('accepted', $d->outcome);
        $this->assertSame([], LifecycleEligibility::acceptanceReasons($d));
        app(MailIngest::class)->review($m->fresh(), $this->staff, ['lock_version' => 1, 'decision' => 'ignore', 'classification' => 'other', 'reason' => 'Later evidence correction; source no longer supports acceptance.']);
        $this->assertStringContainsString('source', implode(' ', LifecycleEligibility::acceptanceReasons($d->fresh())));
        $this->assertSame('accepted', $d->fresh()->outcome);
        $this->assertSame(1, VendorReconfirmation::count());
    }

    public function test_incoming_question_stops_reminders_before_any_final_decision(): void
    {
        $q = $this->quote();
        $envelope = $q->approval->envelopes()->firstOrFail();
        $dispatch = app(MailOutbox::class)->enqueue($envelope, $this->staff, (string) Str::uuid(), $envelope->digest);
        for ($n = 0; $n < 3; $n++) {
            (new DispatchMail($dispatch->id))->handle();
            $this->travel(2)->seconds();
        }
        MailboxFolder::factory()->create(['last_sync_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        $followups = app(ManageFollowups::class);
        $followups->policy($admin, array_replace(ManageFollowups::defaults('client_quote'), ['kind' => 'client_quote', 'expected_number' => 0, 'enabled' => true, 'reason' => 'Controlled policy for immediate cancellation test.']));
        $preview = $followups->preview('client_quote', $q->approval->id, $this->staff, 'automatic');
        $plan = $followups->activate('client_quote', $q->approval->id, $this->staff, ['mode' => 'automatic', 'attach' => false, 'digest' => Processing::hash($preview), 'reason' => 'Explicit test activation.']);
        $m = MailMessage::factory()->create(['is_demo' => true, 'inquiry_id' => $this->case->id, 'client_quotation_revision_id' => $q->id,
            'sender_email' => $q->payload['to']['email'], 'match_state' => 'matched', 'classification' => 'question']);
        app(ManageLifecycle::class)->incoming($m);
        $this->assertSame('stopped', $plan->fresh()->state);
        $this->assertSame(0, ClientDecision::count());
        $this->assertSame(0, $plan->stages()->count());
    }

    public function test_document_requirements_use_exact_private_files_and_deposit_company_timezone(): void
    {
        [$q, $d, $r, $h, $e] = $this->ready(['required_documents' => [['label' => 'Packing list', 'service' => 'any']], 'require_deposit' => true, 'operational_exceptions' => ['documents']]);
        $this->assertSame('incomplete', $h->state);
        $bytes = 'Fictional packing evidence, version 1';
        Storage::disk('inquiry_documents')->put('evidence/packing.txt', $bytes);
        $doc = InquiryDocument::factory()->create(['inquiry_id' => $this->case->id, 'storage_path' => 'evidence/packing.txt', 'size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes), 'mime' => 'text/plain', 'original_name' => 'packing.txt', 'classification' => 'packing_list']);
        $localTime = now('Asia/Kuala_Lumpur')->subMinute()->format('Y-m-d\TH:i:s');
        $next = app(ManageLifecycle::class)->saveHandoff($this->case, $this->staff, array_replace($e, ['expected_revision' => 1,
            'document_map' => [0 => $doc->id], 'deposit_confirmed' => true, 'deposit_at' => $localTime, 'deposit_evidence' => 'Staff reviewed fictional deposit advice.']));
        $this->assertSame('ready', $next->state);
        $this->assertSame([$doc->id], $next->snapshot['evidence']['document_ids']);
        $this->assertSame(now()->subMinute()->format('Y-m-d\TH:i:s').'+00:00', $next->snapshot['evidence']['deposit_at']);
        app(ManageLifecycle::class)->approveHandoff($next, $this->staff, $next->digest);
        Storage::disk('inquiry_documents')->put('evidence/packing.txt', 'changed');
        $this->assertStringContainsString('checksum', implode(' ', LifecycleEligibility::revisionReasons($next)));
        $this->assertSame('Release on hold', LifecycleEligibility::statuses($this->case)['handoff']);
        $admin = User::factory()->create(['role' => 'admin']);
        $attempt = LifecycleEligibility::readiness($this->case, array_replace($next->snapshot['evidence'], ['exception_authority' => $admin->id, 'exceptions' => ['documents' => 'A missing-file exception cannot authorize corrupt supplied evidence.']]));
        $this->assertSame('missing', $attempt['items']['documents']['state']);
        $this->assertFalse($attempt['ready']);
    }

    public function test_admin_operational_exception_is_audited_and_cannot_bypass_commercial_change(): void
    {
        [$q, $d, $r, $h, $e] = $this->ready(['required_documents' => [['label' => 'Company packing evidence', 'service' => 'any']], 'operational_exceptions' => ['documents']]);
        $input = array_replace($e, ['expected_revision' => 1, 'exceptions' => ['documents' => 'Admin permits a documented operational exception; operations will collect the packing evidence before dispatch.']]);
        $this->post(route('lifecycle.handoff.save', $this->case), $input)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $next = app(ManageLifecycle::class)->saveHandoff($this->case, $admin, $input);
        $this->assertSame('ready', $next->state);
        $this->assertSame('conditional', $next->snapshot['checklist']['documents']['state']);
        $this->assertSame($admin->id, $next->snapshot['evidence']['exception_authority']);
        app(ManageLifecycle::class)->confirmation($r, $this->staff, $this->vendorInput($r, ['rate_total' => '1400']));
        $this->blocked(fn () => app(ManageLifecycle::class)->approveHandoff($next, $admin, $next->digest), 'vendor');
    }

    public function test_rate_change_requires_reviewed_replacement_offer_and_renewed_exact_acceptance(): void
    {
        [$q, $d, $r] = $this->ready();
        app(ManageLifecycle::class)->confirmation($r, $this->staff, $this->vendorInput($r, ['rate_total' => '1400']));
        app(ManageLifecycle::class)->confirmation($r, $this->staff, $this->vendorInput($r));
        $this->assertStringContainsString('Material vendor changes', implode(' ', LifecycleEligibility::requestReasons($r->fresh())));
        $this->travel(2)->seconds();
        $sameBasis = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput(1));
        app(ManageQuotation::class)->approve($sameBasis, $this->staff, Processing::hash(app(ManageQuotation::class)->reviewSnapshot($sameBasis)));
        $review = $this->accept($sameBasis);
        $this->assertSame('review_required', $review->outcome);
        $this->assertStringContainsString('newly reviewed', implode(' ', $review->snapshot['blocking_reasons']));
        $offer = $this->selection->revision->offer;
        $payload = $offer->current()->payload;
        $payload['lines'][0]['rate'] = (string) BigDecimal::of($payload['lines'][0]['rate'])->plus('50');
        $payload['quoted_total'] = '1400';
        $newOffer = app(ManageOffer::class)->save($offer, $this->staff, array_replace($payload, ['expected_revision' => $offer->current_number, 'change_reason' => 'Vendor increased freight by MYR 100; exact new evidence reviewed.']), true);
        $this->assertSame('1400.00000000', $newOffer->complete_total);
        $comparison = OfferEligibility::currentComparison($this->case);
        $this->selection = app(ManageOffer::class)->select($this->case, $this->staff, ['offer_revision_id' => $newOffer->id, 'comparison_id' => $comparison->id, 'expected_selection' => $this->selection->id, 'kind' => 'final', 'reason' => 'New final selection after material change.']);
        $replacement = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput(2));
        app(ManageQuotation::class)->approve($replacement, $this->staff, Processing::hash(app(ManageQuotation::class)->reviewSnapshot($replacement)));
        $this->travel(2)->seconds();
        $renewed = $this->accept($replacement->fresh());
        $this->assertSame('accepted', $renewed->outcome);
        $this->assertSame('1680.00', $replacement->pricing['total']);
        $this->assertSame(2, VendorReconfirmation::count());
        $this->assertSame('Superseded', LifecycleEligibility::outcome($q));
    }

    public function test_closure_reopen_cannot_revive_approval_and_booking_corrections_append(): void
    {
        [$q, $d, $r, $h] = $this->ready();
        $life = app(ManageLifecycle::class);
        $a = $life->approveHandoff($h, $this->staff, $h->digest);
        $this->travel(2)->seconds();
        $life->event($a, $this->staff, ['action_key' => (string) Str::uuid(), 'kind' => 'handed_to_operations', 'occurred_at' => now()->toIso8601String(), 'notes' => 'Operations received exact approved handoff.']);
        $input = ['action_key' => (string) Str::uuid(), 'kind' => 'booking_confirmed', 'occurred_at' => now()->toIso8601String(), 'notes' => 'Actual fictional booking reference confirmed by vendor.',
            'vendor_reference' => 'SIM-OLD-001', 'contact_id' => $r->fresh()->current()->contact_id, 'pickup_date' => $this->case->shipment['cargo_ready_date'], 'arrival_date' => $this->case->shipment['arrival_date'], 'scope_confirmed' => true];
        $old = $life->event($a, $this->staff, $input);
        $corrected = $life->event($a, $this->staff, array_replace($input, ['action_key' => (string) Str::uuid(), 'corrects_id' => $old->id, 'vendor_reference' => 'SIM-CORRECTED-001', 'notes' => 'Correct original transcribed reference against retained vendor evidence.']));
        $this->assertSame($old->id, $corrected->corrects_id);
        $this->assertSame('SIM-OLD-001', $old->fresh()->snapshot['data']['vendor_reference']);
        $this->assertSame(3, HandoffEvent::count());
        try {
            DB::transaction(fn () => $old->update(['kind' => 'booking_requested']));
            $this->fail('Immutable booking evidence must reject updates.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('immutable', strtolower($error->getMessage()));
        }
        $generation = $this->case->fresh()->lifecycle_generation;
        $closed = app(TransitionInquiry::class)->handle($this->case->fresh(), ['lock_version' => $this->case->fresh()->lock_version, 'target' => 'closed', 'reason' => 'Explicit fictional administrative closure after evidence review.']);
        $reopened = app(TransitionInquiry::class)->handle($closed, ['lock_version' => $closed->lock_version, 'target' => 'reopen', 'reason' => 'Explicit fresh operational assessment requested.']);
        app(TransitionInquiry::class)->handle($reopened, ['lock_version' => $reopened->lock_version, 'target' => 'ready_for_sourcing']);
        $this->assertGreaterThan($generation, $this->case->fresh()->lifecycle_generation);
        $this->blocked(fn () => LifecycleEligibility::approved($a->fresh()), 'changed');
        $this->assertSame(3, HandoffEvent::count());
    }

    public function test_private_children_and_malformed_company_policy_are_denied_safely(): void
    {
        [$q, $d, $r, $h] = $this->ready();
        $other = Inquiry::whereKeyNot($this->case->id)->firstOrFail();
        $this->get(route('lifecycle.pdf', [$other, $h]))->assertNotFound();
        $this->get(route('lifecycle.decision', [$other, $q]))->assertNotFound();
        $this->get(route('lifecycle.vendor', [$other, $r]))->assertNotFound();
        $this->withSession(['_previous' => ['url' => route('quotations.pdf', [$this->case, $q])]])
            ->post(route('lifecycle.decision.save', [$this->case, $q]), $this->decisionInput(['expected_decision' => $d->id, 'corrects_id' => $d->id, 'decided_at' => now()->addDay()->toIso8601String()]))
            ->assertRedirect(route('lifecycle.decision', [$this->case, $q]))->assertSessionHasErrors('processing')->assertSessionHasInput('notes');
        $this->assertSame(1, ClientDecision::count());
        $this->withSession(['_previous' => ['url' => route('quotations.pdf', [$this->case, $q])]])
            ->post(route('lifecycle.decision.save', [$this->case, $q]), array_replace($this->decisionInput(), ['notes' => '']))
            ->assertRedirect(route('lifecycle.decision', [$this->case, $q]))->assertSessionHasErrors('notes');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('settings.handoff.save'), ['expected_revision' => 1, 'reason' => 'Malformed rows are validation errors.', 'required_documents' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('required_documents');
        $this->postJson(route('settings.handoff.save'), ['expected_revision' => 1, 'reason' => 'Malformed document label must not crash.', 'required_documents' => [['label' => ['bad'], 'service' => 'any']]])->assertUnprocessable()->assertJsonValidationErrors('required_documents.0.label');
        auth()->logout();
        $this->get(route('lifecycle.pdf', [$this->case, $h]))->assertRedirect(route('login'));
    }

    public function test_booking_requires_actual_handoff_and_rejects_automated_source(): void
    {
        [$q, $d, $r, $h] = $this->ready();
        $life = app(ManageLifecycle::class);
        $a = $life->approveHandoff($h, $this->staff, $h->digest);
        $this->travel(2)->seconds();
        $input = ['action_key' => (string) Str::uuid(), 'kind' => 'booking_requested', 'occurred_at' => now()->toIso8601String(), 'notes' => 'External request evidence cannot predate actual operations handoff.'];
        $this->blocked(fn () => $life->event($a, $this->staff, $input), 'actual handoff');
        $life->event($a, $this->staff, array_replace($input, ['action_key' => (string) Str::uuid(), 'kind' => 'handed_to_operations']));
        $mail = app(ManageOperationalMail::class);
        $op = $mail->save($h, $this->staff, array_replace($mail->defaults($h), ['reason' => 'Explicit controlled booking request draft.']));
        $mail->approve($op, $this->staff, Processing::hash($mail->preview($op, $this->staff)));
        $m = MailMessage::factory()->create(['is_demo' => true, 'inquiry_id' => $this->case->id, 'operational_message_id' => $op->id,
            'sender_email' => $op->content['to']['email'], 'match_state' => 'matched', 'classification' => 'out_of_office', 'response_reviewed_at' => now(), 'response_reviewed_by' => $this->staff->id]);
        $this->travel(2)->seconds();
        $this->blocked(fn () => $life->event($a, $this->staff, array_replace($input, ['action_key' => (string) Str::uuid(), 'kind' => 'booking_confirmed',
            'occurred_at' => now()->toIso8601String(), 'mail_message_id' => $m->id, 'vendor_reference' => 'SIM-001', 'contact_id' => $r->fresh()->current()->contact_id,
            'pickup_date' => $this->case->shipment['cargo_ready_date'], 'arrival_date' => $this->case->shipment['arrival_date'], 'scope_confirmed' => true])), 'Review booking email');
        $this->assertSame('Booking unconfirmed', LifecycleEligibility::statuses($this->case)['booking']);
    }
}
