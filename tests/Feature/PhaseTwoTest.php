<?php

namespace Tests\Feature;

use App\Actions\StoreInquiryDocuments;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Inquiry;
use App\Models\User;
use App\Support\InquiryWorkflow;
use App\Support\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'agent'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function inquiry(array $shipment = []): Inquiry
    {
        $client = Client::factory()->create();
        $contact = ClientContact::factory()->create(['client_id' => $client->id, 'is_primary' => true]);

        return Inquiry::factory()->create(['client_id' => $client->id, 'client_contact_id' => $contact->id, 'response_due_at' => now()->addDays(2), 'shipment' => Shipment::normalize($shipment)]);
    }

    private function cargo(): array
    {
        return ['mode' => 'LCL', 'scope' => 'port_to_port', 'cargo_description' => 'General machine parts', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore port', 'cargo_ready_date' => '2026-10-10', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 2, 'gross_weight' => '250.5', 'weight_unit' => 'kg', 'length' => '100', 'width' => '80', 'height' => '90', 'dimension_unit' => 'cm']]];
    }

    private function payload(Inquiry $inquiry, array $overrides = []): array
    {
        return array_replace(['client_id' => (string) $inquiry->client_id, 'client_contact_id' => (string) $inquiry->client_contact_id, 'title' => $inquiry->title, 'owner_id' => $inquiry->owner_id, 'priority' => $inquiry->priority, 'response_due_at' => InquiryWorkflow::local($inquiry->response_due_at), 'shipment' => $inquiry->shipment, 'internal_notes' => $inquiry->internal_notes, 'lock_version' => $inquiry->lock_version], $overrides);
    }

    private function transition(Inquiry $inquiry, string $target, ?string $reason = null): void
    {
        $this->post('/inquiries/'.$inquiry->id.'/transition', ['lock_version' => $inquiry->fresh()->lock_version, 'target' => $target, 'reason' => $reason])->assertSessionHasNoErrors()->assertRedirect();
    }

    private function pdf(string $name = 'packing-list.pdf', string $content = 'Packing list'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n% ".$content."\n%%EOF");
    }

    public function test_active_admin_and_agent_share_records_and_guests_and_inactive_staff_are_denied(): void
    {
        $inquiry = $this->inquiry();
        foreach (['/clients', '/inquiries', '/inquiries/'.$inquiry->id] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach (['admin', 'agent'] as $role) {
            $this->actingAs($this->staff($role));
            foreach (['/clients', '/clients/create', '/clients/'.$inquiry->client_id, '/clients/'.$inquiry->client_id.'/edit', '/clients/'.$inquiry->client_id.'/status', '/clients/'.$inquiry->client_id.'/contacts/create', '/clients/'.$inquiry->client_id.'/contacts/'.$inquiry->client_contact_id.'/edit', '/inquiries', '/inquiries/create', '/inquiries/'.$inquiry->id.'/edit', '/overview'] as $path) {
                $this->get($path)->assertOk();
            }
            foreach (['overview', 'shipment', 'documents', 'activity'] as $section) {
                $this->get('/inquiries/'.$inquiry->id.'?section='.$section)->assertOk();
            }
            $this->patch('/inquiries/'.$inquiry->id, $this->payload($inquiry->fresh(), ['internal_notes' => 'Shared edit']))->assertSessionHasNoErrors();
        }
        $this->actingAs(User::factory()->create(['is_active' => false]))->get('/inquiries')->assertRedirect('/login');
        $this->actingAs($this->staff())->get('/staff')->assertForbidden();
    }

    public function test_clients_contacts_normalization_primary_transfer_archive_filters_and_scoping(): void
    {
        $this->actingAs($this->staff());
        $this->post('/clients', ['company_name' => 'Acme', 'contact_name' => 'Aina', 'contact_email' => ' AINA@EXAMPLE.TEST '])->assertSessionHasNoErrors();
        $client = Client::firstOrFail();
        $this->assertDatabaseHas('client_contacts', ['client_id' => $client->id, 'email' => 'aina@example.test', 'is_primary' => true]);
        $this->post('/clients/'.$client->id.'/contacts', ['name' => 'Desk', 'email' => 'desk@example.test', 'is_active' => '1', 'is_primary' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, $client->contacts()->where('is_primary', true)->count());
        $this->post('/clients/'.$client->id.'/contacts', ['name' => 'Duplicate', 'email' => ' DESK@EXAMPLE.TEST ', 'is_active' => '1'])->assertSessionHasErrors('email');
        $desk = $client->primaryContact()->firstOrFail();
        $this->patch('/clients/'.$client->id.'/contacts/'.$desk->id, ['name' => 'Desk', 'email' => $desk->email, 'is_active' => '0', 'is_primary' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse($desk->fresh()->is_primary);
        $other = ClientContact::factory()->create();
        $this->get('/clients/'.$client->id.'/contacts/'.$other->id.'/edit')->assertNotFound();
        $this->post('/clients', ['company_name' => 'ACME'])->assertSessionHasNoErrors();
        $this->get('/clients/'.Client::latest('id')->first()->id)->assertSee('Possible duplicate');
        $this->patch('/clients/'.$client->id.'/status', ['is_active' => '0'])->assertSessionHasNoErrors();
        $this->get('/inquiries/create')->assertDontSee('value="'.$client->id.'"', false);
        $this->get('/clients?status=archived')->assertSee('Acme');
        $this->patch('/clients/'.$client->id.'/status', ['is_active' => '1'])->assertSessionHasNoErrors();
    }

    public function test_postgresql_primary_and_client_contact_foreign_key_constraints_are_enforced(): void
    {
        $inquiry = $this->inquiry();
        foreach ([fn () => ClientContact::factory()->create(['client_id' => $inquiry->client_id, 'is_primary' => true]), fn () => $inquiry->update(['client_contact_id' => ClientContact::factory()->create()->id])] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Expected database constraint.');
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[0], ['23505', '23503']);
            }
        }
    }

    public function test_draft_intake_preserves_original_utc_values_and_sequence_references_do_not_reuse_rollback_numbers(): void
    {
        $this->actingAs($owner = $this->staff());
        $client = Client::factory()->create();
        $data = ['client_id' => $client->id, 'title' => 'Incomplete intake', 'owner_id' => $owner->id, 'priority' => 'normal', 'received_at' => '2026-10-04T10:00', 'source_channel' => 'email', 'original_source_text' => 'Original customer wording', 'shipment' => ['mode' => 'LCL', 'scope' => 'unknown', 'arrival_date' => '2026-10-20']];
        $this->post('/inquiries', $data)->assertSessionHasNoErrors();
        $inquiry = Inquiry::firstOrFail();
        $this->assertMatchesRegularExpression('/^LRS-\d{4}-\d{6,}$/', $inquiry->reference);
        $this->assertSame('2026-10-04 02:00:00', $inquiry->received_at->format('Y-m-d H:i:s'));
        $this->assertNull($inquiry->shipment['declared_volume']);
        $this->assertNotEmpty($inquiry->gaps());
        $number = null;
        try {
            DB::transaction(function () use (&$number): void {
                $number = InquiryWorkflow::reference();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $next = InquiryWorkflow::reference();
        $this->assertGreaterThan((int) substr($number, -6), (int) substr($next, -6));
        $this->patch('/inquiries/'.$inquiry->id, $this->payload($inquiry, ['original_source_text' => 'Changed']))->assertSessionHasErrors('original_source_text');
        $this->assertSame('Original customer wording', $inquiry->fresh()->original_source_text);
    }

    public function test_validation_preserves_input_and_rejects_negative_missing_units_wrong_contacts_and_currency_pairs(): void
    {
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry();
        $s = $this->cargo();
        $s['packages'][0]['gross_weight'] = '0';
        $s['packages'][0]['dimension_unit'] = null;
        $s['goods_value'] = '100';
        $this->from('/inquiries/'.$inquiry->id.'/edit')->patch('/inquiries/'.$inquiry->id, $this->payload($inquiry, ['shipment' => $s, 'client_contact_id' => ClientContact::factory()->create()->id]))->assertSessionHasErrors(['shipment.packages.0.gross_weight', 'shipment.packages.0.dimension_unit', 'shipment.goods_currency', 'client_contact_id'])->assertSessionHasInput('shipment.cargo_description', 'General machine parts');
        $this->assertSame('unknown', $inquiry->fresh()->shipment['mode']);
        $other = Client::factory()->create();
        $withoutContact = $this->payload($inquiry, ['client_id' => $other->id]);
        unset($withoutContact['client_contact_id']);
        $this->patch('/inquiries/'.$inquiry->id, $withoutContact)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inquiries', ['id' => $inquiry->id, 'client_id' => $other->id, 'client_contact_id' => null]);
    }

    public function test_lcl_and_fcl_readiness_scope_and_special_cargo_rules(): void
    {
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry($this->cargo());
        $this->assertSame([], $inquiry->gaps());
        $s = $this->cargo();
        unset($s['packages'][0]['height']);
        $inquiry->shipment = Shipment::normalize($s);
        $this->assertArrayHasKey('shipment.packages.0.height', $inquiry->gaps());
        $s['declared_volume'] = '1.6';
        $s['declared_volume_source'] = 'Customer packing list';
        $inquiry->shipment = Shipment::normalize($s);
        $this->assertSame([], $inquiry->gaps());
        $s['scope'] = 'door_to_door';
        $s['incoterm'] = 'FOB';
        $inquiry->shipment = Shipment::normalize($s);
        foreach (['pickup_address', 'delivery_address', 'named_place'] as $key) {
            $this->assertArrayHasKey('shipment.'.$key, $inquiry->gaps());
        }
        $s = $this->cargo();
        $s['mode'] = 'FCL';
        $s['containers'] = [['type' => '40HC', 'quantity' => 2, 'gross_weight' => '12000', 'weight_unit' => 'kg']];
        $inquiry->shipment = Shipment::normalize($s);
        $this->assertSame([], $inquiry->gaps());
        $this->assertNull(Shipment::totals($inquiry->shipment)['volume']);
        $s['containers'][0]['type'] = '40RF';
        $inquiry->shipment = Shipment::normalize($s);
        $this->assertArrayHasKey('shipment.special_flags', $inquiry->gaps());
        $this->transition($inquiry, 'needs_review');
        $inquiry->update(['shipment' => Shipment::normalize($s)]);
        $this->post('/inquiries/'.$inquiry->id.'/transition', ['lock_version' => $inquiry->fresh()->lock_version, 'target' => 'ready_for_sourcing'])->assertSessionHasErrors('shipment.special_flags');
    }

    public function test_exact_decimal_totals_unknowns_and_group_weight_semantics(): void
    {
        $s = Shipment::normalize($this->cargo());
        $this->assertSame(['volume' => '1.44', 'weight' => '250.5', 'basis' => ['2 × 100 × 80 × 90 cm = 1.44 m³']], Shipment::totals($s));
        $s['packages'][0]['height'] = null;
        $this->assertNull(Shipment::totals($s)['volume']);
        $s['packages'][0] = ['quantity' => 1, 'gross_weight' => '0.0001', 'weight_unit' => 'lb', 'length' => '0.0001', 'width' => '0.0001', 'height' => '0.0001', 'dimension_unit' => 'mm'];
        $this->assertSame('0.000000000000000000001', Shipment::totals($s)['volume']);
        $this->assertSame('0.000045359237', Shipment::totals($s)['weight']);
    }

    public function test_confirmation_is_immutable_metadata_edits_do_not_fork_and_material_edits_require_reconfirmation(): void
    {
        $this->actingAs($reviewer = $this->staff('admin'));
        $inquiry = $this->inquiry($this->cargo());
        $this->transition($inquiry, 'needs_review');
        $this->transition($inquiry, 'ready_for_sourcing');
        $inquiry = $inquiry->fresh();
        $first = $inquiry->versions()->firstOrFail();
        $this->assertTrue($inquiry->eligible());
        $this->assertSame($reviewer->id, $first->reviewer_id);
        $this->get('/inquiries/'.$inquiry->id.'/versions/'.$first->id)->assertOk()->assertSee('Immutable shipment evidence');
        foreach ([fn () => DB::table('shipment_versions')->where('id', $first->id)->update(['reviewer_name' => 'Tampered']), fn () => DB::table('shipment_versions')->where('id', $first->id)->delete()] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Expected immutable trigger.');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
        }
        $this->patch('/inquiries/'.$inquiry->id, $this->payload($inquiry, ['internal_notes' => 'No shipment change', 'owner_id' => $this->staff()->id, 'response_due_at' => InquiryWorkflow::local(now()->addDays(3))]))->assertSessionHasNoErrors();
        $inquiry = $inquiry->fresh();
        $this->assertSame(1, $inquiry->shipment_revision);
        $this->assertSame('ready_for_sourcing', $inquiry->status);
        $s = $inquiry->shipment;
        $s['destination_location'] = 'Jurong port';
        $this->patch('/inquiries/'.$inquiry->id, $this->payload($inquiry, ['shipment' => $s]))->assertSessionHasNoErrors();
        $inquiry = $inquiry->fresh();
        $this->assertSame(2, $inquiry->shipment_revision);
        $this->assertSame('draft', $inquiry->status);
        $this->assertFalse($inquiry->eligible());
        $this->assertSame('Singapore port', $first->fresh()->snapshot['shipment']['destination_location']);
        $this->transition($inquiry, 'needs_review');
        $this->transition($inquiry, 'ready_for_sourcing');
        $this->assertDatabaseCount('shipment_versions', 2);
        $this->assertTrue($inquiry->fresh()->eligible());
        $this->assertDatabaseHas('audit_entries', ['inquiry_id' => $inquiry->id, 'action' => 'Working shipment revised']);
    }

    public function test_stale_edits_and_invalid_transitions_do_not_mutate_records(): void
    {
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry();
        $stale = $this->payload($inquiry);
        $this->transition($inquiry, 'needs_review');
        $this->patch('/inquiries/'.$inquiry->id, $stale + ['internal_notes' => 'Lost update'])->assertSessionHasErrors('lock_version');
        $this->get('/inquiries/'.$inquiry->id.'/edit')->assertOk()->assertSee('name="lock_version" value="0"', false);
        $this->post('/inquiries/'.$inquiry->id.'/transition', ['lock_version' => 0, 'target' => 'on_hold', 'reason' => 'Pause'])->assertSessionHasErrors('lock_version');
        $this->post('/inquiries/'.$inquiry->id.'/transition', ['lock_version' => $inquiry->fresh()->lock_version, 'target' => 'reopen', 'reason' => 'Invalid'])->assertSessionHasErrors('target');
        $this->assertSame('needs_review', $inquiry->fresh()->status);
        $this->assertDatabaseCount('shipment_versions', 0);
    }

    public function test_hold_close_resume_reopen_require_reasons_and_return_to_review(): void
    {
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry($this->cargo());
        $this->post('/inquiries/'.$inquiry->id.'/transition', ['lock_version' => 0, 'target' => 'on_hold'])->assertSessionHasErrors('reason');
        $this->transition($inquiry, 'on_hold', 'Awaiting specialist advice');
        $this->transition($inquiry, 'resume');
        $this->assertSame('needs_review', $inquiry->fresh()->status);
        $this->transition($inquiry, 'closed', 'Client withdrew');
        $this->patch('/inquiries/'.$inquiry->id, $this->payload($inquiry->fresh()))->assertSessionHasErrors('status');
        $this->transition($inquiry, 'reopen', 'Client returned');
        $this->assertSame('needs_review', $inquiry->fresh()->status);
        $this->assertDatabaseHas('audit_entries', ['action' => 'Inquiry reopened for review']);
    }

    public function test_clarification_approval_and_copy_are_separate_from_explicit_manual_communication_and_answer(): void
    {
        Mail::fake();
        Notification::fake();
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry(['mode' => 'LCL']);
        $this->post('/inquiries/'.$inquiry->id.'/clarifications', ['lock_version' => 0])->assertSessionHasNoErrors();
        $item = $inquiry->clarifications()->firstOrFail();
        $path = '/inquiries/'.$inquiry->id.'/clarifications/'.$item->id;
        $this->get($path)->assertOk()->assertSee('Exact message');
        $this->patch($path, ['lock_version' => 0, 'client_contact_id' => $item->client_contact_id, 'body' => 'Please confirm dimensions and gross weight.'])->assertSessionHasNoErrors();
        $item = $item->fresh();
        $this->post($path.'/approve', ['lock_version' => $item->lock_version])->assertSessionHasNoErrors();
        $item = $item->fresh();
        $this->assertDatabaseCount('inquiry_communications', 0);
        $this->get($path)->assertOk()->assertSee('Copy message')->assertSee('Record manual communication');
        $this->patch($path, ['lock_version' => $item->lock_version, 'client_contact_id' => $item->client_contact_id, 'body' => 'Changed after approval'])->assertSessionHasErrors('body');
        $manual = ['lock_version' => $item->lock_version, 'recipient' => $item->recipient_email, 'channel' => 'email', 'occurred_at' => InquiryWorkflow::local(now()->subMinute())];
        $this->post($path.'/communicated', $manual)->assertSessionHasErrors('confirmed_manual');
        $this->post($path.'/communicated', $manual + ['confirmed_manual' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inquiry_communications', ['kind' => 'clarification', 'notes' => 'Please confirm dimensions and gross weight.', 'recipient' => $item->recipient_email]);
        $this->assertSame('needs_client_information', $inquiry->fresh()->status);
        $this->post($path.'/communicated', $manual + ['confirmed_manual' => '1'])->assertSessionHasErrors('lock_version');
        $this->post('/inquiries/'.$inquiry->id.'/communications', ['kind' => 'client_response', 'channel' => 'phone', 'occurred_at' => InquiryWorkflow::local(now()->subMinute()), 'notes' => 'Client supplied the packing dimensions.'])->assertSessionHasNoErrors();
        $this->assertSame('needs_review', $inquiry->fresh()->status);
        $this->assertDatabaseCount('inquiry_communications', 2);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_material_change_and_contact_change_invalidate_clarification_approval(): void
    {
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry();
        $this->post('/inquiries/'.$inquiry->id.'/clarifications', ['lock_version' => 0]);
        $item = $inquiry->clarifications()->firstOrFail();
        $path = '/inquiries/'.$inquiry->id.'/clarifications/'.$item->id;
        $this->post($path.'/approve', ['lock_version' => 0])->assertSessionHasNoErrors();
        $inquiry->contact->update(['email' => 'replacement@example.test']);
        $this->assertFalse($item->fresh()->currentFor($inquiry->fresh()));
        $this->post($path.'/communicated', ['lock_version' => 1, 'recipient' => $item->recipient_email, 'channel' => 'phone', 'occurred_at' => InquiryWorkflow::local(now()->subMinute()), 'confirmed_manual' => 1])->assertSessionHasErrors('recipient');
        $s = $inquiry->shipment;
        $s['cargo_description'] = 'New cargo';
        $this->patch('/inquiries/'.$inquiry->id, $this->payload($inquiry->fresh(), ['shipment' => $s]))->assertSessionHasNoErrors();
        $this->assertSame('invalidated', $item->fresh()->status);
        $this->assertSame('draft', $inquiry->fresh()->status);
        $this->assertDatabaseCount('inquiry_communications', 0);
    }

    public function test_private_uploads_actual_type_dedup_archive_scope_and_confirmation_preservation(): void
    {
        Storage::fake('inquiry_documents');
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry($this->cargo());
        $this->transition($inquiry, 'needs_review');
        $this->transition($inquiry, 'ready_for_sourcing');
        $before = $inquiry->fresh();
        $path = '/inquiries/'.$inquiry->id.'/documents';
        $this->post($path, ['files' => [$this->pdf('../packing-list.pdf')], 'classification' => 'packing_list'])->assertSessionHasNoErrors();
        $doc = $inquiry->documents()->firstOrFail();
        $this->assertSame('packing-list.pdf', $doc->original_name);
        $this->assertSame('application/pdf', $doc->mime);
        $this->assertSame(64, strlen($doc->checksum));
        $this->assertArrayNotHasKey('storage_path', $doc->toArray());
        $this->get($path.'/'.$doc->id)->assertOk()->assertSee('extraction not performed');
        $this->get($path.'/'.$doc->id.'/file')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($path.'/'.$doc->id.'/file?download=1')->assertDownload('packing-list.pdf');
        $this->post($path, ['files' => [$this->pdf()], 'classification' => 'packing_list'])->assertSessionHas('warning');
        $this->assertDatabaseCount('inquiry_documents', 1);
        $other = $this->inquiry();
        $this->get('/inquiries/'.$other->id.'/documents/'.$doc->id.'/file')->assertNotFound();
        $this->post('/inquiries/'.$other->id.'/documents', ['files' => [$this->pdf()], 'classification' => 'packing_list'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inquiry_documents', 2);
        $this->patch($path.'/'.$doc->id, ['classification' => 'other', 'is_archived' => '1'])->assertSessionHasNoErrors();
        Storage::disk('inquiry_documents')->assertExists($doc->storage_path);
        $this->assertSame($before->lock_version, $inquiry->fresh()->lock_version);
        $this->assertSame($before->shipment_revision, $inquiry->fresh()->shipment_revision);
        $this->assertTrue($inquiry->fresh()->eligible());
        $this->actingAs(User::factory()->create(['is_active' => false]))->get($path.'/'.$doc->id.'/file')->assertRedirect('/login');
        auth()->logout();
        $this->get($path.'/'.$doc->id.'/file')->assertRedirect('/login');
    }

    public function test_upload_rejects_spoofed_contents_and_configured_limits_and_missing_original_is_not_found(): void
    {
        Storage::fake('inquiry_documents');
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry();
        $path = '/inquiries/'.$inquiry->id.'/documents';
        foreach (['fake.pdf', 'fake.docx', 'fake.png'] as $name) {
            $this->post($path, ['files' => [UploadedFile::fake()->createWithContent($name, '<script>attack</script>')], 'classification' => 'other'])->assertSessionHasErrors('files.0');
        }
        config(['inquiries.upload_batch_limit' => 1]);
        $this->post($path, ['files' => [$this->pdf(), $this->pdf('other.pdf', 'Different')], 'classification' => 'other'])->assertSessionHasErrors('files');
        config(['inquiries.upload_batch_limit' => 5, 'inquiries.upload_max_kb' => 1]);
        $this->post($path, ['files' => [UploadedFile::fake()->createWithContent('large.pdf', "%PDF-1.4\n".str_repeat('x', 2048))], 'classification' => 'other'])->assertSessionHasErrors('files.0');
        config(['inquiries.upload_max_kb' => 10240, 'inquiries.document_limit' => 1]);
        $this->post($path, ['files' => [$this->pdf()], 'classification' => 'other'])->assertSessionHasNoErrors();
        $this->post($path, ['files' => [$this->pdf('extra.pdf', 'Extra')], 'classification' => 'other'])->assertSessionHasErrors('files');
        $doc = $inquiry->documents()->firstOrFail();
        Storage::disk('inquiry_documents')->delete($doc->storage_path);
        $this->get($path.'/'.$doc->id.'/file')->assertNotFound();
    }

    public function test_supported_csv_images_and_office_archives_are_validated_by_actual_contents(): void
    {
        Storage::fake('inquiry_documents');
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry();
        $files = [UploadedFile::fake()->createWithContent('list.csv', "item,qty\nparts,2\n")];
        $imagePath = Storage::disk('inquiry_documents')->path('fixture.png');
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aLuYAAAAASUVORK5CYII='));
        $files[] = new UploadedFile($imagePath, 'image.png', null, null, true);
        foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml'] as $ext => $entry) {
            $archive = Storage::disk('inquiry_documents')->path('fixture-'.$ext.'.zip');
            $zip = new \PharData($archive, 0, null, \Phar::ZIP);
            $zip['[Content_Types].xml'] = '<?xml version="1.0"?><Types />';
            $zip[$entry] = '<?xml version="1.0"?><document />';
            unset($zip);
            $files[] = new UploadedFile($archive, 'original.'.$ext, null, null, true);
        }
        $this->post('/inquiries/'.$inquiry->id.'/documents', ['files' => $files, 'classification' => 'other'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inquiry_documents', 4);
        $archive = Storage::disk('inquiry_documents')->path('disguised.tar');
        $tar = new \PharData($archive, 0, null, \Phar::TAR);
        $tar['[Content_Types].xml'] = '<Types />';
        $tar['word/document.xml'] = '<document />';
        unset($tar);
        $this->post('/inquiries/'.$inquiry->id.'/documents', ['files' => [new UploadedFile($archive, 'disguised.docx', null, null, true)], 'classification' => 'other'])->assertSessionHasErrors('files.0');
        $this->assertDatabaseCount('inquiry_documents', 4);
    }

    public function test_document_audit_failure_rolls_back_metadata_and_stored_files(): void
    {
        Storage::fake('inquiry_documents');
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry();
        AuditEntry::creating(fn () => throw new \RuntimeException('Audit unavailable'));
        try {
            app(StoreInquiryDocuments::class)->handle($inquiry, [$this->pdf()], 'packing_list');
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        } finally {
            AuditEntry::flushEventListeners();
        }
        $this->assertDatabaseCount('inquiry_documents', 0);
        $this->assertSame([], Storage::disk('inquiry_documents')->allFiles());
    }

    public function test_communication_document_references_are_scoped_and_audit_does_not_leak_source_or_paths(): void
    {
        Storage::fake('inquiry_documents');
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry();
        $other = $this->inquiry();
        $this->post('/inquiries/'.$other->id.'/documents', ['files' => [$this->pdf()], 'classification' => 'packing_list']);
        $doc = $other->documents()->firstOrFail();
        $data = ['kind' => 'client_response', 'channel' => 'phone', 'occurred_at' => InquiryWorkflow::local(now()->subMinute()), 'notes' => 'Private response', 'document_ids' => [$doc->id]];
        $this->post('/inquiries/'.$inquiry->id.'/communications', $data)->assertSessionHasErrors('document_ids.0');
        $this->post('/inquiries/'.$other->id.'/communications', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('communication_document', ['inquiry_document_id' => $doc->id]);
        $audit = json_encode(AuditEntry::all());
        $this->assertStringNotContainsString('Private response', $audit);
        $this->assertStringNotContainsString($doc->storage_path, $audit);
        $this->get('/clients/'.$inquiry->client_id)->assertSee($inquiry->reference);
    }

    public function test_server_filters_pagination_overview_and_client_search_use_real_records(): void
    {
        $this->actingAs($this->staff());
        $matching = $this->inquiry($this->cargo());
        $matching->update(['title' => 'Unique machinery', 'priority' => 'urgent', 'status' => 'needs_review', 'response_due_at' => now()->subDay()]);
        Inquiry::factory()->count(13)->create(['title' => 'Other intake', 'response_due_at' => now()->addDays(2)]);
        $this->get('/inquiries?q=unique&status=needs_review&priority=urgent&overdue=1&owner='.$matching->owner_id.'&client='.$matching->client_id)->assertOk()->assertSee('Unique machinery')->assertDontSee('Other intake');
        $this->get('/inquiries?page=2')->assertOk()->assertSee('Page 2 of 2');
        $this->get('/inquiries?q=%25')->assertSee('No inquiries match');
        $this->get('/overview')->assertViewHas('review', 1)->assertViewHas('overdue', 1);
        $this->get('/clients?q='.urlencode($matching->contact->email).'&contact=ready')->assertOk()->assertSee($matching->client->company_name);
        $this->get('/inquiries?status=invalid')->assertSessionHasErrors('status');
    }

    public function test_external_contact_archive_revokes_eligibility_and_warnings_preserve_facts(): void
    {
        $this->actingAs($this->staff());
        $inquiry = $this->inquiry($this->cargo());
        $this->transition($inquiry, 'needs_review');
        $this->transition($inquiry, 'ready_for_sourcing');
        $inquiry->contact->update(['is_active' => false, 'is_primary' => false]);
        $this->assertFalse($inquiry->fresh()->eligible());
        $this->assertArrayHasKey('client_contact_id', $inquiry->fresh()->gaps());
        $s = $this->cargo();
        $s['declared_volume'] = '2';
        $s['declared_volume_source'] = 'Client estimate';
        $s['destination_country'] = $s['origin_country'];
        $s['destination_location'] = $s['origin_location'];
        $warnings = Shipment::warnings(Shipment::normalize($s));
        $this->assertArrayHasKey('shipment.declared_volume', $warnings);
        $this->assertArrayHasKey('shipment.destination_location', $warnings);
        $this->get('/inquiries/'.$inquiry->id.'/edit')->assertOk()->assertSee('Select an active contact');
        $s['arrival_date'] = '2026-10-09';
        $this->patch('/inquiries/'.$inquiry->id, $this->payload($inquiry->fresh(), ['shipment' => $s]))->assertSessionHasErrors('shipment.arrival_date');
    }

    public function test_opt_in_demo_command_is_idempotent_and_never_confirms_or_sends(): void
    {
        $this->staff();
        Mail::fake();
        $this->artisan('lrs:demo-inquiries')->assertSuccessful();
        $this->artisan('lrs:demo-inquiries')->assertSuccessful();
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('inquiries', 2);
        $this->assertDatabaseCount('shipment_versions', 0);
        $this->assertDatabaseCount('inquiry_communications', 0);
        $this->assertSame(['draft'], Inquiry::distinct()->pluck('status')->all());
        Mail::assertNothingSent();
    }
}
