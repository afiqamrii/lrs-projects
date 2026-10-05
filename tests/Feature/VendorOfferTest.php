<?php

namespace Tests\Feature;

use App\Actions\ManageOffer;
use App\Actions\PrepareRfqs;
use App\Actions\StartOfferProposals;
use App\Actions\TransitionInquiry;
use App\Jobs\RequestAiProposals;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\DocumentRun;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\OfferComparison;
use App\Models\OfferSelection;
use App\Models\PublicSubmission;
use App\Models\RfqApproval;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOffer;
use App\Support\AiUsage;
use App\Support\OfferEligibility;
use App\Support\OfferProposal;
use App\Support\OpenAiResponses;
use App\Support\Processing;
use App\Support\Shipment;
use App\Support\WorkspaceData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VendorOfferTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Inquiry $case;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->staff = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->actingAs($this->staff);
        $client = Client::factory()->create();
        $contact = ClientContact::factory()->create(['client_id' => $client->id]);
        $s = Shipment::normalize(['mode' => 'LCL', 'scope' => 'port_to_door', 'cargo_description' => 'Machine components', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore port', 'delivery_address' => 'Receiving warehouse, Singapore', 'cargo_ready_date' => now()->addDays(7)->toDateString(), 'arrival_date' => now()->addDays(20)->toDateString(), 'incoterm' => 'DAP', 'named_place' => 'Receiving warehouse', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 2, 'gross_weight' => '250', 'weight_unit' => 'kg', 'length' => '100', 'width' => '100', 'height' => '100', 'dimension_unit' => 'cm']]]);
        $this->case = Inquiry::factory()->create(['client_id' => $client->id, 'client_contact_id' => $contact->id, 'owner_id' => $this->staff->id, 'status' => 'needs_review', 'response_due_at' => now()->addDays(3), 'shipment' => $s]);
        $this->case = app(TransitionInquiry::class)->handle($this->case, ['lock_version' => 0, 'target' => 'ready_for_sourcing']);
    }

    private function capture(string $name = 'Harbour Link', string $alternative = 'Standard sailing'): VendorOffer
    {
        $v = Vendor::factory()->create(['company_name' => $name]);
        $v->contacts()->create(['name' => 'Amir Tan', 'email' => 'desk'.$v->id.'@vendor.example', 'is_active' => true, 'is_primary' => true]);
        $round = app(PrepareRfqs::class)->handle($this->case, $this->staff, [$v->id], $this->case->lock_version);
        $r = $round->rfqs()->where('vendor_id', $v->id)->first()->current();
        $r->update(['status' => 'approved']);
        RfqApproval::factory()->create(['rfq_revision_id' => $r->id]);

        return app(ManageOffer::class)->capture($this->case, $this->staff, ['rfq_revision_id' => $r->id, 'alternative' => $alternative, 'source_kind' => 'manual', 'manual_text' => 'Freight MYR 1050. Delivery quoted separately. Validity and payment require staff review.', 'association_confirmed' => '1', 'association_reason' => 'Vendor quotation desk commercial note retained.']);
    }

    private function data(VendorOffer $offer, string $freight = '1050', ?string $delivery = '100'): array
    {
        $p = ManageOffer::blank($offer);
        $lines = [ManageOffer::blankLine()];
        $lines[0] = array_replace($lines[0], ['description' => 'Main freight', 'state' => 'priced', 'rate' => $freight, 'source_ref' => 'quotation line 1', 'confirmed' => '1', 'tax_treatment' => 'inclusive']);
        $lines[] = array_replace(ManageOffer::blankLine(2), ['description' => 'Required warehouse delivery', 'category' => 'pickup_delivery', 'service' => 'delivery', 'state' => $delivery === null ? 'unpriced' : 'priced', 'rate' => $delivery, 'source_ref' => 'quotation line 2', 'confirmed' => '1', 'tax_treatment' => 'inclusive']);

        return array_replace($p, ['expected_revision' => $offer->fresh()->current_number, 'change_reason' => 'Reviewed against original vendor quotation', 'reference' => 'Q-2026-101', 'currency' => 'MYR', 'quantity_statement' => '2 pallets, 2 CBM, 250 kg; warehouse delivery included in scope', 'payment_terms' => 'Payment before release', 'validity_statement' => 'dated', 'valid_until' => now()->addDays(4)->toIso8601String(), 'timing_assessment' => 'meets_requested', 'timing_note' => 'Vendor confirms the requested ready and arrival window; subject to capacity', 'quoted_total' => $delivery === null ? null : (string) ((int) $freight + (int) $delivery), 'total_not_stated_reason' => $delivery === null ? 'Only freight stated; delivery awaiting quotation' : null, 'review' => ['scope' => '1', 'quantities' => '1', 'terms' => '1', 'validity' => '1'], 'lines' => $lines]);
    }

    private function comparison(): OfferComparison
    {
        return app(ManageOffer::class)->comparison($this->case, $this->staff, ['expected_comparison' => 0, 'currency' => 'MYR', 'reason' => 'Reviewed same confirmed shipment costs']);
    }

    public function test_unknown_delivery_never_ranks_or_finalizes_and_higher_complete_offer_can_be_selected(): void
    {
        $a = $this->capture('Offer A');
        $b = $this->capture('Offer B');
        $c = $this->capture('Offer C');
        $ra = app(ManageOffer::class)->save($a, $this->staff, $this->data($a, '1050', null), true);
        $rb = app(ManageOffer::class)->save($b, $this->staff, $this->data($b), true);
        $rc = app(ManageOffer::class)->save($c, $this->staff, $this->data($c, '1200'), true);
        $this->assertSame('1050.00000000', $ra->known_total);
        $this->assertNull($ra->complete_total);
        $this->assertSame('reviewed_gaps', $ra->status);
        $comp = $this->comparison();
        $matrix = OfferEligibility::matrix($this->case, $comp);
        $this->assertFalse($matrix[0]['lowest']);
        $this->assertTrue($matrix[1]['lowest']);
        $this->assertFalse($matrix[2]['lowest']);
        $this->post(route('offers.select', $this->case), ['offer_revision_id' => $ra->id, 'comparison_id' => $comp->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => 'Prefer cheaper freight'])->assertSessionHasErrors('processing');
        $this->post(route('offers.select', $this->case), ['offer_revision_id' => $rc->id, 'comparison_id' => $comp->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => 'Earlier confirmed delivery window justifies higher cost'])->assertRedirect();
        $selection = OfferSelection::firstOrFail();
        $snapshot = OfferEligibility::pricingBasis($selection);
        $this->assertSame('1300.00', $snapshot['calculation']['complete_total']);
        $this->get(route('offers.index', $this->case))->assertOk()->assertSee('Lowest comparable cost')->assertSee('Known subtotal');
        $this->get(route('offers.selection', [$this->case, $selection]))->assertOk()->assertSee('Earlier confirmed delivery');
        Mail::assertNothingSent();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_reimport_is_idempotent_alternatives_are_separate_and_stale_review_cannot_overwrite(): void
    {
        $offer = $this->capture();
        $data = ['rfq_revision_id' => $offer->rfq_revision_id, 'alternative' => $offer->alternative, 'source_kind' => 'manual', 'manual_text' => $offer->source['text'], 'association_confirmed' => '1', 'association_reason' => 'Verified again'];
        $this->assertSame($offer->id, app(ManageOffer::class)->capture($this->case, $this->staff, $data)->id);
        $data['alternative'] = 'Later sailing';
        $other = app(ManageOffer::class)->capture($this->case, $this->staff, $data);
        $this->assertNotSame($offer->id, $other->id);
        $p = $this->data($offer);
        $first = app(ManageOffer::class)->save($offer, $this->staff, $p, true);
        $this->patch(route('offers.save', [$this->case, $offer]), $p + ['intent' => 'review'])->assertSessionHasErrors('processing');
        $this->assertSame(1, $offer->fresh()->current_number);
        $this->assertSame('reviewed_complete', $first->fresh()->status);
    }

    public function test_expiry_edits_fx_shipment_and_inactive_vendor_invalidate_but_preserve_selection(): void
    {
        $o = $this->capture();
        $r = app(ManageOffer::class)->save($o, $this->staff, $this->data($o), true);
        $comp = $this->comparison();
        $s = app(ManageOffer::class)->select($this->case, $this->staff, ['offer_revision_id' => $r->id, 'comparison_id' => $comp->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => 'Complete scope and suitable delivery']);
        $frozen = $s->snapshot;
        $digest = $s->digest;
        $this->travel(5)->days();
        $this->assertStringContainsString('expired', implode(' ', OfferEligibility::selectionReasons($s)));
        $this->travelBack();
        $o->vendor->update(['is_active' => false]);
        $s->unsetRelation('revision');
        $this->assertStringContainsString('inactive', implode(' ', OfferEligibility::selectionReasons($s)));
        $o->vendor->update(['is_active' => true]);
        app(ManageOffer::class)->save($o, $this->staff, $this->data($o, '1100'), true);
        $s->unsetRelation('revision');
        $this->assertNotEmpty(OfferEligibility::selectionReasons($s));
        $this->assertSame(Processing::hash($frozen), Processing::hash($s->fresh()->snapshot));
        $this->assertSame($digest, $s->fresh()->digest);
        $this->get(route('offers.edit', [$this->case, $o]).'?version=1')->assertOk()->assertSee('Historical commercial record');
    }

    public function test_provisional_is_not_pricing_basis_and_old_rfq_is_outside_ranking(): void
    {
        $o = $this->capture();
        $r = app(ManageOffer::class)->save($o, $this->staff, $this->data($o, '1050', null), true);
        $comp = $this->comparison();
        $s = app(ManageOffer::class)->select($this->case, $this->staff, ['offer_revision_id' => $r->id, 'comparison_id' => $comp->id, 'expected_selection' => 0, 'kind' => 'provisional', 'reason' => 'Awaiting complete delivery cost']);
        $this->assertStringContainsString('Provisional', implode(' ', OfferEligibility::selectionReasons($s)));
        $o->request->rfq->update(['current_number' => 2]);
        $r->unsetRelation('offer');
        $this->assertStringContainsString('old', implode(' ', OfferEligibility::reasons($r, $comp)));
    }

    public function test_private_routes_authorization_scoping_and_evidence_immutability(): void
    {
        $o = $this->capture();
        $r = app(ManageOffer::class)->save($o, $this->staff, $this->data($o), true);
        $this->get(route('offers.edit', [$this->case, $o]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $other = Inquiry::factory()->create();
        $this->get(route('offers.edit', [$other, $o]))->assertNotFound();
        $this->actingAs(User::factory()->create(['is_active' => false]))->get(route('offers.index', $this->case))->assertRedirect(route('login'));
        $this->get('/request-quote')->assertOk()->assertDontSee('Q-2026-101');
        DB::beginTransaction();
        try {
            DB::table('vendor_offer_revisions')->where('id', $r->id)->update(['quoted_total' => '1']);
            $this->fail('Expected database protection');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_manual_review_can_resolve_unknowns_without_ai(): void
    {
        $o = $this->capture();
        $r = app(ManageOffer::class)->save($o, $this->staff, $this->data($o, '1050', null), true);
        $this->get(route('offers.ai.scope', [$this->case, $o]))->assertOk()->assertSee('Live AI is disabled');
        $p = $this->data($o);
        $this->patch(route('offers.save', [$this->case, $o]), $p + ['intent' => 'review'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('reviewed_complete', $o->fresh()->current()->status);
        $this->assertSame('superseded', $r->fresh()->status);
    }

    public function test_unsupported_ai_values_cannot_be_accepted_and_corrected_decisions_create_only_draft(): void
    {
        $o = $this->capture();
        $r = app(ManageOffer::class)->save($o, $this->staff, $this->data($o), false);
        $sources = OfferProposal::sources($r);
        $result = ['proposals' => [['field' => 'lines.0.rate', 'value' => '7', 'source_id' => $sources[0]['id'], 'snippet' => 'Not in the source', 'uncertainty' => '']]];
        $run = AiRun::factory()->create(['inquiry_id' => $this->case->id, 'purpose' => 'vendor_quotation', 'vendor_offer_revision_id' => $r->id, 'shipment_hash' => $this->case->snapshotHash(), 'sources' => $sources, 'state' => 'needs_review', 'result' => $result]);
        $this->post(route('offers.ai.apply', [$this->case, $o, $run]), ['expected_revision' => 1, 'decisions' => [['decision' => 'accept']]])->assertSessionHasErrors('processing');
        $this->post(route('offers.ai.apply', [$this->case, $o, $run]), ['expected_revision' => 1, 'decisions' => [['decision' => 'correct', 'value' => '1050', 'reason' => 'Original note explicitly states MYR 1050']]])->assertSessionHasNoErrors();
        $current = $o->fresh()->current();
        $this->assertSame('needs_review', $current->status);
        $this->assertNull($current->complete_total);
        $this->assertSame('1050', $current->payload['lines'][0]['rate']);
        $this->assertSame($run->id, $current->payload['proposal_review']['run_id']);
        $this->assertTrue($run->fresh()->stale());
    }

    public function test_single_usable_offer_has_lowest_label_and_selection_requires_reason(): void
    {
        $o = $this->capture();
        app(ManageOffer::class)->save($o, $this->staff, $this->data($o), true);
        $comp = $this->comparison();
        $this->assertTrue(OfferEligibility::matrix($this->case, $comp)[0]['lowest']);
        $this->post(route('offers.select', $this->case), ['offer_revision_id' => $o->fresh()->current()->id, 'comparison_id' => $comp->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => ''])->assertSessionHasErrors('reason');
    }

    public function test_quote_provider_uses_shared_budget_strict_schema_and_never_approves_costs(): void
    {
        $offer = $this->capture();
        $revision = app(ManageOffer::class)->save($offer, $this->staff, $this->data($offer), false);
        config(['ai.key' => 'fictional-test-key']);
        AiSetting::current()->update(['enabled' => true, 'model' => 'fictional-test-model', 'configuration' => ['input_rate' => '1', 'output_rate' => '2', 'cached_rate' => '0.5', 'rate_version' => 'fixture-1', 'rate_date' => '2026-10-05', 'structured_verified' => true, 'run_cap' => '1', 'inquiry_cap' => '5', 'daily_cap' => '10'], 'model_check' => ['model' => 'fictional-test-model', 'state' => 'accessible']]);
        $settings = AiSetting::current();
        $sources = OfferProposal::sources($revision);
        $scope = AiUsage::scope($sources, $settings, 'vendor_quotation');
        $run = app(StartOfferProposals::class)->handle($offer, $this->staff, 1, $scope, array_column($sources, 'id'));
        $this->assertSame($run->id, app(StartOfferProposals::class)->handle($offer, $this->staff, 1, $scope, array_column($sources, 'id'))->id);
        $this->assertSame('vendor_quotation', $run->purpose);
        $result = ['proposals' => [['field' => 'lines.0.rate', 'value' => '1050', 'source_id' => $sources[0]['id'], 'snippet' => 'Freight MYR 1050.', 'uncertainty' => '']]];
        Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_quote_fixture', 'status' => 'completed', 'model' => 'fictional-test-model', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]], 200)]);
        (new RequestAiProposals($run->id))->handle(app(OpenAiResponses::class));
        $this->assertSame('needs_review', $run->fresh()->state);
        $this->assertTrue($run->fresh()->proposals[0]['supported']);
        $this->assertSame('0.00020000', $run->fresh()->estimated_cost);
        Http::assertSent(function (Request $request): bool {
            return $request['store'] === false && $request['tools'] === [] && $request['text']['format']['name'] === 'vendor_quotation_v1' && $request['text']['format']['strict'] === true;
        });
        $this->assertNull($offer->fresh()->current()->complete_total);
        Mail::assertNothingSent();
    }

    public function test_queued_quote_proposals_release_reservation_when_offer_changes(): void
    {
        $offer = $this->capture();
        $revision = app(ManageOffer::class)->save($offer, $this->staff, $this->data($offer), false);
        config(['ai.key' => 'fictional-test-key']);
        AiSetting::current()->update(['enabled' => true, 'model' => 'fictional-test-model', 'configuration' => ['input_rate' => '1', 'output_rate' => '2', 'rate_version' => 'fixture-1', 'rate_date' => '2026-10-05', 'structured_verified' => true, 'run_cap' => '1', 'inquiry_cap' => '5', 'daily_cap' => '10'], 'model_check' => ['model' => 'fictional-test-model', 'state' => 'accessible']]);
        $settings = AiSetting::current();
        $sources = OfferProposal::sources($revision);
        $run = app(StartOfferProposals::class)->handle($offer, $this->staff, 1, AiUsage::scope($sources, $settings, 'vendor_quotation'), array_column($sources, 'id'));
        app(ManageOffer::class)->save($offer, $this->staff, $this->data($offer, '1100'), false);
        (new RequestAiProposals($run->id))->handle(app(OpenAiResponses::class));
        $this->assertSame('unavailable', $run->fresh()->state);
        $this->assertSame('0.00000000', $run->fresh()->reservation);
        Http::assertNothingSent();
    }

    public function test_professional_preview_is_idempotent_labelled_and_keeps_legacy_evidence(): void
    {
        Storage::fake('inquiry_documents');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $legacy = Vendor::factory()->create(['company_name' => 'Legacy (Demo)']);
        $legacy->contacts()->create(['name' => 'Old desk', 'email' => 'old@example.test', 'is_active' => true, 'is_primary' => true]);
        $this->artisan('lrs:sample-workspace', ['--apply' => true])->assertSuccessful();
        $this->assertFalse($legacy->fresh()->is_active);
        $count = VendorOffer::count();
        $this->artisan('lrs:sample-workspace', ['--apply' => true])->assertSuccessful();
        $this->assertSame($count, VendorOffer::count());
        $this->assertSame(5, Inquiry::where('sample_set', WorkspaceData::SAMPLE_SET)->count());
        $this->get('/overview')->assertOk()->assertSee('Business preview')->assertDontSee('Array to string');
        $this->get('/inquiries')->assertOk()->assertSee('Precision components')->assertDontSee('Local shipment inquiry');
        $this->get('/mail')->assertOk()->assertSee('Business preview');
        $this->assertNotSame(0, PublicSubmission::count());
        $this->assertNotSame(0, DocumentRun::count());
        $this->assertDatabaseCount('mail_dispatches', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->artisan('lrs:sample-workspace', ['--real' => true])->assertSuccessful();
        $this->get('/inquiries')->assertOk()->assertDontSee('Precision components');
        $this->assertSame($count, VendorOffer::count());
    }

    public function test_changed_comparison_and_confirmed_shipment_block_pricing_without_rewriting_history(): void
    {
        $offer = $this->capture();
        $r = app(ManageOffer::class)->save($offer, $this->staff, $this->data($offer), true);
        $comparison = $this->comparison();
        $s = app(ManageOffer::class)->select($this->case, $this->staff, ['offer_revision_id' => $r->id, 'comparison_id' => $comparison->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => 'Complete comparable vendor offer']);
        $oldDigest = $s->digest;
        app(ManageOffer::class)->comparison($this->case, $this->staff, ['expected_comparison' => $comparison->id, 'currency' => 'USD', 'reason' => 'New explicit indicative comparison', 'fx' => ['MYR' => ['from' => 'MYR', 'to' => 'USD', 'rate' => '4.5', 'direction' => 'divide', 'date' => '2026-10-05', 'source' => 'Fictional reverse FX fixture', 'confirmed' => '1']]]);
        $this->assertStringContainsString('stale', implode(' ', OfferEligibility::selectionReasons($s)));
        $this->case->update(['status' => 'needs_review', 'shipment_revision' => 2]);
        $this->assertStringContainsString('shipment', implode(' ', OfferEligibility::selectionReasons($s)));
        $this->assertSame($oldDigest, $s->fresh()->digest);
        $this->expectException(ValidationException::class);
        OfferEligibility::pricingBasis($s);
    }

    public function test_revised_vendor_source_supersedes_without_rebinding_or_destroying_selection(): void
    {
        $offer = $this->capture();
        $revision = app(ManageOffer::class)->save($offer, $this->staff, $this->data($offer), true);
        $comparison = $this->comparison();
        $selection = app(ManageOffer::class)->select($this->case, $this->staff, ['offer_revision_id' => $revision->id, 'comparison_id' => $comparison->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => 'Complete scope and reviewed delivery']);
        $digest = $selection->digest;
        $original = $offer->source;
        $replacement = app(ManageOffer::class)->capture($this->case, $this->staff, ['rfq_revision_id' => $offer->rfq_revision_id, 'alternative' => 'Revised sailing', 'supersedes_offer_id' => $offer->id, 'source_kind' => 'manual', 'manual_text' => 'Revised vendor quotation: freight MYR 1100 plus delivery MYR 100.', 'association_confirmed' => '1', 'association_reason' => 'Vendor expressly replaced the previous quotation.']);
        $this->assertSame($replacement->id, $offer->fresh()->supersededBy()->id);
        $this->assertSame('superseded', $revision->fresh()->status);
        $this->assertSame(Processing::hash($original), Processing::hash($offer->fresh()->source));
        $this->assertSame($digest, $selection->fresh()->digest);
        $this->assertStringContainsString('revised quotation', implode(' ', OfferEligibility::selectionReasons($selection)));
        $this->get(route('offers.edit', [$this->case, $offer]))->assertOk()->assertSee('Historical commercial record')->assertDontSee('Record commercial review');
        $this->patch(route('offers.save', [$this->case, $offer]), $this->data($offer) + ['intent' => 'review'])->assertSessionHasErrors('processing');
        $this->assertSame(1, $offer->fresh()->current_number);
        app(ManageOffer::class)->save($replacement, $this->staff, $this->data($replacement, '1100'), true);
        $rows = OfferEligibility::matrix($this->case, $comparison);
        $this->assertFalse($rows[0]['lowest']);
        $this->assertTrue($rows[1]['lowest']);
        Mail::assertNothingSent();
    }

    public function test_customer_invoice_is_rejected_and_changed_vendor_file_blocks_current_pricing(): void
    {
        Storage::fake('inquiry_documents');
        $offer = $this->capture();
        $contents = "Freight,1050\nDelivery,100\n";
        Storage::disk('inquiry_documents')->put('vendor/quote.csv', $contents);
        $document = InquiryDocument::factory()->create(['inquiry_id' => $this->case->id, 'classification' => 'invoice', 'original_name' => 'quote.csv', 'storage_path' => 'vendor/quote.csv', 'checksum' => hash('sha256', $contents), 'mime' => 'text/csv', 'size' => strlen($contents)]);
        $capture = ['rfq_revision_id' => $offer->rfq_revision_id, 'alternative' => 'Private vendor quotation', 'source_kind' => 'document', 'document_ids' => [$document->id], 'association_confirmed' => '1', 'association_reason' => 'Reviewed source is the vendor commercial quotation'];
        $this->post(route('offers.store', $this->case), $capture)->assertSessionHasErrors('processing');
        $this->assertDatabaseCount('vendor_offers', 1);
        $document->update(['classification' => 'freight_quote']);
        $quoted = app(ManageOffer::class)->capture($this->case, $this->staff, $capture);
        $revision = app(ManageOffer::class)->save($quoted, $this->staff, $this->data($quoted), true);
        $comparison = $this->comparison();
        $selection = app(ManageOffer::class)->select($this->case, $this->staff, ['offer_revision_id' => $revision->id, 'comparison_id' => $comparison->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => 'Reviewed original quotation and complete scope']);
        $digest = $selection->digest;
        Storage::disk('inquiry_documents')->put('vendor/quote.csv', 'Unexpected changed file bytes');
        $this->assertStringContainsString('evidence', implode(' ', OfferEligibility::selectionReasons($selection)));
        $this->assertSame($digest, $selection->fresh()->digest);
        $this->assertFalse(OfferEligibility::matrix($this->case, $comparison)[0]['lowest']);
        Storage::disk('inquiry_documents')->put('vendor/quote.csv', $contents);
        $document->update(['is_archived' => true]);
        $this->assertStringContainsString('evidence', implode(' ', OfferEligibility::selectionReasons($selection)));
        $this->expectException(ValidationException::class);
        OfferEligibility::pricingBasis($selection);
    }

    public function test_stale_validation_preserves_submitted_revision_and_never_refreshes_lock_silently(): void
    {
        $offer = $this->capture();
        $first = $this->data($offer);
        app(ManageOffer::class)->save($offer, $this->staff, $first, true);
        $url = route('offers.edit', [$this->case, $offer]);
        $this->from($url)->patch(route('offers.save', [$this->case, $offer]), $first + ['intent' => 'review'])->assertSessionHasErrors('processing');
        $this->get($url)->assertOk()->assertSee('name="expected_revision" value="0"', false);
        $this->assertSame(1, $offer->fresh()->current_number);
    }

    public function test_numeric_proposal_requires_its_value_in_the_exact_source_snippet(): void
    {
        $offer = $this->capture();
        $revision = app(ManageOffer::class)->save($offer, $this->staff, $this->data($offer), false);
        $sources = OfferProposal::sources($revision);
        $result = ['proposals' => [['field' => 'lines.0.rate', 'value' => '9999', 'source_id' => $sources[0]['id'], 'snippet' => 'Freight MYR 1050.', 'uncertainty' => '']]];
        $run = AiRun::factory()->create(['inquiry_id' => $this->case->id, 'purpose' => 'vendor_quotation', 'vendor_offer_revision_id' => $revision->id, 'shipment_hash' => $this->case->snapshotHash(), 'sources' => $sources, 'state' => 'needs_review', 'result' => $result]);
        $this->assertFalse(OfferProposal::inspect($run, $result)[0]['supported']);
        $this->post(route('offers.ai.apply', [$this->case, $offer, $run]), ['expected_revision' => 1, 'decisions' => [['decision' => 'accept']]])->assertSessionHasErrors('processing');
        $this->assertSame(1, $offer->fresh()->current_number);
        $result['proposals'][0]['value'] = '1050.00';
        $this->assertTrue(OfferProposal::inspect($run, $result)[0]['supported']);
        Http::assertNothingSent();
    }

    public function test_unconfirmed_fx_change_has_readable_error_and_preserves_saved_comparison(): void
    {
        $offer = $this->capture();
        app(ManageOffer::class)->save($offer, $this->staff, $this->data($offer), true);
        $comparison = $this->comparison();
        $this->post(route('offers.comparison', $this->case), ['expected_comparison' => $comparison->id, 'currency' => 'USD', 'reason' => 'Review a new explicit conversion', 'fx' => ['MYR' => ['from' => 'MYR', 'to' => 'USD', 'rate' => '4.5', 'direction' => 'divide', 'date' => now()->toDateString(), 'source' => 'Fictional reverse FX fixture']]])->assertSessionHasErrors(['fx.MYR.confirmed' => 'The exchange-rate review confirmation field is required.']);
        $this->assertSame($comparison->id, OfferEligibility::currentComparison($this->case)->id);
        $this->assertDatabaseCount('offer_comparisons', 1);
    }
}
