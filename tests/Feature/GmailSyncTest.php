<?php

namespace Tests\Feature;

use App\Actions\MailboxSync;
use App\Actions\MailIngest;
use App\Jobs\ImportMailAttachments;
use App\Models\Inquiry;
use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use App\Models\MailMessage;
use App\Models\User;
use App\Support\GmailFixture;
use App\Support\GmailMime;
use App\Support\Mailboxes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GmailSyncTest extends TestCase
{
    use RefreshDatabase;

    private MailboxConnection $gmail;

    private MailboxConnection $outlook;

    private MailboxFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('mailbox');
        Storage::fake('inquiry_documents');
        config(['mailbox.demo_enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->outlook = MailboxConnection::factory()->create(['is_demo' => true]);
        $this->gmail = MailboxConnection::factory()->gmail()->create(['is_demo' => true]);
        $this->gmail->update(['identity_hash' => app(Mailboxes::class)->identity($this->gmail)]);
        $this->folder = MailboxFolder::factory()->create(['mailbox_connection_id' => $this->gmail->id, 'identity_hash' => $this->gmail->identity_hash, 'mailbox_id' => $this->gmail->target_id, 'provider_id' => 'INBOX', 'name' => 'Gmail Inbox', 'kind' => 'incoming', 'gmail_phase' => 'initial']);
    }

    private function source(string $id, array $changes = []): array
    {
        $text = "Please quote three pallets of machinery parts from Port Klang to Singapore.\nCargo ready next week.";

        return array_replace_recursive(['id' => $id, 'threadId' => 'thread-'.$id, 'internalDate' => (string) now()->getTimestampMs(), 'labelIds' => ['INBOX'], 'payload' => ['partId' => '0', 'mimeType' => 'text/plain', 'headers' => [['name' => 'From', 'value' => 'Nadia Rahman <nadia@straits-components.example>'], ['name' => 'To', 'value' => $this->gmail->account_email], ['name' => 'Subject', 'value' => 'LCL machinery parts request '.$id], ['name' => 'Message-ID', 'value' => '<'.$id.'@straits-components.example>']], 'body' => ['size' => strlen($text), 'data' => GmailMime::encode($text)]]], $changes);
    }

    private function push(array $message, string $type = 'messagesAdded'): void
    {
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['messages'][$message['id']] = $message;
        $state['sequence']++;
        $state['history'][] = ['id' => 'fixture-history-'.$state['sequence'], '_sequence' => $state['sequence'], $type => [['message' => ['id' => $message['id'], 'labelIds' => $message['labelIds']], 'labelIds' => ['INBOX']]]];
        app(GmailFixture::class)->state($this->gmail, $state);
    }

    private function sync(): void
    {
        $this->folder->update(['next_attempt_at' => null]);
        app(MailboxSync::class)->handle($this->folder->id);
        $this->folder = $this->folder->fresh();
    }

    public function test_initial_import_captures_arrivals_before_history_commit_and_preserves_private_source(): void
    {
        $this->push($this->source('first'));
        $this->sync();
        $this->assertSame('catchup', $this->folder->gmail_phase);
        $this->assertNull($this->folder->gmail_history_id);
        $this->assertSame('fixture-history-1', $this->folder->gmail_initial_anchor);
        $this->push($this->source('arrived-during-import'));
        $this->sync();
        $this->assertSame('fixture-history-2', $this->folder->gmail_history_id);
        $this->assertSame(2, MailMessage::count());
        $this->assertSame(2, Inquiry::count());
        $m = MailMessage::firstOrFail();
        $original = app(GmailMime::class)->original($m->source);
        $this->assertSame('first', $original['id']);
        $this->assertStringNotContainsString('machinery parts', Storage::disk('mailbox')->get($m->source['gmail_original']['path']));
        $this->assertSame($this->gmail->id, $m->mailbox_connection_id);
    }

    public function test_history_pagination_and_replay_do_not_advance_early_or_duplicate_cases(): void
    {
        $this->sync();
        for ($n = 1; $n <= 30; $n++) {
            $this->push($this->source('page-'.$n));
        }
        $this->sync();
        $this->assertSame(25, MailMessage::count());
        $this->assertNull($this->folder->gmail_history_id);
        $this->assertSame('25', $this->folder->gmail_page_token);
        $this->sync();
        $this->assertSame(30, MailMessage::count());
        $this->assertSame('fixture-history-30', $this->folder->gmail_history_id);
        $this->folder->update(['gmail_history_id' => 'fixture-history-0']);
        $this->sync();
        $this->sync();
        $this->assertSame(30, MailMessage::count());
        $this->assertSame(30, Inquiry::count());
    }

    public function test_opaque_huge_history_id_is_a_string_and_expiration_requires_admin_resync(): void
    {
        $this->sync();
        $this->sync();
        $this->folder->update(['gmail_history_id' => '9999999999999999999999999999']);
        $this->sync();
        $this->assertFalse($this->folder->enabled);
        $this->assertSame('9999999999999999999999999999', $this->folder->gmail_history_id);
        $this->assertStringContainsString('Admin must choose', $this->folder->last_error);
        $this->post(route('settings.mailbox.folder-action', $this->folder), ['action' => 'resync', 'import_from' => now()->subDay()->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d\\TH:i'), 'reason' => 'Controlled recovery after expired Gmail history', 'confirm' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->folder->fresh()->gmail_history_id);
        $this->assertSame('initial', $this->folder->fresh()->gmail_phase);
    }

    public function test_sent_drafts_spam_trash_and_label_only_changes_create_no_customer_cases(): void
    {
        $this->sync();
        $this->sync();
        foreach (['SENT', 'DRAFT', 'SPAM', 'TRASH'] as $label) {
            $m = $this->source('excluded-'.$label);
            $m['labelIds'] = ['INBOX', $label];
            $this->push($m);
        }
        $this->push($this->source('label-only'), 'labelsAdded');
        $this->sync();
        $this->assertSame(0, MailMessage::count());
        $this->assertSame(0, Inquiry::count());
        $this->push($this->source('kept'));
        $this->sync();
        $m = MailMessage::firstOrFail();
        $this->push($this->source('kept'), 'labelsRemoved');
        $this->sync();
        $this->assertSame(1, Inquiry::count());
        $this->assertNull($m->fresh()->deleted_at_provider);
        $this->assertNotNull(DB::table('mail_folder_message')->where('mail_message_id', $m->id)->value('removed_at'));
        $this->push($this->source('kept'), 'messagesDeleted');
        $this->sync();
        $this->assertNotNull($m->fresh()->deleted_at_provider);
        $this->assertSame(1, Inquiry::count());
    }

    public function test_partial_page_is_retained_and_resumed_without_skipping_the_failed_message(): void
    {
        $this->push($this->source('one'));
        $this->push($this->source('two'));
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['fault'] = ['path' => '/messages/two', 'status' => 503, 'once' => true];
        app(GmailFixture::class)->state($this->gmail, $state);
        $this->sync();
        $this->assertSame(1, $this->folder->offset);
        $this->assertNotNull($this->folder->page);
        $this->assertNull($this->folder->gmail_history_id);
        $this->assertSame(1, MailMessage::count());
        $this->sync();
        $this->sync();
        $this->assertSame(2, MailMessage::count());
        $this->assertSame(2, Inquiry::count());
        $this->assertNull($this->folder->page);
    }

    public function test_partial_attachment_import_is_visible_private_and_retry_does_not_duplicate_documents(): void
    {
        $m = $this->source('with-file');
        $bytes = "item,quantity\nMachinery parts,3\n";
        $body = $m['payload'];
        $m['payload'] = ['mimeType' => 'multipart/mixed', 'headers' => $body['headers'], 'parts' => [$body, ['partId' => 'file-1', 'mimeType' => 'text/csv', 'filename' => 'Cargo list.csv', 'body' => ['size' => strlen($bytes), 'attachmentId' => 'attachment-1']]]];
        $this->push($m);
        $this->sync();
        $message = MailMessage::firstOrFail();
        (new ImportMailAttachments($message->id, $this->folder->id))->handle();
        $this->assertSame('partial', $message->fresh()->attachment_state);
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['attachments']['attachment-1'] = ['data' => GmailMime::encode($bytes), 'size' => strlen($bytes)];
        app(GmailFixture::class)->state($this->gmail, $state);
        (new ImportMailAttachments($message->id, $this->folder->id))->handle();
        $this->assertSame('complete', $message->fresh()->attachment_state, $message->fresh()->attachment_error ?? '');
        $this->assertSame(1, $message->inquiry->documents()->count());
        (new ImportMailAttachments($message->id, $this->folder->id))->handle();
        $this->assertSame(1, $message->inquiry->documents()->count());
        $this->assertSame(hash('sha256', $bytes), $message->attachments()->firstOrFail()->checksum);
    }

    public function test_identical_cross_provider_copy_retains_provenance_and_different_content_needs_review(): void
    {
        $m = $this->source('copy');
        $normalized = app(GmailMime::class)->incoming($this->gmail, $m);
        $outlookFolder = MailboxFolder::factory()->create(['identity_hash' => $this->outlook->identity_hash]);
        $graphSource = array_diff_key($normalized, array_flip(['gmail_original', 'gmail_labels', 'gmail_attachment_count']));
        $graphSource['id'] = 'graph-copy-id';
        $original = app(MailIngest::class)->handle($outlookFolder, $graphSource);
        $this->push($m);
        $this->sync();
        $copy = MailMessage::where('mailbox_connection_id', $this->gmail->id)->firstOrFail();
        $this->assertSame($original->id, $copy->duplicate_of_id);
        $this->assertSame($original->inquiry_id, $copy->inquiry_id);
        $this->assertSame(1, Inquiry::count());
        $this->assertNotSame($original->source_hash, $copy->source_hash);
        $this->get(route('mail.index', ['data' => 'all', 'state' => 'duplicate_copy']))->assertOk()->assertSee($copy->subject);
        $this->get(route('mail.message', $copy))->assertOk()->assertSee('Compare original source copy');
        $different = $m;
        $different['id'] = 'different-copy';
        $different['payload']['body']['data'] = GmailMime::encode('A materially different shipment with the same claimed RFC identifier.');
        $this->push($different);
        $this->sync();
        $this->sync();
        $review = MailMessage::where('provider_id', 'different-copy')->firstOrFail();
        $this->assertSame('unmatched', $review->match_state);
        $this->assertNull($review->inquiry_id);
        $this->assertSame(1, Inquiry::count());
        $this->assertStringContainsString('Possible copy', $review->match_reason);
    }

    public function test_external_text_body_failure_retains_page_then_imports_exact_body_and_original_source(): void
    {
        $original = $this->source('external-body');
        $bytes = GmailMime::decode($original['payload']['body']['data']);
        unset($original['payload']['body']['data']);
        $original['payload']['body']['attachmentId'] = 'body-attachment';
        $this->push($original);
        $this->sync();
        $this->assertSame(0, MailMessage::count());
        $this->assertSame(0, $this->folder->offset);
        $this->assertNotNull($this->folder->page);
        $this->assertNull($this->folder->gmail_history_id);
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['attachments']['body-attachment'] = ['data' => GmailMime::encode($bytes), 'size' => strlen($bytes)];
        app(GmailFixture::class)->state($this->gmail, $state);
        $this->sync();
        $message = MailMessage::firstOrFail();
        $this->assertSame($bytes, $message->source['body']['content']);
        $this->assertSame($original, app(GmailMime::class)->original($message->source));
        $this->assertFalse($message->source['hasAttachments']);
        $this->assertSame(1, Inquiry::count());
        $this->sync();
        $this->assertSame(1, MailMessage::count());
        $this->assertNotNull($this->folder->gmail_history_id);
    }

    public function test_external_text_body_cannot_exceed_the_shared_processing_bound(): void
    {
        $source = $this->source('oversized-body');
        $source['payload']['body'] = ['size' => 1048577, 'attachmentId' => 'oversized-body-attachment'];
        $this->push($source);
        $this->sync();
        $this->assertSame(0, MailMessage::count());
        $this->assertSame(0, Inquiry::count());
        $this->assertNotNull($this->folder->page);
        $this->assertSame(0, $this->folder->offset);
        $this->assertNull($this->folder->gmail_history_id);
        $this->assertNotNull($this->folder->last_error);
    }

    public function test_long_opaque_mailbox_identity_and_imported_content_provenance_are_preserved(): void
    {
        $subject = str_repeat('opaque', 42);
        $this->gmail->update(['google_subject' => $subject, 'account_id' => $subject, 'target_id' => $subject]);
        $this->gmail->update(['identity_hash' => app(Mailboxes::class)->identity($this->gmail)]);
        $this->folder->update(['mailbox_id' => $subject, 'identity_hash' => $this->gmail->identity_hash]);
        $this->push($this->source('protected-source'));
        $this->sync();
        $message = MailMessage::firstOrFail();
        $this->assertSame($this->folder->mailboxKey(), $message->mailbox_key);
        foreach (['mailbox_connection_id' => $this->outlook->id, 'evidence_digest' => str_repeat('0', 64)] as $field => $value) {
            try {
                DB::transaction(fn () => DB::table('mail_messages')->where('id', $message->id)->update([$field => $value]));
                $this->fail('Expected immutable source evidence');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        $this->assertSame($this->gmail->id, $message->fresh()->mailbox_connection_id);
        $this->assertSame($message->evidence_digest, $message->fresh()->evidence_digest);
    }
}
