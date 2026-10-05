<?php

namespace Tests\Feature;

use App\Actions\MailOutbox;
use App\Actions\ManageLifecycle;
use App\Actions\ManageQuotation;
use App\Models\HandoffEvent;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\ShipmentVersion;
use App\Models\VendorReconfirmation;
use App\Support\InquiryJourney;
use App\Support\Processing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fixtures\LifecycleFixture;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class InquiryJourneyTest extends TestCase
{
    use LifecycleFixture, QuotationFixture, RefreshDatabase;

    public function test_incomplete_and_changed_shipments_start_with_details_instead_of_later_approvals(): void
    {
        $draft = Inquiry::factory()->create(['source_channel' => 'website', 'client_id' => null, 'client_contact_id' => null]);
        $step = InquiryJourney::current($draft);
        $this->assertSame(1, $step['stage']);
        $this->assertSame(route('inquiries.public-contact.edit', $draft), $step['url']);
        $this->get(route('inquiries.show', $draft))->assertOk()->assertSee('Check customer contact')->assertSee('How it works');

        $this->case->update(['status' => 'needs_review']);
        $this->assertSame(1, InquiryJourney::current($this->case)['stage']);
        $this->case->update(['status' => 'ready_for_sourcing', 'shipment_revision' => 2]);
        ShipmentVersion::factory()->create(['inquiry_id' => $this->case->id, 'number' => 2]);
        $step = InquiryJourney::current($this->case);
        $this->assertSame(2, $step['stage']);
        $this->assertSame('Choose vendors', $step['action']);
        $this->assertDatabaseCount('mail_dispatches', 0);
        $this->assertDatabaseCount('handoff_events', 0);
    }

    public function test_reviewed_cost_and_exact_quotation_approval_have_distinct_next_actions(): void
    {
        $this->assertSame('Set customer price', InquiryJourney::current($this->case)['action']);
        $quote = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput());
        $step = InquiryJourney::current($this->case);
        $this->assertSame(4, $step['stage']);
        $this->assertSame(route('quotations.review', [$this->case, $quote]), $step['url']);
        $this->assertSame('Review quotation', $step['action']);
        app(ManageQuotation::class)->approve($quote, $this->staff, Processing::hash(app(ManageQuotation::class)->reviewSnapshot($quote)));
        $this->assertSame('Review and send', InquiryJourney::current($this->case)['action']);
        $this->get(route('inquiries.show', $this->case))->assertOk()->assertSee('Send the approved quotation')->assertDontSee('Select vendors & prepare RFQs');
        $this->assertDatabaseCount('mail_dispatches', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_uncertain_dispatch_points_to_its_status_and_never_prompts_a_fresh_send(): void
    {
        $quote = $this->quote();
        $envelope = $quote->approval->envelopes()->firstOrFail();
        $dispatch = app(MailOutbox::class)->enqueue($envelope, $this->staff, (string) Str::uuid(), $envelope->digest);
        $dispatch->update(['status' => 'uncertain']);
        $step = InquiryJourney::current($this->case);
        $this->assertSame('Check sending status', $step['action']);
        $this->assertSame(route('mail.dispatch', $dispatch), $step['url']);
        $this->assertDatabaseCount('mail_dispatches', 1);
        $this->assertSame('uncertain', $dispatch->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_customer_revision_request_returns_to_pricing(): void
    {
        $quote = $this->quote();
        app(ManageLifecycle::class)->decision($quote, $this->staff, $this->decisionInput(['outcome' => 'revision_requested']));
        $step = InquiryJourney::current($this->case);
        $this->assertSame(4, $step['stage']);
        $this->assertSame('Revise quotation', $step['action']);
        $this->assertDatabaseCount('handoff_events', 0);
    }

    public function test_approved_handoff_and_actual_booking_are_never_reported_as_the_same_step(): void
    {
        [$quote, $decision, $request, $handoff] = $this->ready();
        $this->assertSame('Review and approve the operations handoff', InquiryJourney::current($this->case)['title']);
        $action = app(ManageLifecycle::class);
        $approval = $action->approveHandoff($handoff, $this->staff, $handoff->digest);
        $step = InquiryJourney::current($this->case);
        $this->assertSame(5, $step['stage']);
        $this->assertSame('Record operations handoff', $step['action']);
        $this->get(route('inquiries.show', $this->case))->assertOk()->assertSee('Hand the approved shipment to operations')->assertDontSee('Select vendors & prepare RFQs');
        $this->assertDatabaseCount('handoff_events', 0);

        $action->event($approval, $this->staff, ['action_key' => (string) Str::uuid(), 'kind' => 'handed_to_operations', 'occurred_at' => now()->toIso8601String(), 'notes' => 'Operations received the checked fictional instructions.']);
        $this->assertSame('Record the actual vendor booking', InquiryJourney::current($this->case)['title']);
        $action->event($approval, $this->staff, [
            'action_key' => (string) Str::uuid(), 'kind' => 'booking_confirmed', 'occurred_at' => now()->toIso8601String(),
            'notes' => 'Fictional vendor booking evidence for this exact handoff.',
            'vendor_reference' => 'SIM-JOURNEY-1005', 'contact_id' => $request->fresh()->current()->contact_id,
            'pickup_date' => $this->case->shipment['cargo_ready_date'], 'arrival_date' => $this->case->shipment['arrival_date'], 'scope_confirmed' => true,
        ]);
        $before = HandoffEvent::count();
        $this->assertSame('Vendor booking recorded', InquiryJourney::current($this->case)['title']);
        $this->assertSame($before, HandoffEvent::count());
        $this->assertDatabaseCount('mail_dispatches', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_changed_vendor_terms_and_expired_costs_return_to_price_review(): void
    {
        [$quote, $decision, $request] = $this->ready();
        app(ManageLifecycle::class)->confirmation($request, $this->staff, $this->vendorInput($request, ['rate_total' => '1400']));
        $step = InquiryJourney::current($this->case);
        $this->assertSame(3, $step['stage']);
        $this->assertSame('Vendor terms changed', $step['title']);
        $this->assertSame(route('offers.index', $this->case), $step['url']);
        $this->travel(60)->days();
        $this->assertSame(3, InquiryJourney::current($this->case)['stage']);
        $this->assertDatabaseCount('handoff_events', 0);
    }

    public function test_guided_forms_keep_existing_fields_and_staff_permissions(): void
    {
        $quote = $this->quote();
        $offer = $this->selection->revision->offer;
        foreach ([
            route('inquiries.edit', $this->case),
            route('rfqs.edit', [$this->case, $offer->request->rfq]),
            route('offers.edit', [$this->case, $offer]),
            route('offers.capture', $this->case),
            route('quotations.index', $this->case),
        ] as $url) {
            $this->get($url)->assertOk()->assertSee('data-guided-form', false)->assertSee('data-form-step=', false)->assertSee('data-form-guide', false);
        }
        $this->get(route('quotations.index', $this->case))->assertSee('markup_confirmed', false)->assertSee('terms_confirmed', false)->assertSee('customer_tax_evidence', false);
        $decision = $this->accept($quote);
        $request = VendorReconfirmation::where('client_decision_id', $decision->id)->firstOrFail();
        foreach ([route('lifecycle.decision', [$this->case, $quote]), route('lifecycle.vendor', [$this->case, $request]), route('lifecycle.handoff', $this->case)] as $url) {
            $this->get($url)->assertOk()->assertSee('data-guided-form', false)->assertSee('data-form-step=', false);
        }
        $this->assertSame(0, MailDispatch::count());
        $this->staff->update(['is_active' => false]);
        $this->get(route('inquiries.show', $this->case))->assertRedirect(route('login'));
    }
}
