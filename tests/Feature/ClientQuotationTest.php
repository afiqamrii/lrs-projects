<?php

namespace Tests\Feature;

use App\Actions\MailOutbox;
use App\Actions\ManageOffer;
use App\Actions\ManageQuotation;
use App\Jobs\DispatchMail;
use App\Models\ClientContact;
use App\Models\ClientQuotationApproval;
use App\Models\ClientQuotationRevision;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\User;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\MailRelease;
use App\Support\Processing;
use App\Support\QuotationEligibility;
use App\Support\QuotationPdf;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class ClientQuotationTest extends TestCase
{
    use QuotationFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quotationFixture();
    }

    private function save(?array $p = null): ClientQuotationRevision
    {
        return app(ManageQuotation::class)->save($this->case, $this->staff, $p ?? $this->quotationInput());
    }

    private function approve(ClientQuotationRevision $r): ClientQuotationApproval
    {
        $snapshot = app(ManageQuotation::class)->reviewSnapshot($r);

        return app(ManageQuotation::class)->approve($r, $this->staff, Processing::hash($snapshot));
    }

    private function blocked(callable $action, string $fragment): void
    {
        try {
            $action();
            $this->fail('Expected a blocked commercial action');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($fragment, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    private function dispatch(ClientQuotationRevision $r): MailDispatch
    {
        $a = $this->approve($r);
        $e = $a->envelopes()->firstOrFail();

        return app(MailOutbox::class)->enqueue($e, $this->staff, (string) Str::uuid(), $e->digest);
    }

    public function test_complete_workbench_approves_exact_bytes_and_fixture_outbox_is_idempotent(): void
    {
        $this->get(route('quotations.index', $this->case))->assertOk()->assertSee('Prepare customer quote');
        $r = $this->save();
        $this->assertSame([], QuotationEligibility::reasons($r));
        $this->assertSame('1560.00', $r->pricing['total']);
        $this->assertSame('260.00', $r->pricing['estimated_profit']);
        $before = QuotationPdf::bytes($r);
        $a = $this->approve($r);
        $this->assertSame($r->pdf_checksum, $a->snapshot['content']['manifest'][0]['checksum']);
        $this->assertSame('Approved', $r->fresh()->label());
        $this->assertSame(0, MailDispatch::count());
        $this->get(route('quotations.review', [$this->case, $r]))->assertOk()->assertSee('Send approved quotation');
        $this->get(route('mail.preview', ['client_quote', $a->id]))->assertOk()->assertSee('Enqueue fixture message');
        $e = $a->envelopes()->first();
        $key = (string) Str::uuid();
        $d = app(MailOutbox::class)->enqueue($e, $this->staff, $key, $e->digest);
        $this->assertSame($d->id, app(MailOutbox::class)->enqueue($e, $this->staff, $key, $e->digest)->id);
        $this->get(route('mail.dispatch', $d))->assertOk()->assertSee(route('mail.preview', ['client_quote', $a->id]));
        for ($i = 0; $i < 4; $i++) {
            $this->travel(2)->seconds();
            (new DispatchMail($d->id))->handle();
        }
        $this->assertSame('accepted', $d->fresh()->status);
        (new DispatchMail($d->id))->handle();
        $this->assertSame(1, MailDispatch::count());
        $this->assertSame($before, QuotationPdf::bytes($r));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_pdf_customer_projection_excludes_internal_vendor_evidence_and_long_document_paginates(): void
    {
        $this->case->client->update(['company_name' => 'Peninsula Precision Manufacturing and International Industrial Components Distribution Company - Southeast Asia Operations']);
        $p = $this->quotationInput();
        $key = array_key_first($p['descriptions']);
        $p['descriptions'][$key] = 'Ocean freight service with extended handling description and currency glyph verification: € £ ¥. '.$p['descriptions'][$key];
        $p['conditions'] = implode("\n", array_fill(0, 45, 'Payment and service conditions remain subject to written operational confirmation. This deliberately long customer condition verifies wrapping and page flow.'));
        $p['optional_lines'] = [['description' => 'Optional cargo insurance subject to revised coverage terms', 'amount' => '90']];
        $r = $this->save($p);
        $customer = $r->payload['customer'];
        foreach (['markup', 'cost_lines', 'estimated_profit', 'vendor', 'PRIVATE PROFIT', 'source_ref'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($customer));
        }
        $directory = storage_path('framework/testing/quotation-pdfs');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $pdf = $directory.'/long.pdf';
        $text = $directory.'/long.txt';
        file_put_contents($pdf, QuotationPdf::bytes($r));
        $process = new Process([config('extraction.pdftotext'), '-layout', $pdf, $text]);
        $process->mustRun();
        $extracted = file_get_contents($text);
        $this->assertGreaterThan(1, substr_count($extracted, "\f"));
        $this->assertStringContainsString('1560.00', $extracted);
        $this->assertStringContainsString('Optional services', $extracted);
        $this->assertStringNotContainsString('PRIVATE PROFIT', $extracted);
        $this->assertStringNotContainsString($r->source_snapshot['vendor']['name'], $extracted);
        $this->assertStringContainsString('Page 2 of', $extracted);
    }

    public function test_drafts_can_retain_gaps_but_never_approve_and_validity_cannot_exceed_vendor(): void
    {
        $p = $this->quotationInput();
        $p['intent'] = 'draft';
        $p['markup_percent'] = null;
        $p['customer_tax_treatment'] = 'unknown';
        $r = $this->save($p);
        $this->assertNull($r->pricing['total']);
        $this->blocked(fn () => $this->approve($r), 'markup');
        $p = $this->quotationInput(1);
        $p['valid_until'] = now()->addYears(2)->format('Y-m-d\TH:i');
        $r = $this->save($p);
        $this->blocked(fn () => $this->approve($r), 'beyond');
        $p = $this->quotationInput(2);
        $p['valid_until'] = now()->subDays(1)->format('Y-m-d\TH:i');
        $r = $this->save($p);
        $this->blocked(fn () => $this->approve($r), 'expired');
    }

    public function test_new_draft_blocks_old_approval_and_preserves_branding_pdf_email_and_history(): void
    {
        $r = $this->save();
        $a = $this->approve($r);
        $bytes = QuotationPdf::bytes($r);
        CompanySetting::current()->update(['display_name' => 'Changed company branding']);
        $this->assertSame($bytes, QuotationPdf::bytes($r));
        $p = $this->quotationInput(1);
        $p['intent'] = 'draft';
        $p['markup_percent'] = '30';
        $p['body'] = 'Revised customer email';
        $new = $this->save($p);
        $this->assertSame(2, $new->number);
        $this->assertNull($new->approval);
        $this->blocked(fn () => app(MailRelease::class)->source('client_quote', $a->id, $this->staff), 'newer');
        $this->assertSame('Superseded', $r->fresh()->label());
        $this->assertSame($bytes, QuotationPdf::bytes($r));
        $this->assertSame($a->digest, $a->fresh()->digest);
        $this->assertSame('Changed company branding', $new->payload['company']['name']);
        $this->blocked(fn () => $this->save($this->quotationInput(1)), 'newer');
        $this->post(route('quotations.save', $this->case), $this->quotationInput(1))->assertSessionHasErrors('processing');
        $this->get(route('quotations.index', $this->case))->assertOk()->assertSee('name="expected_revision" value="1"', false);
        $this->assertSame(2, $r->quotation->fresh()->current_number);
    }

    public function test_foreign_recipient_guest_inactive_staff_and_case_scoping_are_enforced(): void
    {
        $p = $this->quotationInput();
        $p['to_contact_id'] = ClientContact::factory()->create()->id;
        $this->post(route('quotations.save', $this->case), $p)->assertSessionHasErrors('processing');
        $r = $this->save();
        $other = Inquiry::factory()->create();
        $this->get(route('quotations.pdf', [$other, $r]))->assertNotFound();
        $this->get(route('quotations.pdf', [$this->case, $r]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs(User::factory()->create(['is_active' => false]))->get(route('quotations.pdf', [$this->case, $r]))->assertRedirect(route('login'));
        auth()->logout();
        $this->get(route('quotations.pdf', [$this->case, $r]))->assertRedirect(route('login'));
        $this->get('/request-quote')->assertDontSee('1560.00')->assertDontSee('PRIVATE PROFIT');
    }

    public function test_recipient_changes_and_sender_identity_changes_block_exact_release(): void
    {
        $r = $this->save();
        $a = $this->approve($r);
        $this->case->contact->update(['email' => 'changed@client.example']);
        $this->blocked(fn () => app(MailRelease::class)->source('client_quote', $a->id, $this->staff), 'recipient');
        $this->case->contact->update(['email' => $r->payload['to']['email']]);
        $this->connection->update(['target_email' => 'changed@example.test']);
        $source = app(MailRelease::class)->source('client_quote', $a->id, $this->staff);
        $this->blocked(fn () => app(MailRelease::class)->preview($source, $this->connection->fresh()), 'sender');
    }

    public function test_worker_blocks_changed_rate_and_changed_shipment_after_enqueue(): void
    {
        $r = $this->save();
        $d = $this->dispatch($r);
        $offer = $this->selection->revision->offer;
        $p = $offer->current()->payload;
        $p['expected_revision'] = $offer->current_number;
        $p['change_reason'] = 'Vendor changes rate';
        $p['lines'][0]['rate'] = '700';
        $p['quoted_total'] = null;
        $p['total_not_stated_reason'] = 'New rate pending total';
        app(ManageOffer::class)->save($offer, $this->staff, $p, true);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertNull($d->fresh()->submission_started_at);
        $this->assertStringContainsString('Superseded', $d->fresh()->last_error);
        $shipment = $this->case->shipment;
        $shipment['cargo_description'] = 'Changed cargo';
        $this->case->update(['shipment' => $shipment, 'shipment_revision' => $this->case->shipment_revision + 1]);
        $this->assertStringContainsString('shipment', implode(' ', QuotationEligibility::reasons($r)));
    }

    public function test_missing_or_tampered_pdf_and_immutable_database_evidence_are_enforced(): void
    {
        $r = $this->save();
        $a = $this->approve($r);
        Storage::disk('inquiry_documents')->put($r->pdf_path, '%PDF-changed');
        $this->blocked(fn () => app(MailRelease::class)->source('client_quote', $a->id, $this->staff), 'checksum');
        foreach ([['client_quotation_revisions', $r->id, ['pdf_checksum' => str_repeat('0', 64)]], ['client_quotation_approvals', $a->id, ['digest' => str_repeat('0', 64)]]] as [$table,$id,$data]) {
            DB::beginTransaction();
            try {
                DB::table($table)->where('id', $id)->update($data);
                $this->fail('Immutable evidence was changed');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Frozen mail evidence', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }
        Storage::disk('inquiry_documents')->delete($r->pdf_path);
        $this->get(route('quotations.pdf', [$this->case, $r]))->assertSessionHasErrors('processing');
    }

    public function test_uncertain_send_never_blindly_retries_or_authorizes_resend(): void
    {
        $r = $this->save();
        $d = $this->dispatch($r);
        $real = app(GraphMail::class);
        $mock = \Mockery::mock(GraphMail::class)->makePartial();
        $mock->shouldReceive('call')->andReturnUsing(function ($c, $method, $path, $data = [], $token = null, $text = false) use ($real) {
            if ($method === 'POST' && str_ends_with($path, '/send')) {
                throw new GraphFailure(0, 30, true);
            }

            return $real->call($c, $method, $path, $data, $token, $text);
        });
        app()->instance(GraphMail::class, $mock);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(2)->seconds();
            (new DispatchMail($d->id))->handle();
        }
        $this->assertSame('uncertain', $d->fresh()->status);
        $attempts = $d->fresh()->attempts;
        (new DispatchMail($d->id))->handle();
        $this->assertSame($attempts, $d->fresh()->attempts);
        $p = $this->quotationInput(1);
        $p['resend_of_id'] = $d->id;
        $p['resend_confirmed'] = true;
        $files = Storage::disk('inquiry_documents')->allFiles();
        $this->blocked(fn () => $this->save($p), 'resolved earlier');
        $this->assertSame($files, Storage::disk('inquiry_documents')->allFiles());
    }

    public function test_manual_communication_excludes_outbox_and_connecting_alone_sends_nothing(): void
    {
        $r = $this->save();
        $a = $this->approve($r);
        $this->post(route('quotations.manual', [$this->case, $r]), ['confirm' => '1', 'reason' => 'Controlled fictional manual-send declaration'])->assertRedirect();
        $this->blocked(fn () => app(MailRelease::class)->source('client_quote', $a->id, $this->staff), 'manually');
        $this->assertSame(0, MailDispatch::count());
        Queue::assertNotPushed(DispatchMail::class);
        Mail::assertNothingSent();
    }

    public function test_unconfigured_mailbox_keeps_draft_and_pdf_review_usable_without_approval(): void
    {
        $this->connection->update(['state' => 'disconnected']);
        $r = $this->save();
        $this->get(route('quotations.review', [$this->case, $r]))->assertOk()->assertSee('Connect or resume')->assertSee('Download PDF')->assertDontSee('Approve exact quotation');
        $this->blocked(fn () => $this->approve($r), 'Connect');
        $this->assertSame(0, ClientQuotationApproval::count());
    }

    public function test_pending_prior_dispatch_and_deliberate_resend_are_checked_before_approval(): void
    {
        $first = $this->save();
        $pending = $this->dispatch($first);
        $second = $this->save($this->quotationInput(1));
        $this->blocked(fn () => $this->approve($second), 'pending or uncertain');
        app(MailOutbox::class)->cancel($pending, $this->staff);
        $sent = $this->dispatch($second);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(2)->seconds();
            (new DispatchMail($sent->id))->handle();
        }
        $this->assertSame('accepted', $sent->fresh()->status);
        $third = $this->save($this->quotationInput(2));
        $this->blocked(fn () => $this->approve($third), 'deliberate resend');
        $p = $this->quotationInput(3);
        $p['resend_of_id'] = (string) $sent->id;
        $p['resend_confirmed'] = true;
        $fourth = $this->save($p);
        $this->assertSame([], QuotationEligibility::reasons($fourth->fresh()));
        $this->approve($fourth);
        $this->assertSame($sent->id, $fourth->resend_of_id);
        $this->assertSame(2, MailDispatch::count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_worker_rechecks_expiry_after_provider_preparation_before_submission(): void
    {
        $r = $this->save();
        $d = $this->dispatch($r);
        $real = app(GraphMail::class);
        $mock = \Mockery::mock(GraphMail::class)->makePartial();
        $mock->shouldReceive('call')->andReturnUsing(function ($c, $method, $path, $data = [], $token = null, $text = false) use ($real) {
            $response = $real->call($c, $method, $path, $data, $token, $text);
            if ($method === 'GET' && $text) {
                $this->travel(40)->days();
            }

            return $response;
        });
        app()->instance(GraphMail::class, $mock);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(2)->seconds();
            (new DispatchMail($d->id))->handle();
        }
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertNotNull($d->fresh()->provider_draft_id);
        $this->assertNull($d->fresh()->submission_started_at);
        $this->assertStringContainsString('expired', $d->fresh()->last_error);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
