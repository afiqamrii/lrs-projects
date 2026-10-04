<?php

namespace Tests\Feature;

use App\Actions\StoreInquiryDocuments;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\PublicSubmission;
use App\Models\User;
use App\Support\AiSources;
use App\Support\InquiryWorkflow;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Boost\Middleware\InjectBoost;
use Tests\TestCase;

class PublicIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $changes = []): array
    {
        $token = $this->get('/request-quote')->assertOk()->viewData('intakeToken');

        return array_replace_recursive(['intake_token' => $token, 'contact' => ['name' => 'Public QA', 'email' => 'public@example.test', 'company' => 'Public QA company'], 'shipment' => ['mode' => 'LCL', 'scope' => 'unknown', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore port', 'cargo_description' => 'General machine parts'], 'classification' => 'packing_list', 'privacy_acknowledged' => '1', 'privacy_version' => CompanySetting::current()->public_privacy_version], $changes);
    }

    private function submit(array $changes = []): Inquiry
    {
        $this->post('/request-quote', $this->payload($changes))->assertSessionHasNoErrors()->assertRedirect('/request-quote/received');

        return Inquiry::latest('id')->firstOrFail();
    }

    private function pdf(string $name = 'packing.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF");
    }

    public function test_public_pages_exclude_development_browser_logging_that_captures_full_urls(): void
    {
        $this->app[Kernel::class]->appendMiddlewareToGroup('web', InjectBoost::class);
        $this->get('/login')->assertOk()->assertSee('browser-logger-active', false);
        $this->get('/request-quote')->assertOk()->assertDontSee('browser-logger-active', false);
        $this->get('/request-quote/confirm')->assertOk()->assertDontSee('browser-logger-active', false);
        $this->submit();
        $this->get('/request-quote/received')->assertOk()->assertDontSee('browser-logger-active', false);
    }

    public function test_public_unknown_measurements_create_one_unresolved_review_case_and_session_bound_receipt(): void
    {
        Storage::fake('inquiry_documents');
        $data = $this->payload(['files' => [$this->pdf()], 'additional_notes' => 'Volume is not final.']);
        $this->post('/request-quote', $data)->assertSessionHasNoErrors();
        $record = Inquiry::firstOrFail();
        $this->assertSame('website', $record->source_channel);
        $this->assertSame('needs_review', $record->status);
        $this->assertNull($record->client_id);
        $this->assertNull($record->client_contact_id);
        $this->assertNull($record->owner_id);
        $this->assertNull($record->response_due_at);
        $this->assertNull($record->shipment['declared_volume']);
        $this->assertSame(0, Client::count());
        $this->assertSame(0, ClientContact::count());
        $this->assertSame(0, $record->versions()->count());
        $this->assertArrayHasKey('client_id', $record->gaps());
        $original = $record->publicSubmission;
        $this->assertTrue($original->snapshot['privacy']['acknowledged']);
        $this->assertSame('Volume is not final.', $original->snapshot['additional_notes']);
        $website = collect(AiSources::catalogue($record))->firstWhere('lineage.kind', 'website');
        $input = AiSources::input([$website]);
        $this->assertStringContainsString('Volume is not final.', $input);
        $this->assertStringNotContainsString('public@example.test', $input);
        $this->assertStringNotContainsString('"contact"', $input);
        $this->assertStringNotContainsString('"privacy"', $input);
        $this->assertTrue(AiSources::current($record, [$website]));
        $this->assertCount(1, $original->snapshot['documents']);
        $doc = $record->documents()->firstOrFail();
        $this->assertNull($doc->uploader_id);
        $this->assertSame('website_submission', $doc->provenance);
        $this->assertSame('unscanned', $doc->scan_status);
        $this->assertSame('System / public submission', AuditEntry::where('action', 'Website inquiry received')->firstOrFail()->actor_name);
        $this->get('/request-quote/received')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertOk()->assertSee($record->reference)->assertDontSee('Volume is not final.');
        $this->get('/inquiries/'.$record->id)->assertRedirect('/login');
        $this->get('/inquiries/'.$record->id.'/documents/'.$doc->id.'/file')->assertRedirect('/login');
        $this->withSession(['public_receipt_inquiry' => null])->get('/request-quote/received?reference='.$record->reference)->assertNotFound();
    }

    public function test_original_submitted_email_text_is_preserved_and_working_matching_is_normalized(): void
    {
        $record = $this->submit(['contact' => ['email' => 'PUBLIC@EXAMPLE.TEST']]);
        $this->assertSame('PUBLIC@EXAMPLE.TEST', $record->publicSubmission->snapshot['contact']['email']);
        $this->assertSame('public@example.test', $record->public_contact['email']);
        $this->assertSame('public@example.test', $record->mailboxVerifications()->firstOrFail()->email);
        $this->get('/request-quote/received')->assertOk()->assertViewHas('canResend', true);
        $this->post('/request-quote/received/resend')->assertRedirect('/request-quote/received');
        $this->assertSame(2, $record->mailboxVerifications()->count());
    }

    public function test_retries_return_same_case_documents_and_receipt_record_and_distinct_requests_remain_distinct(): void
    {
        Storage::fake('inquiry_documents');
        $data = $this->payload(['files' => [$this->pdf()]]);
        $this->post('/request-quote', $data)->assertSessionHasNoErrors();
        $first = Inquiry::firstOrFail();
        $this->post('/request-quote', ['intake_token' => $data['intake_token']])->assertRedirect('/request-quote/received');
        $this->assertSame(1, Inquiry::count());
        $this->assertSame(1, PublicSubmission::count());
        $this->assertSame(1, $first->documents()->count());
        $this->assertSame(1, $first->mailboxVerifications()->count());
        $this->assertCount(1, Storage::disk('inquiry_documents')->allFiles());
        $this->submit();
        $this->assertSame(2, Inquiry::count());
    }

    public function test_contact_route_cargo_privacy_numeric_array_and_trusted_field_validation_preserves_values(): void
    {
        $data = $this->payload(['contact' => ['email' => 'bad address'], 'shipment' => ['origin_country' => '', 'cargo_description' => '', 'packages' => [['gross_weight' => '-1', 'quantity' => 0]]], 'owner_id' => 1, 'status' => 'ready_for_sourcing', 'privacy_acknowledged' => '0']);
        $this->post('/request-quote', $data)->assertSessionHasErrors(['contact.email', 'shipment.origin_country', 'shipment.cargo_description', 'shipment.packages.0.gross_weight', 'shipment.packages.0.quantity', 'owner_id', 'status', 'privacy_acknowledged'])->assertSessionHasInput('contact.name', 'Public QA');
        $this->assertSame(0, Inquiry::count());
        $data = $this->payload(['shipment' => ['mode' => 'FCL', 'scope' => 'door_to_door', 'containers' => [['type' => '40HC', 'quantity' => 1, 'gross_weight' => '200', 'weight_unit' => 'kg']], 'special_flags' => ['temperature']]]);
        $this->post('/request-quote', $data)->assertSessionHasNoErrors();
        $record = Inquiry::firstOrFail();
        $this->assertArrayHasKey('shipment.pickup_address', $record->gaps());
        $this->assertArrayHasKey('shipment.delivery_address', $record->gaps());
        $this->assertArrayHasKey('shipment.special_flags', $record->gaps());
        $this->assertSame('needs_review', $record->status);
    }

    public function test_mode_changes_unknown_and_server_row_add_preserve_alternative_entered_rows(): void
    {
        $data = $this->payload(['shipment' => ['mode' => 'unknown', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 2]], 'containers' => [['type' => '40HC', 'quantity' => 1]]]]);
        $this->post('/request-quote/draft', $data + ['add_row' => 'packages'])->assertSessionHasNoErrors()->assertSessionHasInput('shipment.packages.0.packaging_type', 'pallets');
        $this->assertSame(0, Inquiry::count());
        $this->post('/request-quote', $data)->assertSessionHasNoErrors();
        $shipment = Inquiry::firstOrFail()->publicSubmission->snapshot['shipment'];
        $this->assertSame('pallets', $shipment['packages'][0]['packaging_type']);
        $this->assertSame('40HC', $shipment['containers'][0]['type']);
        $this->assertSame('unknown', $shipment['mode']);
    }

    public function test_same_existing_email_never_attaches_exposes_or_mutates_a_directory_contact(): void
    {
        $client = Client::factory()->create(['company_name' => 'Private existing client']);
        $contact = ClientContact::factory()->create(['client_id' => $client->id, 'email' => 'public@example.test']);
        $original = $contact->fresh()->getAttributes();
        $record = $this->submit();
        $this->assertNull($record->client_id);
        $this->assertSame($original, $contact->fresh()->getAttributes());
        $this->get('/request-quote/received')->assertDontSee('Private existing client')->assertDontSee($contact->name);
        $this->actingAs(User::factory()->create(['role' => 'agent']))->get('/inquiries/'.$record->id.'/public-contact')->assertOk()->assertSee('Private existing client')->assertSee('Possible contact matches');
    }

    public function test_staff_resolution_is_explicit_stale_safe_and_can_continue_the_phase_two_workflow(): void
    {
        $record = $this->submit();
        $original = $record->publicSubmission->snapshot;
        $owner = User::factory()->create(['role' => 'agent']);
        $this->actingAs($owner);
        $data = ['lock_version' => 0, 'resolution' => 'new_client', 'contact' => $record->public_contact, 'identity_assessed' => '0'];
        $this->patch('/inquiries/'.$record->id.'/public-contact', $data)->assertSessionHasErrors('identity_assessed');
        $data['identity_assessed'] = '1';
        $this->patch('/inquiries/'.$record->id.'/public-contact', $data)->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertNotNull($record->client_id);
        $this->assertNotNull($record->client_contact_id);
        $this->assertSame($original, $record->publicSubmission->snapshot);
        $this->patch('/inquiries/'.$record->id.'/public-contact', $data)->assertSessionHasErrors('lock_version');
        $this->assertSame(1, Client::count());
        $this->patch('/inquiries/'.$record->id, ['lock_version' => $record->lock_version, 'client_id' => $record->client_id, 'client_contact_id' => $record->client_contact_id, 'title' => $record->title, 'owner_id' => $owner->id, 'priority' => 'normal', 'response_due_at' => InquiryWorkflow::local(now()->addDay()), 'shipment' => ['mode' => 'LCL', 'scope' => 'port_to_port', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore port', 'cargo_description' => 'General machine parts', 'cargo_ready_date' => '2026-10-10', 'declared_volume' => '2', 'declared_volume_source' => 'Customer packing list', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 2, 'gross_weight' => '100', 'weight_unit' => 'kg']]]])->assertSessionHasNoErrors();
        foreach (['needs_review', 'ready_for_sourcing'] as $target) {
            $this->post('/inquiries/'.$record->id.'/transition', ['target' => $target, 'lock_version' => $record->fresh()->lock_version])->assertSessionHasNoErrors();
        }
        $this->assertTrue($record->fresh()->eligible());
        $this->assertFalse($record->mailboxConfirmed());
        $this->assertSame($original, $record->publicSubmission->snapshot);
    }

    public function test_website_drafts_allow_staff_corrections_without_creating_clients_or_weakening_manual_requirements(): void
    {
        $record = $this->submit();
        $staff = User::factory()->create(['role' => 'agent']);
        $this->actingAs($staff);
        $this->patch('/inquiries/'.$record->id, ['lock_version' => 0, 'client_id' => null, 'client_contact_id' => null, 'owner_id' => null, 'title' => $record->title, 'priority' => 'normal', 'shipment' => array_replace($record->shipment, ['cargo_description' => 'Assessed general cargo'])])->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertNull($record->client_id);
        $this->assertNull($record->owner_id);
        $this->patch('/inquiries/'.$record->id.'/public-contact', ['lock_version' => $record->lock_version, 'resolution' => 'defer', 'contact' => array_replace($record->public_contact, ['email' => 'corrected@example.test'])])->assertSessionHasNoErrors();
        $this->assertSame(0, Client::count());
        $this->assertSame('public@example.test', $record->publicSubmission->snapshot['contact']['email']);
        $this->assertSame('corrected@example.test', $record->fresh()->public_contact['email']);
        $this->post('/inquiries', ['title' => 'Manual without client', 'owner_id' => $staff->id, 'priority' => 'normal', 'received_at' => InquiryWorkflow::local(now()), 'source_channel' => 'email', 'shipment' => ['mode' => 'unknown', 'scope' => 'unknown']])->assertSessionHasErrors('client_id');
    }

    public function test_original_website_snapshot_is_immutable_at_the_database_boundary(): void
    {
        $record = $this->submit();
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($record, $operation): void {
                    $original = $record->publicSubmission;
                    if ($operation === 'delete') {
                        $original->delete();
                    } else {
                        $original->update(['snapshot' => ['changed' => true]]);
                    }
                });
                $this->fail('Expected immutable evidence constraint.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->assertSame(1, PublicSubmission::count());
    }

    public function test_private_actual_type_count_size_aggregate_and_cross_inquiry_access_are_enforced(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('inquiry_documents');
        foreach (['payload.svg' => '<svg></svg>', 'script.html' => '<html></html>', 'renamed.pdf' => 'not a PDF', 'archive.docx' => 'arbitrary archive'] as $name => $contents) {
            $this->post('/request-quote', $this->payload(['files' => [UploadedFile::fake()->createWithContent($name, $contents)]]))->assertSessionHasErrors('files.0');
        }
        $this->post('/request-quote', $this->payload(['files' => array_fill(0, 6, $this->pdf())]))->assertSessionHasErrors('files');
        $this->post('/request-quote', $this->payload(['files' => [UploadedFile::fake()->createWithContent('large.pdf', "%PDF-1.4\n".str_repeat('x', 10240 * 1024 + 1))]]))->assertSessionHasErrors('files.0');
        $files = [];
        for ($i = 0; $i < 4; $i++) {
            $files[] = UploadedFile::fake()->createWithContent("part-$i.pdf", "%PDF-1.4\n".str_repeat('x', 8 * 1024 * 1024));
        }
        $this->post('/request-quote', $this->payload(['files' => $files]))->assertSessionHasErrors('files');
        $this->assertSame(0, Inquiry::count());
        $record = $this->submit(['files' => [$this->pdf()]]);
        $doc = $record->documents()->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'agent']))->get('/inquiries/'.$record->id.'/documents/'.$doc->id.'/file?download=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $other = Inquiry::factory()->create();
        $this->get('/inquiries/'.$other->id.'/documents/'.$doc->id.'/file?download=1')->assertNotFound();
    }

    public function test_honeypot_session_key_expiry_disabled_intake_and_settings_permissions(): void
    {
        $data = $this->payload();
        $this->post('/request-quote', $data + ['website' => 'spam'])->assertSessionHasErrors('website');
        DB::table('public_intake_keys')->where('token_hash', hash('sha256', $data['intake_token']))->update(['expires_at' => now()->subHour()]);
        $this->post('/request-quote', $data)->assertSessionHasErrors('intake_token');
        $data = $this->payload();
        $this->withSession(['public_intake_nonce' => 'another-browser'])->post('/request-quote', $data)->assertSessionHasErrors('intake_token');
        CompanySetting::current()->update(['public_intake_enabled' => false]);
        $this->get('/request-quote')->assertOk()->assertSee('Online inquiries are paused')->assertDontSee('data-public-intake', false);
        $this->assertSame(0, Inquiry::count());
        $this->actingAs(User::factory()->create(['role' => 'agent']))->patch('/settings', ['public_intake_enabled' => true])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/settings')->assertOk();
    }

    public function test_default_owner_is_active_only_and_staff_screens_handle_unresolved_records(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        CompanySetting::current()->update(['public_intake_owner_id' => $owner->id]);
        $record = $this->submit();
        $this->assertSame($owner->id, $record->owner_id);
        $owner->update(['is_active' => false]);
        $next = $this->submit();
        $this->assertNull($next->owner_id);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        foreach (['/overview', '/inquiries?source=website&unassigned=1', '/inquiries/'.$next->id, '/inquiries/'.$next->id.'/edit', '/inquiries/'.$next->id.'/public-contact'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/inquiries?source=website&data=fixtures')->assertSee('Website form')->assertSee('Client assessment needed');
    }

    public function test_failure_rolls_back_database_and_compensates_only_new_private_files(): void
    {
        Storage::fake('inquiry_documents');
        $existing = Inquiry::factory()->create();
        app(StoreInquiryDocuments::class)->handle($existing, [$this->pdf('history.pdf')], 'other', true);
        $originalFiles = Storage::disk('inquiry_documents')->allFiles();
        PublicSubmission::creating(function (): void {
            throw new \RuntimeException('Test failure after file write');
        });
        try {
            $this->post('/request-quote', $this->payload(['files' => [$this->pdf()]]))->assertSessionHasErrors('submission');
            $this->assertSame(1, Inquiry::count());
            $this->assertSame(0, PublicSubmission::count());
            $this->assertSame($originalFiles, Storage::disk('inquiry_documents')->allFiles());
        } finally {
            PublicSubmission::flushEventListeners();
        }
    }

    public function test_real_csrf_middleware_and_persistent_rate_limits_have_useful_states(): void
    {
        $data = $this->payload();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->post('/request-quote', $data)->assertStatus(419)->assertSee('Your form session expired.');
        $this->assertSame(0, Inquiry::count());
        $this->withSession(['_token' => 'test-token']);
        for ($i = 0; $i < 4; $i++) {
            $this->post('/request-quote', ['_token' => 'test-token'])->assertRedirect();
        }
        $this->post('/request-quote', ['_token' => 'test-token'])->assertStatus(429)->assertSee('Please wait a moment.');
        $this->assertSame('database', config('cache.limiter'));
    }
}
