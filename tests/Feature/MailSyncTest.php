<?php

namespace Tests\Feature;

use App\Actions\MailboxSync;
use App\Actions\MailIngest;
use App\Jobs\ImportMailAttachments;
use App\Models\ClientContact;
use App\Models\Inquiry;
use App\Models\MailAttachment;
use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use App\Models\MailDispatch;
use App\Models\MailEnvelope;
use App\Models\MailMessage;
use App\Models\Rfq;
use App\Models\RfqApproval;
use App\Models\RfqDispatch;
use App\Models\RfqRevision;
use App\Models\User;
use App\Support\Audit;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\InquiryWorkflow;
use App\Support\RfqContent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private MailboxFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('mailbox');
        Storage::fake('inquiry_documents');
        $this->staff = User::factory()->create(['role' => 'agent']);
        $this->actingAs($this->staff);
        MailboxConnection::factory()->create();
        $this->folder = MailboxFolder::factory()->create();
    }

    private function source(string $id, array $extra = []): array
    {
        return array_replace(['id' => $id, 'subject' => 'New synthetic shipment request', 'from' => ['emailAddress' => ['name' => 'Synthetic customer', 'address' => 'customer@example.test']], 'sender' => ['emailAddress' => ['address' => 'customer@example.test']], 'body' => ['contentType' => 'Text', 'content' => 'Please review this synthetic request.'], 'toRecipients' => [['emailAddress' => ['address' => 'operations@example.test']]], 'internetMessageId' => '<'.$id.'@example.test>', 'receivedDateTime' => now()->toIso8601String(), 'sentDateTime' => now()->toIso8601String(), 'isDraft' => false, 'hasAttachments' => false], $extra);
    }

    private function url(string $token = 'finish'): string
    {
        return 'https://graph.microsoft.com/v1.0/users/'.$this->folder->mailbox_id.'/mailFolders/'.$this->folder->provider_id.'/messages/delta?\$deltatoken='.$token;
    }

    private function ingest(string $id, array $extra = []): MailMessage
    {
        return app(MailIngest::class)->handle($this->folder, $this->source($id, $extra));
    }

    public function test_new_customer_email_creates_needs_review_without_directory_merge_and_original_html_is_escaped(): void
    {
        $existing = Inquiry::factory()->create(['source_channel' => 'website', 'public_contact' => ['name' => 'Existing', 'email' => 'customer@example.test', 'company' => 'Existing company']]);
        $m = $this->ingest('customer-1', ['body' => ['contentType' => 'HTML', 'content' => '<p>Real text</p><script>window.evil=1</script><img src="https://tracking.invalid"><a href="javascript:evil()">Untrusted link</a>']]);
        $this->assertSame('needs_review', $m->inquiry->status);
        $this->assertNull($m->inquiry->client_id);
        $this->assertNull($m->inquiry->client_contact_id);
        $this->assertSame('email', $m->inquiry->source_channel);
        $this->assertNotSame($existing->id, $m->inquiry_id);
        $this->assertSame(2, Inquiry::count());
        $this->assertSame('unknown', $m->inquiry->shipment['mode']);
        $this->assertSame('customer', $m->classification);
        $response = $this->get(route('mail.message', $m))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>window.evil', false)->assertDontSee('<img src="https://tracking.invalid"', false);
        $this->assertStringNotContainsString('window.evil', $m->text());
        $this->assertStringContainsString('Real text', $m->text());
        $this->get(route('inquiries.public-contact.edit', $m->inquiry))->assertOk()->assertSee($existing->reference);
        $this->assertNotSame(json_encode($m->source), DB::table('mail_messages')->where('id', $m->id)->value('source'));
        $this->ingest('customer-1');
        $this->assertDatabaseCount('mail_messages', 1);
        $this->assertSame(2, Inquiry::count());
    }

    public function test_delta_pages_cursor_replays_and_folder_moves_preserve_one_original_case_and_deletion_evidence(): void
    {
        $next = $this->url('page2');
        $done = $this->url('finished');
        $calls = 0;
        Http::fake(function ($r) use (&$calls, $next, $done) {
            $calls++;

            return Http::response($calls === 1 ? ['value' => [$this->source('one')], '@odata.nextLink' => $next] : ['value' => [$this->source('one'), $this->source('two')], '@odata.deltaLink' => $done]);
        });
        app(MailboxSync::class)->handle($this->folder->id);
        $this->assertSame($next, $this->folder->fresh()->cursor);
        $this->assertNull($this->folder->fresh()->last_sync_at);
        $this->folder->update(['next_attempt_at' => null]);
        app(MailboxSync::class)->handle($this->folder->id);
        $this->assertSame($done, $this->folder->fresh()->cursor);
        $this->assertNotNull($this->folder->fresh()->last_sync_at);
        $this->assertDatabaseCount('mail_messages', 2);
        $this->assertDatabaseCount('inquiries', 2);
        $other = MailboxFolder::factory()->create(['provider_id' => 'review', 'name' => 'Review']);
        $original = MailMessage::where('provider_id', 'one')->firstOrFail();
        app(MailIngest::class)->handle($this->folder, ['id' => 'one', '@removed' => ['reason' => 'deleted']]);
        app(MailIngest::class)->handle($other, $this->source('one'));
        $this->assertDatabaseCount('mail_messages', 2);
        $this->assertDatabaseCount('inquiries', 2);
        $this->assertDatabaseHas('mail_folder_message', ['mailbox_folder_id' => $this->folder->id, 'mail_message_id' => $original->id]);
        $this->assertNotNull($original->fresh()->deleted_at_provider);
        $this->assertSame($original->inquiry_id, MailMessage::where('provider_id', 'one')->first()->inquiry_id);
        Http::assertSent(fn ($r) => str_contains($r->header('Prefer')[0] ?? '', 'ImmutableId'));
    }

    public function test_interrupted_page_is_durable_and_restarts_at_offset_without_duplicate_sources_or_advancing_cursor(): void
    {
        Http::fake(['https://graph.microsoft.com/*' => Http::response(['value' => [$this->source('first'), ['subject' => 'malformed second']], '@odata.deltaLink' => $this->url()])]);
        app(MailboxSync::class)->handle($this->folder->id);
        $f = $this->folder->fresh();
        $this->assertSame(1, $f->offset);
        $this->assertNull($f->cursor);
        $this->assertNotNull($f->page);
        $this->assertFalse($f->enabled);
        $this->assertDatabaseCount('inquiries', 1);
        $page = $f->page;
        $page['value'][1] = $this->source('second');
        $f->update(['page' => $page, 'enabled' => true, 'next_attempt_at' => null]);
        app(MailboxSync::class)->handle($f->id);
        $this->assertSame($this->url(), $f->fresh()->cursor);
        $this->assertNull($f->fresh()->page);
        $this->assertDatabaseCount('inquiries', 2);
        Http::assertSentCount(1);
        $raw = DB::table('mailbox_folders')->where('id', $f->id)->value('cursor');
        $this->assertStringNotContainsString('deltatoken', $raw);
    }

    public function test_delta_rejects_unexpected_links_and_expired_cursor_resync_is_bounded_without_deleting_cases(): void
    {
        $m = $this->ingest('preserved');
        $this->folder->update(['cursor' => $this->url('old'), 'last_sync_at' => now()->subDay()]);
        Http::fake(['https://graph.microsoft.com/*' => Http::response([], 410)]);
        app(MailboxSync::class)->handle($this->folder->id);
        $this->assertNull($this->folder->fresh()->cursor);
        $this->assertTrue($this->folder->fresh()->enabled);
        $this->assertSame(1, $this->folder->fresh()->resync_count);
        $this->assertDatabaseHas('mail_messages', ['id' => $m->id]);
        $this->folder->update(['next_attempt_at' => null, 'last_sync_at' => now()->subDays(100)]);
        app(MailboxSync::class)->handle($this->folder->id);
        $this->assertFalse($this->folder->fresh()->enabled);
        $this->folder->update(['enabled' => true, 'next_attempt_at' => null]);
        Http::fake(['https://graph.microsoft.com/*' => Http::response(['value' => [$this->source('badlink')], '@odata.nextLink' => 'https://evil.example/steal'])]);
        app(MailboxSync::class)->handle($this->folder->id);
        $this->assertDatabaseCount('inquiries', 1);
    }

    public function test_overlapping_sync_claim_throttling_and_sent_items_never_create_incoming_cases(): void
    {
        $this->folder->update(['lease' => '77777777-7777-4777-8777-777777777777', 'lease_until' => now()->addMinute()]);
        app(MailboxSync::class)->handle($this->folder->id);
        Http::assertNothingSent();
        $this->folder->update(['lease' => null, 'lease_until' => null]);
        Http::fake(['https://graph.microsoft.com/*' => Http::response([], 429, ['Retry-After' => 19])]);
        app(MailboxSync::class)->handle($this->folder->id);
        $this->assertTrue($this->folder->fresh()->next_attempt_at->gt(now()->addSeconds(17)));
        $sent = MailboxFolder::factory()->create(['provider_id' => 'sentitems', 'kind' => 'sent']);
        app(MailIngest::class)->handle($sent, $this->source('our-sent', ['from' => ['emailAddress' => ['address' => 'operations@example.test']]]));
        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseHas('mail_messages', ['provider_id' => 'our-sent', 'direction' => 'outgoing', 'match_state' => 'sent_evidence']);
    }

    private function outbound(): MailDispatch
    {
        $rfq = Rfq::factory()->create();
        $p = RfqContent::defaults($rfq);
        $p['to'] = ['id' => 1, 'name' => 'Synthetic vendor', 'email' => 'vendor@example.test'];
        $revision = RfqRevision::factory()->create(['rfq_id' => $rfq->id, 'payload' => $p, 'status' => 'approved']);
        $a = RfqApproval::factory()->create(['rfq_revision_id' => $revision->id]);
        $e = MailEnvelope::factory()->create(['rfq_approval_id' => $a->id, 'clarification_id' => null, 'inquiry_id' => $rfq->inquiry_id, 'source_key' => 'rfq:'.$a->id, 'content_digest' => $a->digest, 'snapshot' => ['content' => $a->snapshot, 'envelope' => MailboxConnection::current()->envelope('operations@example.test', 'Synthetic company')]]);

        return MailDispatch::factory()->create(['mail_envelope_id' => $e->id, 'source_key' => $e->source_key, 'status' => 'observed', 'internet_id' => '<outbound-known@example.test>', 'submission_started_at' => now(), 'observed_at' => now()]);
    }

    public function test_strong_known_sender_reply_matches_exact_old_revision_and_unknown_sender_is_reviewed(): void
    {
        $d = $this->outbound();
        $rfq = $d->envelope->approval->revision->rfq;
        $old = $d->envelope->approval->revision;
        RfqRevision::factory()->create(['rfq_id' => $rfq->id, 'number' => 2, 'payload' => $old->payload]);
        $rfq->update(['current_number' => 2]);
        $old->update(['status' => 'superseded']);
        $snapshot = $rfq->inquiry->shipment;
        $m = $this->ingest('valid-reply', ['subject' => 'Re: '.$rfq->reference, 'from' => ['emailAddress' => ['address' => 'vendor@example.test']], 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => $d->internet_id]], 'body' => ['contentType' => 'Text', 'content' => 'Our quotation is attached; synthetic rates are not parsed.']]);
        $this->assertSame($old->id, $m->rfq_revision_id);
        $this->assertSame('quote', $m->classification);
        $this->assertTrue($m->oldRevision());
        $this->assertSame($snapshot, $rfq->inquiry->fresh()->shipment);
        $u = $this->ingest('unknown-reply', ['subject' => 'Re: '.$rfq->reference, 'from' => ['emailAddress' => ['address' => 'unknown@example.test']], 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => $d->internet_id]]]);
        $this->assertSame('unmatched', $u->match_state);
        $this->assertNull($u->inquiry_id);
        $this->assertCount(1, $u->candidates);
        $this->get(route('mail.message', $m))->assertOk()->assertSee('Earlier RFQ');
    }

    public function test_automated_notices_and_ambiguous_reference_remain_evidence_and_staff_corrections_are_audited(): void
    {
        $d = $this->outbound();
        $rfq = $d->envelope->approval->revision->rfq;
        $ooo = $this->ingest('ooo', ['subject' => 'Automatic reply: Out of office', 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => $d->internet_id]], 'from' => ['emailAddress' => ['address' => 'vendor@example.test']]]);
        $bounce = $this->ingest('bounce', ['subject' => 'Undeliverable: '.$rfq->reference, 'from' => ['emailAddress' => ['address' => 'postmaster@example.test']]]);
        $noise = $this->ingest('noise', ['subject' => 'Weekly updates', 'internetMessageHeaders' => [['name' => 'List-ID', 'value' => 'mailing-list']]]);
        $this->assertSame('out_of_office', $ooo->classification);
        $this->assertNull($ooo->inquiry_id);
        $this->assertSame('bounce', $bounce->classification);
        $this->assertSame('automated', $noise->match_state);
        $this->post(route('mail.review', $ooo), ['lock_version' => 0, 'decision' => 'associate', 'classification' => 'out_of_office', 'inquiry_id' => $rfq->inquiry_id, 'rfq_revision_id' => $d->envelope->approval->rfq_revision_id, 'reason' => 'Synthetic absence notice belongs to this old request'])->assertSessionHasNoErrors();
        $this->assertSame('out_of_office', $ooo->fresh()->classification);
        $this->assertSame(1, $ooo->fresh()->lock_version);
        $this->assertDatabaseHas('mail_events', ['mail_message_id' => $ooo->id, 'kind' => 'staff_review']);
        $this->post(route('mail.review', $ooo), ['lock_version' => 0, 'decision' => 'ignore', 'classification' => 'noise', 'reason' => 'Stale review'])->assertSessionHasErrors('lock_version');
        $other = RfqRevision::factory()->create();
        RfqApproval::factory()->create(['rfq_revision_id' => $other->id]);
        $this->post(route('mail.review', $bounce), ['lock_version' => 0, 'decision' => 'associate', 'classification' => 'bounce', 'inquiry_id' => $rfq->inquiry_id, 'rfq_revision_id' => $other->id, 'reason' => 'Wrong case'])->assertSessionHasErrors('rfq_revision_id');
        $this->assertDatabaseCount('inquiries', 2);
    }

    public function test_private_attachment_import_validates_actual_bytes_preserves_partial_failures_and_retries_without_duplicates(): void
    {
        $m = $this->ingest('files');
        $pdf = "%PDF-1.4\nSynthetic packing list\n%%EOF";
        Http::fake(function ($r) use ($pdf) {
            if (str_contains($r->url(), '/attachments?')) {
                return Http::response(['value' => [['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'pdf', 'name' => '../../packing.pdf', 'contentType' => 'application/pdf', 'size' => strlen($pdf)], ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'bad', 'name' => 'script.html', 'contentType' => 'text/html', 'size' => 9], ['@odata.type' => '#microsoft.graph.referenceAttachment', 'id' => 'link', 'name' => 'external-reference', 'contentType' => 'application/octet-stream', 'size' => 5]]]);
            }

            return Http::response(str_contains($r->url(), '/pdf/') ? $pdf : '<script/>');
        });
        (new ImportMailAttachments($m->id, $this->folder->id))->handle();
        (new ImportMailAttachments($m->id, $this->folder->id))->handle();
        $this->assertDatabaseCount('mail_attachments', 3);
        $this->assertDatabaseCount('inquiry_documents', 1);
        $this->assertDatabaseCount('mail_attachment_documents', 1);
        $this->assertSame('partial', $m->fresh()->attachment_state);
        $a = $m->attachments()->where('provider_id', 'pdf')->firstOrFail();
        $this->assertSame('packing.pdf', $a->name);
        $this->assertSame(hash('sha256', $pdf), $a->checksum);
        $this->assertDatabaseHas('inquiry_documents', ['provenance' => 'mailbox_import', 'scan_status' => 'unscanned']);
        $this->get(route('mail.attachment', [$m, $a]))->assertOk()->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
        $other = MailAttachment::factory()->create();
        $this->get(route('mail.attachment', [$m, $other]))->assertNotFound();
        auth()->logout();
        $this->get(route('mail.message', $m))->assertRedirect(route('login'));
        $this->get(route('mail.attachment', [$m, $a]))->assertRedirect(route('login'));
    }

    public function test_ambiguous_reference_and_foreign_mailbox_thread_cannot_auto_match(): void
    {
        $d = $this->outbound();
        $rfq = $d->envelope->approval->revision->rfq;
        $old = $d->envelope->approval->revision;
        $new = RfqRevision::factory()->create(['rfq_id' => $rfq->id, 'number' => 2, 'payload' => $old->payload, 'status' => 'approved']);
        $a = RfqApproval::factory()->create(['rfq_revision_id' => $new->id]);
        RfqDispatch::factory()->create(['rfq_approval_id' => $a->id]);
        $m = $this->ingest('ambiguous', ['subject' => 'Re: '.$rfq->reference, 'from' => ['emailAddress' => ['address' => 'vendor@example.test']]]);
        $this->assertSame('unmatched', $m->match_state);
        $this->assertNull($m->inquiry_id);
        $this->assertCount(2, $m->candidates);
        $foreign = MailboxFolder::factory()->create(['mailbox_id' => '99999999-9999-4999-8999-999999999999', 'provider_id' => 'foreign']);
        $m2 = app(MailIngest::class)->handle($foreign, $this->source('foreign-thread', ['subject' => 'Re: unrelated', 'from' => ['emailAddress' => ['address' => 'vendor@example.test']], 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => $d->internet_id]]]));
        $this->assertNull($m2->inquiry_id);
        $this->assertSame('unmatched', $m2->match_state);
        $this->assertSame([], $m2->candidates);
    }

    public function test_repeated_sync_outage_is_bounded_and_admin_cannot_reset_active_page(): void
    {
        Http::fake(['https://graph.microsoft.com/*' => Http::response([], 503)]);
        for ($n = 0; $n < 6; $n++) {
            $this->folder->refresh()->update(['next_attempt_at' => null]);
            app(MailboxSync::class)->handle($this->folder->id);
        }
        $this->assertFalse($this->folder->fresh()->enabled);
        $this->assertSame(6, $this->folder->fresh()->failure_count);
        $this->assertNull($this->folder->fresh()->cursor);
        app(MailboxSync::class)->handle($this->folder->id);
        Http::assertSentCount(6);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->folder->refresh()->update(['lease' => Str::uuid(), 'lease_until' => now()->addMinute()]);
        $this->post(route('settings.mailbox.folder-action', $this->folder), ['action' => 'resync', 'reason' => 'Synthetic repair', 'import_from' => InquiryWorkflow::local(now()->subHour()), 'confirm' => 1])->assertSessionHasErrors('folder');
        $this->assertSame(6, $this->folder->fresh()->failure_count);
    }

    public function test_delta_opaque_ids_are_case_sensitive_and_foreign_collection_links_are_rejected(): void
    {
        $url = 'https://graph.microsoft.com/v1.0/users/'.$this->folder->mailbox_id."/mailFolders('OpaqueABC')/messages/delta?%24deltatoken=preserve";
        GraphMail::deltaUrl($url, $this->folder->mailbox_id, 'OpaqueABC');
        $this->addToAssertionCount(1);
        $this->expectException(GraphFailure::class);
        GraphMail::deltaUrl($url, $this->folder->mailbox_id, 'Opaqueabc');
    }

    public function test_real_and_fixture_views_preserve_sources_and_filter_without_automatic_merge(): void
    {
        $real = $this->ingest('real-mail');
        $fake = MailMessage::factory()->create(['direction' => 'incoming', 'is_demo' => true, 'subject' => 'SYNTHETIC uniquely labelled']);
        $this->get(route('mail.index'))->assertOk()->assertDontSee($fake->subject);
        $this->get(route('mail.index', ['data' => 'real']))->assertOk()->assertSee($real->subject)->assertDontSee($fake->subject);
        $this->get(route('mail.index', ['data' => 'fixtures']))->assertOk()->assertSee($fake->subject)->assertDontSee($real->subject);
        $this->get(route('inquiries.index', ['data' => 'real']))->assertOk()->assertSee($real->inquiry->reference);
        $fixtureCase = Inquiry::factory()->create(['is_demo' => true, 'status' => 'needs_review', 'response_due_at' => now()->subDay()]);
        Inquiry::factory()->create(['is_demo' => true, 'status' => 'needs_client_information', 'response_due_at' => now()->subDay()]);
        Audit::record('Synthetic fixture activity sentinel', $fixtureCase, systemActor: 'System / mailbox');
        $this->get(route('overview'))->assertOk()->assertViewHas('review', 1)->assertViewHas('waiting', 0)->assertViewHas('overdue', 0)->assertViewHas('queue', fn ($cases) => $cases->pluck('id')->all() === [$real->inquiry_id])->assertSee($real->inquiry->reference)->assertDontSee('Synthetic fixture activity sentinel');
    }

    public function test_staff_can_find_older_cases_and_cannot_associate_fixture_evidence_with_real_data(): void
    {
        $old = Inquiry::factory()->create(['title' => 'Older synthetic search target']);
        Inquiry::factory()->count(151)->create();
        $m = $this->ingest('reply-for-case-search', ['subject' => 'Re: Unknown thread']);
        $this->get(route('mail.message', [$m, 'case_q' => $old->reference]))->assertOk()->assertSee($old->title);
        $fixture = MailMessage::factory()->create(['direction' => 'incoming', 'is_demo' => true]);
        $this->post(route('mail.review', $fixture), ['lock_version' => 0, 'decision' => 'associate', 'classification' => 'quote', 'inquiry_id' => $old->id, 'reason' => 'Synthetic cross-data test'])->assertSessionHasErrors('inquiry_id');
        $this->assertNull($fixture->fresh()->inquiry_id);
        $this->assertSame(0, $fixture->fresh()->lock_version);
    }

    public function test_legacy_test_address_labels_preserve_history_and_do_not_flag_real_synthetic_cargo(): void
    {
        $contact = ClientContact::factory()->create(['email' => 'legacy-qa@local.test']);
        $legacy = Inquiry::factory()->create(['client_id' => $contact->client_id, 'client_contact_id' => $contact->id]);
        $web = Inquiry::factory()->create(['public_contact' => ['email' => 'acceptance@example.test']]);
        $real = Inquiry::factory()->create(['title' => 'Synthetic resin cargo', 'public_contact' => ['email' => 'customer@company.com']]);
        $snapshot = $legacy->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_04_154208_label_existing_synthetic_inquiries.php');
        $migration->up();
        $this->assertTrue($legacy->fresh()->is_demo);
        $this->assertTrue($web->fresh()->is_demo);
        $this->assertFalse($real->fresh()->is_demo);
        unset($snapshot['is_demo']);
        $after = $legacy->fresh()->getAttributes();
        unset($after['is_demo']);
        $this->assertSame($snapshot, $after);
        $migration->up();
        $this->assertSame(3, Inquiry::count());
    }

    public function test_stored_thread_cannot_auto_associate_a_different_data_provenance(): void
    {
        $dispatch = $this->outbound();
        $dispatch->envelope->inquiry->update(['is_demo' => true]);
        $reply = $this->ingest('different-provenance', ['subject' => 'Re: '.$dispatch->envelope->approval->revision->rfq->reference, 'from' => ['emailAddress' => ['address' => 'vendor@example.test']], 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => $dispatch->internet_id]]]);
        $this->assertSame('unmatched', $reply->match_state);
        $this->assertNull($reply->inquiry_id);
        $this->assertSame(1, Inquiry::count());
    }

    public function test_message_source_cannot_be_mutated_or_deleted_in_postgresql(): void
    {
        $m = $this->ingest('immutable');
        $this->expectException(QueryException::class);
        DB::table('mail_messages')->where('id', $m->id)->update(['source' => 'changed']);
    }
}
