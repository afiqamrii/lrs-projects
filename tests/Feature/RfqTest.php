<?php

namespace Tests\Feature;

use App\Actions\ManageRfq;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\Rfq;
use App\Models\RfqApproval;
use App\Models\RfqDispatch;
use App\Models\SourcingRound;
use App\Models\User;
use App\Models\Vendor;
use App\Support\InquiryWorkflow;
use App\Support\Processing;
use App\Support\RfqContent;
use App\Support\RfqEligibility;
use App\Support\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RfqTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Inquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('inquiry_documents');
        $this->staff = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->actingAs($this->staff);
        CompanySetting::current()->update(['rfq_reply_name' => 'Synthetic LRS sourcing desk', 'rfq_reply_email' => 'sourcing@example.test', 'rfq_signature' => "Synthetic LRS\nSourcing desk\nsourcing@example.test"]);
        $client = Client::factory()->create();
        $contact = ClientContact::factory()->create(['client_id' => $client->id]);
        $this->inquiry = Inquiry::factory()->create(['client_id' => $client->id, 'client_contact_id' => $contact->id, 'owner_id' => $this->staff->id, 'response_due_at' => now()->addDays(3), 'shipment' => $this->cargo(), 'status' => 'needs_review']);
    }

    private function cargo(): array
    {
        return Shipment::normalize(['mode' => 'LCL', 'scope' => 'port_to_port', 'cargo_description' => 'Synthetic general machine parts', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore port', 'cargo_ready_date' => '2026-11-10', 'arrival_date' => '2026-11-20', 'incoterm' => 'FOB', 'named_place' => 'Port Klang', 'goods_value' => '12000', 'goods_currency' => 'USD', 'budget' => '900', 'budget_currency' => 'USD', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 2, 'gross_weight' => '250.5', 'weight_unit' => 'kg', 'length' => '100', 'width' => '80', 'height' => '90', 'dimension_unit' => 'cm']]]);
    }

    private function confirm(): void
    {
        $this->post(route('inquiries.transition', $this->inquiry), ['lock_version' => $this->inquiry->fresh()->lock_version, 'target' => 'ready_for_sourcing'])->assertSessionHasNoErrors();
        $this->inquiry = $this->inquiry->fresh();
        $this->assertTrue($this->inquiry->eligible());
    }

    private function vendor(string $name = 'Synthetic vendor', ?string $email = null): Vendor
    {
        $vendor = Vendor::factory()->create(['company_name' => $name, 'services' => ['LCL', 'FCL']]);
        $vendor->contacts()->create(['name' => 'Quotation desk '.$vendor->id, 'email' => $email ?? 'vendor'.$vendor->id.'@example.test', 'is_primary' => true, 'is_active' => true]);

        return $vendor;
    }

    private function select(array $vendors): void
    {
        $this->post(route('inquiries.sourcing.select', $this->inquiry), ['lock_version' => $this->inquiry->fresh()->lock_version, 'vendor_ids' => array_map(fn (Vendor $v): int => $v->id, $vendors)])->assertSessionHasNoErrors();
    }

    private function data(Rfq $rfq, array $extra = []): array
    {
        $p = $rfq->current()->payload;

        return array_replace(['expected_revision' => $rfq->fresh()->current_number, 'to_contact_id' => $p['to']['id'], 'cc_contact_ids' => array_column($p['cc'], 'id'), 'subject' => $p['subject'], 'opening' => $p['opening'], 'closing' => $p['closing'], 'vendor_notes' => $p['vendor_notes'], 'alternative_notes' => $p['alternative_notes'], 'response_due_at' => InquiryWorkflow::local(now()->addDay()), 'currency' => 'USD', 'document_ids' => array_column($p['manifest'], 'document_id'), 'attachments_reviewed' => '1', 'disclose_identity' => '0', 'disclose_addresses' => '0', 'disclosure_notes' => $p['disclosure_notes'], 'limitation_reason' => $p['limitation_reason'], 'shared_email_reason' => $p['shared_email_reason'], 'deadline_reason' => $p['deadline_reason']], $extra);
    }

    private function prepared(): Rfq
    {
        $this->confirm();
        $this->select([$this->vendor()]);
        $rfq = Rfq::firstOrFail();
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq))->assertSessionHasNoErrors();

        return $rfq->fresh();
    }

    private function approval(Rfq $rfq): RfqApproval
    {
        $revision = $rfq->fresh()->current();
        $this->post(route('rfqs.approve', [$this->inquiry, $rfq]), ['expected_revision' => $revision->number, 'digest' => Processing::hash(RfqContent::snapshot($revision)), 'approve_exact' => '1'])->assertSessionHasNoErrors();

        return $revision->approval()->firstOrFail();
    }

    private function document(?Inquiry $case = null, string $classification = 'packing_list'): InquiryDocument
    {
        $case ??= $this->inquiry;
        $content = "%PDF-1.4\nSynthetic disclosure ".Str::uuid()."\n%%EOF";
        $path = $case->id.'/'.Str::uuid().'.pdf';
        Storage::disk('inquiry_documents')->put($path, $content);

        return InquiryDocument::factory()->create(['inquiry_id' => $case->id, 'storage_path' => $path, 'checksum' => hash('sha256', $content), 'size' => strlen($content), 'classification' => $classification, 'original_name' => 'synthetic-packing-list.pdf', 'mime' => 'application/pdf']);
    }

    private function manualData(Rfq $rfq, RfqApproval $approval): array
    {
        return ['expected_revision' => $rfq->fresh()->current_number, 'digest' => $approval->digest, 'action_key' => (string) Str::uuid(), 'confirm_exact' => '1', 'sent_at' => InquiryWorkflow::local(now()), 'channel' => 'email', 'recipient' => $approval->snapshot['to']['email'], 'evidence' => 'Synthetic acceptance declaration; no real message sent'];
    }

    public function test_incomplete_specialist_and_unauthorized_cases_cannot_create_or_approve_requests(): void
    {
        $vendor = $this->vendor();
        $this->get(route('inquiries.sourcing', $this->inquiry))->assertOk()->assertSee('Confirm requirements');
        $this->post(route('inquiries.sourcing.select', $this->inquiry), ['lock_version' => 0, 'vendor_ids' => [$vendor->id]])->assertSessionHasErrors('vendor_ids');
        $this->assertSame(0, Rfq::count());
        $s = $this->cargo();
        $s['special_flags'] = ['dangerous'];
        $this->inquiry->update(['shipment' => $s]);
        $this->post(route('inquiries.transition', $this->inquiry), ['lock_version' => 0, 'target' => 'ready_for_sourcing'])->assertSessionHasErrors();
        $this->actingAs(User::factory()->create(['is_active' => false]))->get(route('inquiries.sourcing', $this->inquiry))->assertRedirect('/login');
        $this->post(route('inquiries.sourcing.select', $this->inquiry), ['lock_version' => 0, 'vendor_ids' => [$vendor->id]])->assertRedirect('/login');
    }

    public function test_three_vendors_receive_isolated_idempotent_drafts_with_one_baseline_and_no_mail(): void
    {
        $this->confirm();
        $vendors = [$this->vendor('Synthetic Alpha'), $this->vendor('Synthetic Beta'), $this->vendor('Synthetic Gamma')];
        $this->select($vendors);
        $this->select($vendors);
        $this->assertSame(1, SourcingRound::count());
        $this->assertSame(3, Rfq::count());
        $facts = [];
        foreach (Rfq::all() as $rfq) {
            $rev = $rfq->current();
            $snapshot = RfqContent::snapshot($rev);
            $facts[] = RfqContent::facts($rfq, $rev->payload);
            $this->assertSame($rfq->vendor->primaryContact->email, $snapshot['to']['email']);
            $this->assertSame([], $snapshot['cc']);
            $this->assertSame([], $snapshot['manifest']);
            foreach ($vendors as $other) {
                if ($other->id !== $rfq->vendor_id) {
                    $this->assertStringNotContainsString($other->company_name, $snapshot['body']);
                    $this->assertStringNotContainsString($other->primaryContact->email, $snapshot['body']);
                }
            }
            $this->assertStringContainsString('250.5 kg', $snapshot['body']);
            $this->assertStringContainsString('1.44 m³', $snapshot['body']);
            $this->assertStringContainsString('FOB · Port Klang', $snapshot['body']);
            $this->assertStringContainsString('Quote validity', $snapshot['body']);
            $this->assertStringNotContainsString('12000', $snapshot['body']);
            $this->assertStringNotContainsString('900', $snapshot['body']);
        }
        $this->assertCount(1, array_unique($facts));
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->get(route('inquiries.sourcing', $this->inquiry).'?q=Synthetic Beta')->assertOk()->assertSee('Synthetic Beta');
    }

    public function test_recipient_relationships_header_injection_cc_duplicates_and_competitor_disclosure_are_rejected(): void
    {
        $rfq = $this->prepared();
        $other = $this->vendor('Distinct competitor');
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['cc_contact_ids' => [$other->primaryContact->id]]))->assertSessionHasErrors('to_contact_id');
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['cc_contact_ids' => [$rfq->vendor->primaryContact->id]]))->assertSessionHasErrors('cc_contact_ids');
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['subject' => "RFQ\r\nBCC: outsider@example.test"]))->assertSessionHasErrors('subject');
        $cc = $rfq->vendor->contacts()->create(['name' => 'CC desk', 'email' => 'CC@EXAMPLE.TEST', 'is_active' => true, 'is_primary' => false]);
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['cc_contact_ids' => [$cc->id], 'opening' => '<script>unsafe()</script> Please quote.']))->assertSessionHasNoErrors();
        $rfq = $rfq->fresh();
        $this->assertSame('cc@example.test', $rfq->current()->payload['cc'][0]['email']);
        $this->assertStringNotContainsString('<script>', RfqContent::snapshot($rfq->current())['html']);
        $this->select([$other]);
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['vendor_notes' => 'Compare against Distinct competitor please']))->assertSessionHasNoErrors();
        $rev = $rfq->fresh()->current();
        $this->post(route('rfqs.approve', [$this->inquiry, $rfq]), ['expected_revision' => $rev->number, 'digest' => Processing::hash(RfqContent::snapshot($rev)), 'approve_exact' => 1])->assertSessionHasErrors('eligibility');
    }

    public function test_shared_email_deadline_and_recorded_service_conflicts_require_deliberate_resolution(): void
    {
        $this->confirm();
        $a = $this->vendor('Separate Alpha', 'shared@example.test');
        $b = $this->vendor('Separate Beta', 'shared@example.test');
        $a->update(['services' => ['FCL']]);
        $this->select([$a, $b]);
        $rfq = Rfq::where('vendor_id', $a->id)->firstOrFail();
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['response_due_at' => InquiryWorkflow::local($this->inquiry->response_due_at->addMinute())]))->assertSessionHasNoErrors();
        $reasons = implode(' ', RfqEligibility::reasons($rfq->fresh()->current()));
        $this->assertStringContainsString('shared', $reasons);
        $this->assertStringContainsString('capability', $reasons);
        $this->assertStringContainsString('deadline', $reasons);
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq->fresh(), ['response_due_at' => InquiryWorkflow::local($this->inquiry->response_due_at->addMinute()), 'shared_email_reason' => 'Separate companies use a shared service desk; deliberately request separate quotations.', 'limitation_reason' => 'Agent checked LCL capability; directory owner will update recorded services.', 'deadline_reason' => 'Client agreed that review will happen after this target.']))->assertSessionHasNoErrors();
        $this->approval($rfq->fresh());
    }

    public function test_approval_freezes_content_and_is_idempotent_copy_does_not_mark_sent(): void
    {
        $rfq = $this->prepared();
        $approval = $this->approval($rfq);
        $this->approval($rfq);
        $this->assertSame(1, RfqApproval::count());
        $this->assertSame($this->staff->id, $approval->approved_by);
        $this->assertSame(Processing::hash($approval->snapshot), $approval->digest);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertOk()->assertSee('Approved — not sent')->assertSee('Copy body');
        $this->get(route('rfqs.review', [$this->inquiry, $rfq]))->assertOk()->assertSee($approval->digest);
        $this->assertSame(0, RfqDispatch::count());
        CompanySetting::current()->update(['rfq_signature' => 'Changed future company signature', 'rfq_reply_email' => 'new@example.test']);
        $this->assertSame('sourcing@example.test', $approval->fresh()->snapshot['company']['reply_email']);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertOk()->assertDontSee('Changed future company signature');
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_stale_second_agent_approval_cannot_authorize_newer_content_and_revision_history_survives(): void
    {
        $rfq = $this->prepared();
        $old = $rfq->current();
        $digest = Processing::hash(RfqContent::snapshot($old));
        $second = User::factory()->create(['role' => 'agent']);
        $this->actingAs($second)->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['opening' => 'Please review this newly edited request.']))->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post(route('rfqs.approve', [$this->inquiry, $rfq]), ['expected_revision' => $old->number, 'digest' => $digest, 'approve_exact' => 1])->assertSessionHasErrors('expected_revision');
        $this->assertSame(0, RfqApproval::count());
        $this->assertSame('superseded', $old->fresh()->status);
        $this->get(route('rfqs.review', [$this->inquiry, $rfq]))->assertOk()->assertSee('What changed')->assertSee('Please review this newly edited request.');
        $this->get(route('rfqs.review', [$this->inquiry, $rfq, 'revision' => $old->number]))->assertOk()->assertSee($old->payload['opening']);
        $this->approval($rfq->fresh());
        $this->assertSame(3, $rfq->revisions()->count());
    }

    public function test_only_explicit_same_case_attachments_are_frozen_and_new_uploads_do_not_join(): void
    {
        $rfq = $this->prepared();
        $doc = $this->document();
        $foreign = $this->document(Inquiry::factory()->create());
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['document_ids' => [$foreign->id], 'disclosure_notes' => 'Necessary packing list']))->assertSessionHasErrors('document_ids');
        $freight = $this->document(classification: 'freight_quote');
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['document_ids' => [$freight->id], 'disclosure_notes' => 'Old customer quote']))->assertSessionHasErrors('document_ids');
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['document_ids' => [$doc->id], 'disclosure_notes' => 'Packing dimensions are necessary; reviewed client data disclosure.']))->assertSessionHasNoErrors();
        $rfq = $rfq->fresh();
        $approval = $this->approval($rfq);
        $extra = $this->document();
        $this->assertCount(1, $approval->snapshot['manifest']);
        $this->assertSame($doc->checksum, $approval->snapshot['manifest'][0]['checksum']);
        $this->get(route('rfqs.attachment', [$this->inquiry, $rfq, $doc->id]))->assertOk();
        $this->get(route('rfqs.attachment', [$this->inquiry, $rfq, $extra->id]))->assertNotFound();
        $this->patch(route('inquiries.documents.update', [$this->inquiry, $doc]), ['classification' => 'invoice', 'is_archived' => false])->assertSessionHasNoErrors();
        $this->assertSame(2, $doc->fresh()->version);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertSessionHasErrors('eligibility');
        $this->get(route('rfqs.attachment', [$this->inquiry, $rfq, $doc->id]))->assertSessionHasErrors('eligibility');
        $this->assertSame('packing_list', $approval->fresh()->snapshot['manifest'][0]['classification']);
    }

    public function test_missing_or_changed_bytes_and_preparation_limit_block_approval_release(): void
    {
        $rfq = $this->prepared();
        $doc = $this->document();
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['document_ids' => [$doc->id], 'disclosure_notes' => 'Required packing list']))->assertSessionHasNoErrors();
        $rfq = $rfq->fresh();
        $this->approval($rfq);
        Storage::disk('inquiry_documents')->put($doc->storage_path, 'changed bytes');
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertSessionHasErrors('eligibility');
        Storage::disk('inquiry_documents')->delete($doc->storage_path);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertSessionHasErrors('eligibility');
        $this->assertSame(1, RfqApproval::count());
    }

    public function test_manual_send_is_truthful_idempotent_and_prevents_future_automatic_resend(): void
    {
        $rfq = $this->prepared();
        $approval = $this->approval($rfq);
        $data = $this->manualData($rfq, $approval);
        $this->assertTrue(RfqEligibility::automaticDispatchAllowed($rfq->current()));
        $this->post(route('rfqs.manual', [$this->inquiry, $rfq]), array_replace($data, ['recipient' => 'other@example.test']))->assertSessionHasErrors('recipient');
        $this->post(route('rfqs.manual', [$this->inquiry, $rfq]), array_replace($data, ['sent_at' => InquiryWorkflow::local(now()->addDay())]))->assertSessionHasErrors('sent_at');
        $this->post(route('rfqs.manual', [$this->inquiry, $rfq]), $data)->assertSessionHasNoErrors();
        $this->post(route('rfqs.manual', [$this->inquiry, $rfq]), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, RfqDispatch::count());
        $this->assertFalse(RfqEligibility::automaticDispatchAllowed($rfq->fresh()->current()));
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertOk()->assertSee('Manually recorded as sent')->assertSee('Provider delivery or reading is not confirmed');
        $this->post(route('rfqs.manual', [$this->inquiry, $rfq]), array_replace($data, ['action_key' => (string) Str::uuid()]))->assertSessionHasErrors('manual_send');
        $this->post(route('rfqs.state', [$this->inquiry, $rfq]), ['expected_revision' => $rfq->current_number, 'target' => 'cancelled', 'reason' => 'Cannot undo external send'])->assertSessionHasErrors('target');
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['opening' => 'Deliberate revised request for another dispatch.', 'change_reason' => 'Deliberate resend revision']))->assertSessionHasNoErrors();
        $this->assertSame(1, RfqDispatch::count());
        $this->approval($rfq->fresh());
        $this->assertTrue(RfqEligibility::automaticDispatchAllowed($rfq->fresh()->current()));
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function inquiryData(array $extra = []): array
    {
        $i = $this->inquiry->fresh();

        return array_replace(['client_id' => $i->client_id, 'client_contact_id' => $i->client_contact_id, 'title' => $i->title, 'owner_id' => $i->owner_id, 'priority' => $i->priority, 'response_due_at' => InquiryWorkflow::local($i->response_due_at), 'shipment' => $i->shipment, 'internal_notes' => $i->internal_notes, 'lock_version' => $i->lock_version], $extra);
    }

    public function test_material_quantity_change_blocks_old_release_preserves_manual_send_and_uses_a_new_round(): void
    {
        $rfq = $this->prepared();
        $approval = $this->approval($rfq);
        $this->post(route('rfqs.manual', [$this->inquiry, $rfq]), $this->manualData($rfq, $approval))->assertSessionHasNoErrors();
        $s = $this->inquiry->shipment;
        $s['packages'][0]['quantity'] = 3;
        $this->patch(route('inquiries.update', $this->inquiry), $this->inquiryData(['shipment' => $s]))->assertSessionHasNoErrors();
        $this->assertSame('draft', $this->inquiry->fresh()->status);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertSessionHasErrors('eligibility');
        $this->assertSame(1, RfqDispatch::count());
        $this->assertSame(2, $approval->snapshot['revision']);
        $this->post(route('inquiries.transition', $this->inquiry), ['lock_version' => $this->inquiry->fresh()->lock_version, 'target' => 'needs_review'])->assertSessionHasNoErrors();
        $this->confirm();
        $this->select([$rfq->vendor]);
        $this->assertSame(2, SourcingRound::count());
        $this->assertSame(2, Rfq::count());
        $this->assertSame(2, $this->inquiry->versions()->count());
        $this->assertStringContainsString('2 pallets', $approval->fresh()->snapshot['body']);
        $this->assertStringContainsString('3 pallets', RfqContent::snapshot(Rfq::latest('id')->first()->current())['body']);
    }

    public function test_nonmaterial_notes_keep_approval_but_hold_and_recipient_deactivation_block_release(): void
    {
        $rfq = $this->prepared();
        $approval = $this->approval($rfq);
        $this->patch(route('inquiries.update', $this->inquiry), $this->inquiryData(['internal_notes' => 'Internal note stays internal']))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inquiry->fresh()->shipment_revision);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertOk()->assertDontSee('Internal note stays internal');
        $contact = $rfq->vendor->primaryContact;
        $contact->update(['is_active' => false]);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertSessionHasErrors('eligibility');
        $contact->update(['is_active' => true, 'email' => 'changed@example.test']);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertSessionHasErrors('eligibility');
        $this->assertNotSame($contact->fresh()->email, $approval->fresh()->snapshot['to']['email']);
        $rfq->vendor->update(['is_active' => false]);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertSessionHasErrors('eligibility');
        $this->post(route('inquiries.transition', $this->inquiry), ['lock_version' => $this->inquiry->fresh()->lock_version, 'target' => 'on_hold', 'reason' => 'Review paused'])->assertSessionHasNoErrors();
        $this->assertSame(1, RfqApproval::count());
    }

    public function test_fcl_scope_addresses_and_units_are_correct_and_identity_requires_explicit_disclosure(): void
    {
        $s = $this->cargo();
        $s['mode'] = 'FCL';
        $s['scope'] = 'door_to_port';
        $s['pickup_address'] = 'Synthetic pickup warehouse, Klang';
        $s['services'] = ['pickup'];
        $s['packages'] = [];
        $s['containers'] = [['type' => '40HC', 'quantity' => 2, 'gross_weight' => '18', 'weight_unit' => 't']];
        $this->inquiry->update(['shipment' => $s]);
        $rfq = $this->prepared();
        $this->assertStringContainsString('address disclosure', implode(' ', RfqEligibility::reasons($rfq->current())));
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['disclose_addresses' => 1, 'disclose_identity' => 1, 'disclosure_notes' => 'Pickup requires client warehouse identity and address.']))->assertSessionHasNoErrors();
        $rfq = $rfq->fresh();
        $approval = $this->approval($rfq);
        $this->assertStringContainsString('2 × 40HC; cargo gross weight 18 t', $approval->snapshot['body']);
        $this->assertStringContainsString('18000 kg', $approval->snapshot['body']);
        $this->assertStringNotContainsString('Package group', $approval->snapshot['body']);
        $this->assertStringContainsString('Door to port', $approval->snapshot['body']);
        $this->assertStringContainsString('Synthetic pickup warehouse', $approval->snapshot['body']);
        $this->assertStringContainsString($this->inquiry->client->company_name, $approval->snapshot['body']);
    }

    public function test_saved_revisions_approvals_and_dispatches_are_database_immutable_and_approval_audit_rolls_back(): void
    {
        $rfq = $this->prepared();
        $approval = $this->approval($rfq);
        foreach ([fn () => $rfq->current()->update(['payload' => ['changed' => true]]), fn () => $approval->update(['digest' => str_repeat('0', 64)]), fn () => $approval->delete()] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Expected immutable evidence guard');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
        }
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['opening' => 'Another meaningful revision.']))->assertSessionHasNoErrors();
        $rfq = $rfq->fresh();
        AuditEntry::saving(function (AuditEntry $entry): void {
            if ($entry->action === 'Exact RFQ revision approved') {
                throw new \RuntimeException('Synthetic audit persistence failure');
            }
        });
        try {
            app(ManageRfq::class)->approve($rfq, $this->staff, $rfq->current_number, Processing::hash(RfqContent::snapshot($rfq->current())));
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit persistence failure', $e->getMessage());
        } finally {
            AuditEntry::flushEventListeners();
        }
        $this->assertNull($rfq->fresh()->current()->approval);
        $this->assertSame('draft', $rfq->fresh()->current()->status);
        $this->assertSame(1, RfqApproval::count());
    }

    public function test_guests_cross_inquiry_rfqs_and_private_files_are_denied_and_empty_states_are_useful(): void
    {
        $rfq = $this->prepared();
        $this->approval($rfq);
        $other = Inquiry::factory()->create();
        $this->get(route('rfqs.edit', [$other, $rfq]))->assertNotFound();
        $this->get(route('rfqs.output', [$other, $rfq]))->assertNotFound();
        $this->get(route('inquiries.sourcing', $this->inquiry).'?q=nomatchingvendor')->assertOk()->assertSee('No active vendors match');
        auth()->logout();
        foreach (['rfqs.edit', 'rfqs.review', 'rfqs.output', 'rfqs.ai.scope'] as $route) {
            $this->get(route($route, [$this->inquiry, $rfq]))->assertRedirect('/login');
        }
    }

    public function test_prepared_upload_retains_original_relationship_and_is_not_automatically_selected(): void
    {
        $rfq = $this->prepared();
        $original = $this->document();
        $prepared = UploadedFile::fake()->createWithContent('prepared-packing.pdf', "%PDF-1.4\nSynthetic redacted copy\n%%EOF");
        $this->post(route('inquiries.documents.store', $this->inquiry), ['files' => [$prepared], 'classification' => 'packing_list', 'prepared_from_id' => $original->id, 'prepared_note' => 'Manually prepared and reviewed redaction'])->assertSessionHasNoErrors();
        $copy = $this->inquiry->documents()->where('prepared_from_id', $original->id)->firstOrFail();
        $this->assertNotSame($original->checksum, $copy->checksum);
        $this->assertTrue(Storage::disk('inquiry_documents')->exists($original->storage_path));
        $this->assertSame('unscanned', $copy->scan_status);
        $this->assertSame([], $rfq->current()->payload['manifest']);
        $this->get(route('rfqs.edit', [$this->inquiry, $rfq]))->assertOk()->assertSee('Prepared copy of document');
    }

    public function test_review_states_missing_company_recipient_and_acknowledgement_require_recovery(): void
    {
        $rfq = $this->prepared();
        app(ManageRfq::class)->state($rfq, $this->staff, $rfq->current_number, 'changes_requested', 'Clarify the requested alternative wording.');
        $this->assertStringContainsString('Changes were requested', implode(' ', RfqEligibility::reasons($rfq->current())));
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['opening' => 'Revised after the recorded review decision.', 'attachments_reviewed' => false]))->assertSessionHasNoErrors();
        $rfq = $rfq->fresh();
        $this->assertStringContainsString('acknowledge', implode(' ', RfqEligibility::reasons($rfq->current())));
        CompanySetting::current()->update(['rfq_reply_email' => null, 'rfq_signature' => null]);
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['refresh_company' => true]))->assertSessionHasNoErrors();
        $rfq = $rfq->fresh();
        $this->assertStringContainsString('configure', implode(' ', RfqEligibility::reasons($rfq->current())));
        app(ManageRfq::class)->state($rfq, $this->staff, $rfq->current_number, 'cancelled', 'Company configuration missing; cancel future use.');
        $this->assertSame('cancelled', $rfq->fresh()->current()->status);
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq->fresh(), ['opening' => 'Deliberate replacement request after cancellation.']))->assertSessionHasNoErrors();
        $this->assertSame('draft', $rfq->fresh()->current()->status);
        $this->inquiry->owner->update(['is_active' => false]);
        $this->assertStringContainsString('active responsible', implode(' ', RfqEligibility::reasons($rfq->fresh()->current())));
    }

    public function test_deadline_acknowledgement_is_bound_to_current_client_deadline_and_approval_repeat_uses_frozen_evidence(): void
    {
        $rfq = $this->prepared();
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['response_due_at' => InquiryWorkflow::local($this->inquiry->response_due_at->addHour()), 'deadline_reason' => 'Client agreed an exceptional later response target.']))->assertSessionHasNoErrors();
        $rfq = $rfq->fresh();
        $approval = $this->approval($rfq);
        $rfq->vendor->update(['company_name' => 'Renamed future directory company']);
        $this->post(route('rfqs.approve', [$this->inquiry, $rfq]), ['expected_revision' => $rfq->current_number, 'digest' => $approval->digest, 'approve_exact' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, RfqApproval::count());
        $this->inquiry->update(['response_due_at' => $this->inquiry->response_due_at->subHour()]);
        $this->get(route('rfqs.output', [$this->inquiry, $rfq]))->assertSessionHasErrors('eligibility');
    }

    public function test_round_snapshot_ownership_is_enforced_and_preparation_size_limit_is_honest(): void
    {
        $rfq = $this->prepared();
        $another = Inquiry::factory()->create();
        try {
            DB::transaction(fn () => SourcingRound::create(['inquiry_id' => $another->id, 'shipment_version_id' => $rfq->round->shipment_version_id, 'created_by' => $this->staff->id]));
            $this->fail('Expected round ownership constraint');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23503', '23505']);
        }
        $doc = $this->document();
        $this->patch(route('rfqs.save', [$this->inquiry, $rfq]), $this->data($rfq, ['document_ids' => [$doc->id], 'disclosure_notes' => 'Necessary document reviewed.']))->assertSessionHasNoErrors();
        config(['rfq.preparation_limit_kb' => 0]);
        $this->assertStringContainsString('preparation limit', implode(' ', RfqEligibility::reasons($rfq->fresh()->current())));
    }
}
