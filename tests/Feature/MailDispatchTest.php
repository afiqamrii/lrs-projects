<?php

namespace Tests\Feature;

use App\Actions\MailIngest;
use App\Actions\MailOutbox;
use App\Actions\ManageClarification;
use App\Actions\ManageRfq;
use App\Actions\TransitionInquiry;
use App\Jobs\DispatchMail;
use App\Jobs\ReconcileMail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use App\Models\MailDispatch;
use App\Models\MailEnvelope;
use App\Models\Rfq;
use App\Models\RfqApproval;
use App\Models\User;
use App\Models\Vendor;
use App\Support\GraphMail;
use App\Support\InquiryWorkflow;
use App\Support\Mailboxes;
use App\Support\MailRelease;
use App\Support\Processing;
use App\Support\RfqContent;
use App\Support\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private MailboxConnection $connection;

    private array $drafts = [];

    private int $sendStatus = 202;

    private bool $timeoutSend = false;

    private bool $hideSent = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('inquiry_documents');
        Storage::fake('mailbox');
        $this->staff = User::factory()->create(['role' => 'agent']);
        $this->actingAs($this->staff);
        CompanySetting::current()->update(['rfq_reply_name' => 'Synthetic sourcing', 'rfq_reply_email' => 'operations@example.test', 'rfq_signature' => 'Synthetic signature']);
        $this->connection = MailboxConnection::factory()->create();
        $this->connection->update(['identity_hash' => app(Mailboxes::class)->identity($this->connection)]);
        $this->fakeGraph();
    }

    private function fakeGraph(): void
    {
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            if (str_ends_with($path, '/messages') && $request->method() === 'POST') {
                $id = 'provider-draft-'.(count($this->drafts) + 1);
                $d = $request->data() + ['id' => $id, 'isDraft' => true, 'internetMessageId' => '<'.$id.'@example.test>', 'sender' => $request->data()['from'], 'attachments' => []];
                $this->drafts[$id] = $d;

                return Http::response($d, 201);
            }
            if (str_ends_with($path, '/messages')) {
                preg_match("/ep\/value eq '([0-9a-f-]+)'/i", $query['$filter'] ?? '', $match);

                return Http::response(['value' => array_values(array_filter($this->drafts, fn ($d) => ! $this->hideSent && ($d['singleValueExtendedProperties'][0]['value'] ?? null) === ($match[1] ?? null)))]);
            }
            preg_match('~/messages/([^/]+)(.*)$~', $path, $match);
            $id = rawurldecode($match[1] ?? '');
            $tail = $match[2] ?? '';
            if (! isset($this->drafts[$id]) || ($this->hideSent && ! $this->drafts[$id]['isDraft'])) {
                return Http::response([], 404);
            }
            if ($tail === '/send') {
                if ($this->timeoutSend) {
                    $this->drafts[$id]['isDraft'] = false;

                    return Http::failedConnection();
                } if ($this->sendStatus === 202) {
                    $this->drafts[$id]['isDraft'] = false;
                }

                return Http::response([], $this->sendStatus, ['Retry-After' => '17']);
            }
            if ($tail === '/attachments' && $request->method() === 'POST') {
                $file = $request->data() + ['id' => 'attachment-'.count($this->drafts[$id]['attachments']), 'size' => strlen(base64_decode($request->data()['contentBytes']))];
                $this->drafts[$id]['attachments'][] = $file;

                return Http::response($file, 201);
            }
            if ($tail === '/attachments') {
                return Http::response(['value' => array_map(fn ($a) => array_diff_key($a, ['contentBytes' => 1]), $this->drafts[$id]['attachments'])]);
            }
            if (preg_match('~^/attachments/([^/]+)/\$value$~', $tail, $a)) {
                foreach ($this->drafts[$id]['attachments'] as $file) {
                    if ($file['id'] === $a[1]) {
                        return Http::response(base64_decode($file['contentBytes']), 200);
                    }
                }
            }

            return Http::response(array_diff_key($this->drafts[$id], ['attachments' => true]));
        });
    }

    private function approved(?Inquiry $inquiry = null, array $files = []): RfqApproval
    {
        if (! $inquiry) {
            $client = Client::factory()->create();
            $contact = ClientContact::factory()->create(['client_id' => $client->id]);
            $cargo = Shipment::normalize(['mode' => 'LCL', 'scope' => 'port_to_port', 'cargo_description' => 'Synthetic cargo', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore', 'cargo_ready_date' => '2026-11-01', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 1, 'gross_weight' => '100', 'weight_unit' => 'kg', 'length' => '100', 'width' => '100', 'height' => '100', 'dimension_unit' => 'cm']]]);
            $inquiry = Inquiry::factory()->create(['client_id' => $client->id, 'client_contact_id' => $contact->id, 'owner_id' => $this->staff->id, 'response_due_at' => now()->addDays(3), 'shipment' => $cargo, 'status' => 'needs_review']);
            app(TransitionInquiry::class)->handle($inquiry, ['lock_version' => 0, 'target' => 'ready_for_sourcing']);
            $inquiry = $inquiry->fresh();
        }
        $v = Vendor::factory()->create(['services' => ['LCL']]);
        $v->contacts()->create(['name' => 'Synthetic desk', 'email' => 'vendor'.$v->id.'@example.test', 'is_primary' => true, 'is_active' => true]);
        $this->post(route('inquiries.sourcing.select', $inquiry), ['lock_version' => $inquiry->lock_version, 'vendor_ids' => [$v->id]])->assertSessionHasNoErrors();
        $rfq = Rfq::where('vendor_id', $v->id)->firstOrFail();
        $p = $rfq->current()->payload;
        $data = ['expected_revision' => 1, 'to_contact_id' => $p['to']['id'], 'cc_contact_ids' => [], 'subject' => $p['subject'], 'opening' => $p['opening'], 'closing' => $p['closing'], 'response_due_at' => InquiryWorkflow::local(now()->addDay()), 'currency' => 'USD', 'document_ids' => $files, 'attachments_reviewed' => 1, 'disclosure_notes' => $files ? 'Necessary synthetic packing list' : ''];
        app(ManageRfq::class)->save($rfq, $this->staff, $data);
        $revision = $rfq->fresh()->current();

        return app(ManageRfq::class)->approve($rfq->fresh(), $this->staff, $revision->number, Processing::hash(RfqContent::snapshot($revision)));
    }

    private function envelope(RfqApproval $approval): MailEnvelope
    {
        $source = app(MailRelease::class)->source('rfq', $approval->id, $this->staff);
        $preview = app(MailRelease::class)->preview($source, $this->connection->fresh());

        return app(MailOutbox::class)->authorize('rfq', $approval->id, $this->staff, Processing::hash($preview));
    }

    private function enqueue(RfqApproval $a): MailDispatch
    {
        $e = $this->envelope($a);

        return app(MailOutbox::class)->enqueue($e, $this->staff, (string) Str::uuid(), $e->digest);
    }

    private function processDispatch(MailDispatch $d): void
    {
        (new DispatchMail($d->id))->handle();
    }

    public function test_three_vendors_have_individual_approved_envelopes_atomic_dispatches_and_exact_provider_recipients(): void
    {
        $a = $this->approved();
        $i = $a->revision->rfq->inquiry;
        $approvals = [$a, $this->approved($i), $this->approved($i)];
        $emails = [];
        foreach ($approvals as $approval) {
            $e = $this->envelope($approval);
            $this->assertDatabaseCount('mail_dispatches', count($emails));
            $key = (string) Str::uuid();
            $d = app(MailOutbox::class)->enqueue($e, $this->staff, $key, $e->digest);
            $this->assertSame($d->id, app(MailOutbox::class)->enqueue($e, $this->staff, $key, $e->digest)->id);
            $this->processDispatch($d);
            $this->processDispatch($d);
            $this->assertSame('accepted', $d->fresh()->status);
            $emails[] = $approval->snapshot['to']['email'];
            (new ReconcileMail($d->id))->handle();
            $this->assertSame('observed', $d->fresh()->status);
            $this->get(route('mail.dispatch', $d))->assertOk()->assertSee('Delivery / reading')->assertSee('Unconfirmed')->assertDontSee('test-access');
        }
        $this->assertCount(3, $this->drafts);
        $this->assertCount(3, array_unique($emails));
        foreach ($this->drafts as $draft) {
            $this->assertCount(1, $draft['toRecipients']);
            $this->assertSame([], $draft['ccRecipients']);
        }
        $this->assertDatabaseCount('mail_dispatches', 3);
        Http::assertSentCount(18);
    }

    public function test_enqueue_rejects_stale_envelope_changed_mailbox_and_previous_manual_declaration(): void
    {
        $a = $this->approved();
        $e = $this->envelope($a);
        $this->connection->update(['target_email' => 'changed@example.test', 'identity_hash' => hash('sha256', 'changed')]);
        $this->post(route('mail.enqueue', $e), ['action_key' => (string) Str::uuid(), 'digest' => $e->digest, 'send_exact' => 1])->assertSessionHasErrors('release');
        $this->assertDatabaseCount('mail_dispatches', 0);
        $this->connection->update(['target_email' => 'operations@example.test', 'identity_hash' => $e->identity_hash]);
        $rfq = $a->revision->rfq;
        app(ManageRfq::class)->manual($rfq, $this->staff, ['expected_revision' => $a->revision->number, 'digest' => $a->digest, 'action_key' => (string) Str::uuid(), 'recipient' => $a->snapshot['to']['email'], 'sent_at' => InquiryWorkflow::local(now()), 'channel' => 'email', 'evidence' => 'Synthetic declaration']);
        $this->post(route('mail.enqueue', $e), ['action_key' => (string) Str::uuid(), 'digest' => $e->digest, 'send_exact' => 1])->assertSessionHasErrors('release');
        Http::assertNothingSent();
    }

    public function test_queued_hold_inactive_contact_and_changed_document_block_provider_submission(): void
    {
        $a = $this->approved();
        $d = $this->enqueue($a);
        $a->revision->rfq->inquiry->update(['status' => 'on_hold', 'status_reason' => 'Synthetic hold']);
        $this->processDispatch($d);
        $this->assertSame('failed', $d->fresh()->status);
        Http::assertNothingSent();
        $b = $this->approved();
        $d2 = $this->enqueue($b);
        $b->revision->rfq->vendor->contacts()->update(['is_active' => false]);
        $this->processDispatch($d2);
        $this->assertSame('failed', $d2->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_timeout_after_submission_is_uncertain_and_never_blindly_retried_even_if_draft_disappears(): void
    {
        $a = $this->approved();
        $d = $this->enqueue($a);
        $this->timeoutSend = true;
        $this->hideSent = true;
        $this->processDispatch($d);
        $this->assertSame('uncertain', $d->fresh()->status);
        $this->assertNotNull($d->fresh()->submission_started_at);
        $this->processDispatch($d);
        app(MailOutbox::class)->recover($d->fresh(), $this->staff, 'Synthetic provider timeout review');
        (new ReconcileMail($d->id))->handle();
        $this->assertSame('uncertain', $d->fresh()->status);
        $this->assertCount(1, $this->drafts);
        $sends = Http::recorded(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/send'));
        $this->assertCount(1, $sends);
        $this->hideSent = false;
        (new ReconcileMail($d->id))->handle();
        $this->assertSame('observed', $d->fresh()->status);
    }

    public function test_acceptance_does_not_invent_sent_item_delivery_and_late_cancellation_does_not_recall(): void
    {
        $d = $this->enqueue($this->approved());
        $this->processDispatch($d);
        $this->hideSent = true;
        (new ReconcileMail($d->id))->handle();
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertNull($d->fresh()->observed_at);
        app(MailOutbox::class)->cancel($d->fresh(), $this->staff);
        $this->assertNotNull($d->fresh()->cancel_requested_at);
        $this->assertSame('accepted', $d->fresh()->status);
    }

    public function test_throttled_send_respects_retry_after_and_rechecks_current_eligibility_before_safe_rejected_retry(): void
    {
        $d = $this->enqueue($this->approved());
        $this->sendStatus = 429;
        $this->processDispatch($d);
        $this->assertSame('ready', $d->fresh()->status);
        $this->assertTrue($d->fresh()->next_attempt_at->gt(now()->addSeconds(15)));
        $this->processDispatch($d);
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/send')));
        $d->envelope->inquiry->update(['status' => 'closed', 'status_reason' => 'Synthetic closure']);
        $d->update(['next_attempt_at' => now()->subSecond()]);
        $this->processDispatch($d);
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/send')));
    }

    public function test_duplicate_live_claim_and_cancelled_dispatch_do_not_create_provider_drafts(): void
    {
        $d = $this->enqueue($this->approved());
        $d->update(['lease' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
        $this->processDispatch($d);
        Http::assertNothingSent();
        app(MailOutbox::class)->cancel($d->fresh(), $this->staff);
        $this->processDispatch($d);
        $this->assertSame('cancelled', $d->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_outlook_dispatch_blocks_manual_record_and_another_action_key(): void
    {
        $a = $this->approved();
        $d = $this->enqueue($a);
        $e = $d->envelope;
        $this->post(route('mail.enqueue', $e), ['action_key' => (string) Str::uuid(), 'digest' => $e->digest, 'send_exact' => 1])->assertSessionHasErrors('release');
        $this->post(route('rfqs.manual', [$a->revision->rfq->inquiry_id, $a->revision->rfq_id]), ['expected_revision' => $a->revision->number, 'digest' => $a->digest, 'action_key' => (string) Str::uuid(), 'recipient' => $a->snapshot['to']['email'], 'sent_at' => InquiryWorkflow::local(now()), 'channel' => 'email', 'confirm_exact' => 1])->assertSessionHasErrors('manual_send');
        $this->assertDatabaseCount('rfq_dispatches', 0);
    }

    public function test_provider_draft_change_blocks_submission_and_encrypted_evidence_is_database_immutable(): void
    {
        $a = $this->approved();
        $d = $this->enqueue($a);
        $this->drafts['persisted'] = ['id' => 'persisted', 'isDraft' => true, 'subject' => 'Changed subject', 'body' => ['content' => 'Different body'], 'attachments' => [], 'from' => ['emailAddress' => ['address' => 'operations@example.test']], 'sender' => ['emailAddress' => ['address' => 'operations@example.test']]];
        $d->update(['provider_draft_id' => 'persisted', 'draft_started_at' => now()]);
        $this->processDispatch($d);
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertNull($d->fresh()->submission_started_at);
        $this->assertNotSame(json_encode($d->envelope->snapshot), DB::table('mail_envelopes')->where('id', $d->mail_envelope_id)->value('snapshot'));
        $this->expectException(QueryException::class);
        DB::table('mail_envelopes')->where('id', $d->mail_envelope_id)->update(['digest' => str_repeat('0', 64)]);
    }

    private function approvedFile(Inquiry $i, string $bytes): InquiryDocument
    {
        $path = 'test/'.Str::uuid().'.pdf';
        Storage::disk('inquiry_documents')->put($path, $bytes);

        return InquiryDocument::factory()->create(['inquiry_id' => $i->id, 'original_name' => 'Synthetic packing.pdf', 'storage_path' => $path, 'mime' => 'application/pdf', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'classification' => 'packing_list']);
    }

    public function test_changed_approved_file_bytes_block_before_any_provider_call(): void
    {
        $base = $this->approved();
        $doc = $this->approvedFile($base->revision->rfq->inquiry, "%PDF-1.4\nSynthetic\n%%EOF");
        $a = $this->approved($base->revision->rfq->inquiry, [$doc->id]);
        $d = $this->enqueue($a);
        Storage::disk('inquiry_documents')->put($doc->storage_path, 'Changed bytes');
        $this->processDispatch($d);
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertNull($d->fresh()->submission_started_at);
        Http::assertNothingSent();
    }

    public function test_small_upload_is_verified_and_duplicate_jobs_do_not_duplicate_files(): void
    {
        $base = $this->approved();
        $bytes = "%PDF-1.4\nSynthetic\n%%EOF";
        $doc = $this->approvedFile($base->revision->rfq->inquiry, $bytes);
        $d = $this->enqueue($this->approved($base->revision->rfq->inquiry, [$doc->id]));
        $this->processDispatch($d);
        $this->assertSame('preparing', $d->fresh()->status);
        $this->assertCount(1, reset($this->drafts)['attachments']);
        $d->refresh()->update(['next_attempt_at' => null]);
        $this->processDispatch($d);
        $this->processDispatch($d);
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertCount(1, reset($this->drafts)['attachments']);
        $this->assertSame($bytes, base64_decode(reset($this->drafts)['attachments'][0]['contentBytes']));
        Http::assertSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/send') && $r->body() === '' && $r->header('Content-Length') === ['0']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/attachments?$select=') && str_contains($r->header('Prefer')[0], 'ImmutableId'));
    }

    public function test_own_large_upload_resumes_after_expired_session_and_shared_mailbox_blocks_it(): void
    {
        $base = $this->approved();
        $i = $base->revision->rfq->inquiry;
        $bytes = "%PDF-1.4\n".str_repeat('x', 3000010)."\n%%EOF";
        $doc = $this->approvedFile($i, $bytes);
        $a = $this->approved($i, [$doc->id]);
        $this->connection->update(['mailbox_type' => 'shared', 'identity_hash' => hash('sha256', 'shared')]);
        $this->get(route('mail.preview', ['rfq', $a->id]))->assertOk()->assertSee('Each file must be below 3 MB');
        $this->assertDatabaseCount('mail_dispatches', 0);
        config(['mailbox.demo_enabled' => true]);
        $i->update(['is_demo' => true]);
        $this->connection->update(['is_demo' => true, 'mailbox_type' => 'personal']);
        $this->connection->update(['identity_hash' => app(Mailboxes::class)->identity($this->connection)]);
        $d = $this->enqueue($a);
        $this->processDispatch($d);
        $first = $d->fresh()->upload_state;
        $this->assertNotNull($first);
        $this->assertStringNotContainsString('AttachmentSessions', DB::table('mail_dispatches')->where('id', $d->id)->value('upload_state'));
        $first['expires_at'] = now()->subMinute()->toIso8601String();
        $d->refresh()->update(['upload_state' => $first, 'next_attempt_at' => null]);
        $this->processDispatch($d);
        $this->assertNotSame($first['url'], $d->fresh()->upload_state['url']);
        for ($n = 0; $n < 5 && $d->fresh()->status !== 'accepted'; $n++) {
            $d->refresh()->update(['next_attempt_at' => null]);
            $this->processDispatch($d);
        }
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertNull($d->fresh()->upload_state);
        $path = GraphMail::messages($this->connection->target_id).'/'.$d->fresh()->provider_draft_id;
        $files = app(GraphMail::class)->list($this->connection->fresh(), $path.'/attachments');
        $this->assertCount(1, $files);
        $this->assertSame(strlen($bytes), $files[0]['size']);
        $stored = app(GraphMail::class)->call($this->connection->fresh(), 'GET', $path.'/attachments/'.$files[0]['id'].'/$value');
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $stored));
        Http::assertNothingSent();
    }

    public function test_real_and_fixture_transport_cannot_release_each_others_inquiries(): void
    {
        $approval = $this->approved();
        $inquiry = $approval->revision->rfq->inquiry;
        $inquiry->update(['is_demo' => true]);
        $this->get(route('mail.preview', ['rfq', $approval->id]))->assertOk()->assertSee('matching data provenance');
        $this->assertDatabaseCount('mail_envelopes', 0);
        config(['mailbox.demo_enabled' => true]);
        $this->connection->update(['is_demo' => true]);
        $inquiry->update(['is_demo' => false]);
        $this->get(route('mail.preview', ['rfq', $approval->id]))->assertOk()->assertSee('matching data provenance');
        $this->assertDatabaseCount('mail_dispatches', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_draft_creation_result_cannot_create_a_replacement_or_send(): void
    {
        $d = $this->enqueue($this->approved());
        Http::fake(['https://graph.microsoft.com/*' => Http::failedConnection()]);
        $this->processDispatch($d);
        $this->assertSame('uncertain', $d->fresh()->status);
        $this->assertNull($d->fresh()->provider_draft_id);
        $this->processDispatch($d);
        $this->assertCount(1, Http::recorded());
        $this->assertNull($d->fresh()->submission_started_at);
        Http::fake(['https://graph.microsoft.com/*' => Http::response(['value' => []])]);
        (new ReconcileMail($d->id))->handle();
        $this->assertSame('uncertain', $d->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_approved_clarification_send_and_known_customer_reply_preserve_shipment_review_gate(): void
    {
        $client = Client::factory()->create();
        $contact = ClientContact::factory()->create(['client_id' => $client->id, 'email' => 'client@example.test']);
        $i = Inquiry::factory()->create(['client_id' => $client->id, 'client_contact_id' => $contact->id, 'owner_id' => $this->staff->id, 'status' => 'needs_review']);
        $action = app(ManageClarification::class);
        $item = $action->prepare($i, 0);
        $action->approve($i, $item, 0);
        $item = $item->fresh();
        $preview = app(MailRelease::class)->preview(app(MailRelease::class)->source('clarification', $item->id, $this->staff), $this->connection->fresh());
        $this->get(route('mail.preview', ['clarification', $item->id]))->assertOk()->assertSee($item->recipient_email);
        $e = app(MailOutbox::class)->authorize('clarification', $item->id, $this->staff, Processing::hash($preview));
        $d = app(MailOutbox::class)->enqueue($e, $this->staff, (string) Str::uuid(), $e->digest);
        $shipment = $i->fresh()->shipment;
        $this->processDispatch($d);
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertSame('needs_client_information', $i->fresh()->status);
        $this->assertSame($item->body, reset($this->drafts)['body']['content']);
        $this->assertDatabaseCount('inquiry_communications', 0);
        $f = MailboxFolder::factory()->create(['identity_hash' => $this->connection->identity_hash]);
        $m = app(MailIngest::class)->handle($f, ['id' => 'client-reply', 'isDraft' => false, 'subject' => 'Re: Clarification', 'receivedDateTime' => now()->toIso8601String(), 'from' => ['emailAddress' => ['address' => $contact->email]], 'body' => ['contentType' => 'Text', 'content' => 'Updated cargo details need review.'], 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => $d->fresh()->internet_id]]]);
        $this->assertSame($i->id, $m->inquiry_id);
        $this->assertSame('customer', $m->classification);
        $this->assertNull($m->rfq_revision_id);
        $this->assertSame('needs_review', $i->fresh()->status);
        $this->assertSame($shipment, $i->fresh()->shipment);
        $this->assertSame(1, $i->fresh()->shipment_revision);
    }

    public function test_known_permission_rejection_pauses_and_recovers_same_draft_after_reconnection(): void
    {
        $d = $this->enqueue($this->approved());
        $this->sendStatus = 403;
        $this->processDispatch($d);
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame('paused', $this->connection->fresh()->state);
        $draft = $d->fresh()->provider_draft_id;
        $this->connection->refresh()->update(['state' => 'connected']);
        $this->sendStatus = 202;
        app(MailOutbox::class)->recover($d->fresh(), $this->staff, 'Synthetic permission repaired');
        $this->processDispatch($d);
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertSame($draft, $d->fresh()->provider_draft_id);
        $this->assertCount(1, $this->drafts);
    }

    public function test_verified_transport_limit_is_blocked_before_queueing(): void
    {
        $a = $this->approved();
        $this->connection->update(['transport_limit' => 100]);
        $this->get(route('mail.preview', ['rfq', $a->id]))->assertOk()->assertSee('exceeds the configured verified tenant limit');
        $this->assertDatabaseCount('mail_dispatches', 0);
        Http::assertNothingSent();
    }
}
