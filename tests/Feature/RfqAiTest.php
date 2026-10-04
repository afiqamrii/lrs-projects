<?php

namespace Tests\Feature;

use App\Actions\ManageRfq;
use App\Actions\PrepareRfqs;
use App\Actions\StartRfqWording;
use App\Jobs\RequestAiProposals;
use App\Jobs\SendInquiryReceipt;
use App\Models\AiBudgetDay;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Support\AiUsage;
use App\Support\InquiryWorkflow;
use App\Support\OpenAiResponses;
use App\Support\Processing;
use App\Support\RfqContent;
use App\Support\RfqWording;
use App\Support\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RfqAiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Inquiry $inquiry;

    private Rfq $rfq;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->staff = User::factory()->create(['role' => 'agent']);
        $this->actingAs($this->staff);
        CompanySetting::current()->update(['rfq_reply_name' => 'Synthetic LRS desk', 'rfq_reply_email' => 'reply@example.test', 'rfq_signature' => 'Synthetic LRS desk']);
        $client = Client::factory()->create(['company_name' => 'Private client identity']);
        $contact = ClientContact::factory()->create(['client_id' => $client->id]);
        $this->inquiry = Inquiry::factory()->create(['client_id' => $client->id, 'client_contact_id' => $contact->id, 'owner_id' => $this->staff->id, 'response_due_at' => now()->addDays(3), 'status' => 'needs_review', 'original_source_text' => 'Private inbox and budget USD 12345', 'internal_notes' => 'Internal cost and margin 998877', 'shipment' => Shipment::normalize(['mode' => 'LCL', 'scope' => 'port_to_port', 'cargo_description' => 'Synthetic machine parts', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore port', 'cargo_ready_date' => '2026-11-01', 'goods_value' => '12000', 'goods_currency' => 'USD', 'budget' => '12345', 'budget_currency' => 'USD', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 2, 'gross_weight' => '250.5', 'weight_unit' => 'kg', 'length' => '100', 'width' => '80', 'height' => '90', 'dimension_unit' => 'cm']]])]);
        $this->post(route('inquiries.transition', $this->inquiry), ['target' => 'ready_for_sourcing', 'lock_version' => 0])->assertSessionHasNoErrors();
        $vendor = Vendor::factory()->create(['company_name' => 'Target vendor identity']);
        $vendor->contacts()->create(['name' => 'Private recipient', 'email' => 'recipient@example.test', 'is_primary' => true]);
        app(PrepareRfqs::class)->handle($this->inquiry->fresh(), $this->staff, [$vendor->id], $this->inquiry->fresh()->lock_version);
        $this->rfq = Rfq::firstOrFail();
        app(ManageRfq::class)->save($this->rfq, $this->staff, $this->data());
        $this->rfq = $this->rfq->fresh();
    }

    private function data(array $extra = []): array
    {
        $revision = $this->rfq->fresh()->current();
        $p = $revision->payload;

        return array_replace(['expected_revision' => $revision->number, 'subject' => $p['subject'], 'opening' => $p['opening'], 'closing' => $p['closing'], 'vendor_notes' => $p['vendor_notes'], 'alternative_notes' => $p['alternative_notes'], 'to_contact_id' => $p['to']['id'], 'cc_contact_ids' => [], 'document_ids' => [], 'attachments_reviewed' => true, 'disclose_identity' => false, 'disclose_addresses' => false, 'currency' => 'USD', 'response_due_at' => InquiryWorkflow::local(now()->addDay())], $extra);
    }

    private function configure(): void
    {
        config(['ai.key' => 'synthetic-test-key']);
        AiSetting::current()->update(['enabled' => true, 'model' => 'synthetic-test-model', 'model_check' => ['model' => 'synthetic-test-model', 'state' => 'accessible'], 'configuration' => ['input_rate' => '1', 'output_rate' => '2', 'cached_rate' => '0.5', 'rate_version' => 'synthetic-1', 'rate_date' => '2026-10-04', 'structured_verified' => true, 'run_cap' => '1', 'inquiry_cap' => '5', 'daily_cap' => '10']]);
    }

    private function start(): AiRun
    {
        $revision = $this->rfq->fresh()->current();
        $sources = RfqWording::sources($revision);

        return app(StartRfqWording::class)->handle($this->rfq, $this->staff, $revision->number, AiUsage::scope($sources, AiSetting::current(), 'rfq_wording'));
    }

    private function wordingResult(): array
    {
        return ['subject' => 'Request for freight quotation', 'opening' => 'Please review the confirmed requirements and provide your quotation.', 'closing' => 'Thank you. Please identify assumptions clearly.', 'quotation_request' => 'Please state any exclusions and available alternatives separately.'];
    }

    private function complete(AiRun $run, ?array $result = null, int $status = 200, ?array $usage = null): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_synthetic_wording', 'model' => 'synthetic-test-model', 'status' => 'completed', 'usage' => $usage ?? ['input_tokens' => 100, 'output_tokens' => 50], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result ?? $this->wordingResult())]]]]], $status)]);
        (new RequestAiProposals($run->id))->handle(app(OpenAiResponses::class));
    }

    public function test_disabled_ai_is_honest_and_deterministic_manual_preparation_remains_usable(): void
    {
        $this->get(route('rfqs.edit', [$this->inquiry, $this->rfq]))->assertOk()->assertSee('Template/manual wording remains usable')->assertSee('Live AI is disabled');
        $this->get(route('rfqs.ai.scope', [$this->inquiry, $this->rfq]))->assertOk()->assertSee('no paid request allowed');
        try {
            $this->start();
            $this->fail('Expected disabled guard');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }
        $this->patch(route('rfqs.save', [$this->inquiry, $this->rfq]), $this->data(['opening' => 'Professional manual template refinement.']))->assertSessionHasNoErrors();
        $revision = $this->rfq->fresh()->current();
        app(ManageRfq::class)->approve($this->rfq, $this->staff, $revision->number, Processing::hash(RfqContent::snapshot($revision)));
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNotPushed(SendInquiryReceipt::class);
    }

    public function test_wording_uses_one_separate_strict_schema_whitelisted_input_and_shared_usage_ledger(): void
    {
        $this->configure();
        $run = $this->start();
        $same = $this->start();
        $this->assertSame($run->id, $same->id);
        $this->assertSame('rfq_wording', $run->purpose);
        Queue::assertPushed(RequestAiProposals::class, 1);
        $input = RfqWording::input($run->sources);
        foreach (['Private client identity', 'Target vendor identity', 'recipient@example.test', '12345', '998877', '12000', 'Private inbox'] as $private) {
            $this->assertStringNotContainsString($private, $input);
        }
        $this->assertGreaterThan(0, (float) AiBudgetDay::first()->reserved);
        $this->complete($run);
        $run = $run->fresh();
        $this->assertSame('needs_review', $run->state);
        $this->assertNull($run->proposals);
        $this->assertSame('0.00020000', $run->estimated_cost);
        $this->assertSame('0.00000000', $run->reservation);
        Http::assertSent(function (Request $request): bool {
            return $request['tools'] === [] && $request['store'] === false && $request['text']['format']['name'] === 'rfq_wording_v1' && $request['text']['format']['schema']['additionalProperties'] === false;
        });
        $this->assertSame($run->id, $this->start()->id);
        Http::assertSentCount(1);
        Mail::assertNothingSent();
        Queue::assertNotPushed(SendInquiryReceipt::class);
    }

    public function test_reviewed_apply_changes_only_wording_in_a_new_unapproved_revision(): void
    {
        $this->configure();
        $run = $this->start();
        $this->complete($run);
        $before = $this->rfq->fresh()->current();
        $facts = RfqContent::facts($this->rfq, $before->payload);
        $this->post(route('rfqs.ai.apply', [$this->inquiry, $this->rfq, $run]), ['expected_revision' => $before->number, 'reviewed_wording' => 1])->assertSessionHasNoErrors();
        $after = $this->rfq->fresh()->current();
        $this->assertSame($before->number + 1, $after->number);
        $this->assertSame('draft', $after->status);
        $this->assertNull($after->approval);
        $this->assertSame($before->payload['to'], $after->payload['to']);
        $this->assertSame($before->payload['cc'], $after->payload['cc']);
        $this->assertSame($before->payload['manifest'], $after->payload['manifest']);
        $this->assertSame($facts, RfqContent::facts($this->rfq, $after->payload));
        $this->assertSame('ai_reviewed', $after->payload['origin']);
        $this->assertSame('superseded', $before->fresh()->status);
        $this->assertSame('applied', $run->fresh()->review_outcome);
        $this->post(route('rfqs.ai.apply', [$this->inquiry, $this->rfq, $run]), ['expected_revision' => $after->number, 'reviewed_wording' => 1])->assertSessionHasErrors('processing');
        $this->post(route('inquiries.ai.preview', [$this->inquiry, $run]), ['lock_version' => $this->inquiry->fresh()->lock_version, 'decisions' => ['1' => ['action' => 'accept']]])->assertNotFound();
    }

    public function test_delayed_result_cannot_overwrite_a_newer_draft_or_an_approved_version(): void
    {
        $this->configure();
        $run = $this->start();
        $this->complete($run);
        app(ManageRfq::class)->save($this->rfq, $this->staff, $this->data(['opening' => 'Newer staff wording must remain.']));
        $revision = $this->rfq->fresh()->current();
        $this->post(route('rfqs.ai.apply', [$this->inquiry, $this->rfq, $run]), ['expected_revision' => $revision->number, 'reviewed_wording' => 1])->assertSessionHasErrors('processing');
        $this->assertSame('Newer staff wording must remain.', $revision->fresh()->payload['opening']);
        $new = $this->start();
        $this->complete($new);
        $approval = app(ManageRfq::class)->approve($this->rfq, $this->staff, $revision->number, Processing::hash(RfqContent::snapshot($revision)));
        $this->post(route('rfqs.ai.apply', [$this->inquiry, $this->rfq, $new]), ['expected_revision' => $revision->number, 'reviewed_wording' => 1])->assertSessionHasErrors('processing');
        $this->assertSame(Processing::hash($approval->snapshot), Processing::hash($approval->fresh()->snapshot));
    }

    public function test_queued_stale_wording_releases_reservation_without_a_paid_call(): void
    {
        $this->configure();
        $run = $this->start();
        app(ManageRfq::class)->save($this->rfq, $this->staff, $this->data(['closing' => 'New staff closing.']));
        (new RequestAiProposals($run->id))->handle(app(OpenAiResponses::class));
        $this->assertSame('unavailable', $run->fresh()->state);
        $this->assertSame('0.00000000', $run->fresh()->reservation);
        $this->assertSame('0.00000000', AiBudgetDay::first()->reserved);
        Http::assertNothingSent();
    }

    public function test_failed_and_untrusted_wording_preserve_template_and_no_send_authority(): void
    {
        $this->configure();
        $run = $this->start();
        $this->complete($run, ['subject' => 'Unsafe', 'opening' => 'We guarantee delivery on 2026-11-01.', 'closing' => 'Booked now', 'quotation_request' => 'USD 999 vendor rates']);
        $this->assertSame('failed', $run->fresh()->state);
        $this->assertSame('invalid_output', $run->fresh()->error_code);
        $this->assertNull($run->fresh()->result);
        $this->assertSame('manual', $this->rfq->fresh()->current()->payload['origin']);
        $this->patch(route('rfqs.save', [$this->inquiry, $this->rfq]), $this->data(['opening' => 'Manual preparation remains available after a failed proposal.']))->assertSessionHasNoErrors();
        Mail::assertNothingSent();
        Queue::assertNotPushed(SendInquiryReceipt::class);
    }

    public function test_timeout_holds_uncertain_cost_and_blocks_paid_retry_but_not_manual_edits(): void
    {
        $this->configure();
        $run = $this->start();
        Http::fake(fn () => throw new ConnectionException('Synthetic timeout'));
        (new RequestAiProposals($run->id))->handle(app(OpenAiResponses::class));
        $this->assertTrue($run->fresh()->cost_uncertain);
        $this->assertNull($run->fresh()->estimated_cost);
        $this->assertGreaterThan(0, (float) $run->fresh()->reservation);
        $this->patch(route('rfqs.save', [$this->inquiry, $this->rfq]), $this->data(['opening' => 'Manual fallback after uncertain AI outcome.']))->assertSessionHasNoErrors();
        try {
            $this->start();
            $this->fail('Expected uncertain cost guard');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('uncertain', $e->getMessage());
        }
    }

    public function test_daily_and_run_caps_are_shared_and_cannot_be_bypassed_by_rfq_purpose(): void
    {
        $this->configure();
        $settings = AiSetting::current();
        $rates = $settings->configuration;
        $rates['daily_cap'] = '0.00000001';
        $settings->update(['configuration' => $rates]);
        try {
            $this->start();
            $this->fail('Expected daily cap');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('daily', $e->getMessage());
        }
        $this->assertSame(0, AiRun::count());
        $rates['daily_cap'] = '10';
        $rates['run_cap'] = '0.00000001';
        $settings->update(['configuration' => $rates]);
        try {
            $this->start();
            $this->fail('Expected run cap');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('per-run', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_unchanged_wording_is_rebound_to_new_revision_without_another_paid_call(): void
    {
        $this->configure();
        $run = $this->start();
        $this->complete($run);
        app(ManageRfq::class)->save($this->rfq, $this->staff, $this->data(['response_due_at' => InquiryWorkflow::local(now()->addDays(2))]));
        $current = $this->rfq->fresh()->current();
        $reused = $this->start();
        $this->assertNotSame($run->id, $reused->id);
        $this->assertSame($current->id, $reused->rfq_revision_id);
        $this->assertSame($run->id, $reused->working_snapshot['reused_from_run_id']);
        $this->assertSame('0.00000000', $reused->estimated_cost);
        $this->assertSame(0, $reused->attempts);
        $retry = app(StartRfqWording::class)->handle($this->rfq, $this->staff, $current->number, AiUsage::scope(RfqWording::sources($current), AiSetting::current(), 'rfq_wording'), true, 'Cosmetic retry must reuse unchanged successful wording.');
        $this->assertSame($reused->id, $retry->id);
        Queue::assertPushed(RequestAiProposals::class, 1);
        Http::assertSentCount(1);
        $this->post(route('rfqs.ai.apply', [$this->inquiry, $this->rfq, $reused]), ['expected_revision' => $current->number, 'reviewed_wording' => 1])->assertSessionHasNoErrors();
        $this->assertSame('ai_reviewed', $this->rfq->fresh()->current()->payload['origin']);
    }
}
