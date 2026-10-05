<?php

namespace Tests\Feature;

use App\Actions\MailboxSync;
use App\Actions\MailIngest;
use App\Actions\MailOutbox;
use App\Actions\ManageFollowups;
use App\Actions\ManageQuotation;
use App\Jobs\DispatchMail;
use App\Jobs\ImportMailAttachments;
use App\Jobs\ReconcileMail;
use App\Models\ClientQuotationApproval;
use App\Models\CompanySetting;
use App\Models\InquiryDocument;
use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use App\Models\MailDispatch;
use App\Models\MailMessage;
use App\Models\RfqApproval;
use App\Models\User;
use App\Support\GmailFailure;
use App\Support\GmailFixture;
use App\Support\GmailMail;
use App\Support\GmailMime;
use App\Support\GmailOAuth;
use App\Support\Mailboxes;
use App\Support\MailRelease;
use App\Support\OutboundControl;
use App\Support\Processing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class GmailIntegrationTest extends TestCase
{
    use QuotationFixture, RefreshDatabase;

    private MailboxConnection $gmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05T01:00:00Z'));
        $this->quotationFixture();
        $this->gmail = MailboxConnection::factory()->gmail()->create(['is_demo' => true]);
        $this->gmail->update(['identity_hash' => app(Mailboxes::class)->identity($this->gmail)]);
        CompanySetting::current()->forceFill(['outbound_mailbox_id' => $this->gmail->id])->save();
        DB::statement("SELECT setval(pg_get_serial_sequence('mailbox_connections','id'), (SELECT MAX(id) FROM mailbox_connections), true)");
    }

    private function quotation(): ClientQuotationApproval
    {
        $r = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput());

        return app(ManageQuotation::class)->approve($r, $this->staff, Processing::hash(app(ManageQuotation::class)->reviewSnapshot($r)));
    }

    private function dispatchQuote(): MailDispatch
    {
        $a = $this->quotation();
        $e = $a->envelopes()->firstOrFail();

        return app(MailOutbox::class)->enqueue($e, $this->staff, (string) Str::uuid(), $e->digest);
    }

    public function test_emergency_pause_after_gmail_draft_verification_blocks_the_actual_send_boundary(): void
    {
        $dispatch = $this->dispatchQuote();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->app->instance(GmailMail::class, new class($admin) extends GmailMail
        {
            public bool $paused = false;

            public function __construct(public User $admin) {}

            public function call(MailboxConnection $connection, string $method, string $path, array $data = [], ?string $token = null): array
            {
                $result = parent::call($connection, $method, $path, $data, $token);
                if (! $this->paused && $method === 'GET' && str_starts_with($path, '/drafts/')) {
                    $this->paused = true;
                    app(OutboundControl::class)->change($this->admin, true, 0, 'Controlled pause after Gmail draft verification.');
                }

                return $result;
            }
        });
        (new DispatchMail($dispatch->id))->handle();
        $this->assertSame('failed', $dispatch->fresh()->status);
        $this->assertNotNull($dispatch->fresh()->provider_draft_id);
        $this->assertNull($dispatch->fresh()->submission_started_at);
        $state = app(GmailFixture::class)->state($this->gmail);
        $this->assertCount(1, $state['drafts']);
        $this->assertSame([], $state['messages']);
        Http::assertNothingSent();
    }

    public function test_client_pdf_mime_and_distinct_draft_and_sent_ids_use_the_shared_outbox(): void
    {
        $d = $this->dispatchQuote();
        (new DispatchMail($d->id))->handle();
        $d = $d->fresh();
        $this->assertSame('accepted', $d->status, $d->last_error ?? '');
        $this->assertNotSame($d->provider_draft_id, $d->provider_draft_message_id);
        $this->assertNotSame($d->provider_sent_id, $d->provider_draft_message_id);
        $this->assertNotEmpty($d->provider_thread_id);
        $this->assertSame([], app(GmailFixture::class)->state($this->gmail)['drafts']);
        (new ReconcileMail($d->id))->handle();
        $this->assertSame('observed', $d->fresh()->status, $d->fresh()->last_error ?? '');
        $m = app(GmailFixture::class)->state($this->gmail)['messages'][$d->provider_sent_id];
        $this->assertCount(1, GmailMime::parts($m['payload'])['files']);
        $this->assertStringNotContainsString('PRIVATE PROFIT NOTES', GmailMime::parts($m['payload'])['text']);
        $this->get(route('mail.dispatch', $d))->assertOk()->assertSee('Gmail dispatch')->assertSee('Pinned connection');
    }

    public function test_rfq_uses_exact_approved_unicode_body_and_private_files(): void
    {
        $approval = RfqApproval::whereHas('revision.rfq', fn ($q) => $q->where('inquiry_id', $this->case->id))->firstOrFail();
        $release = app(MailRelease::class);
        $s = $release->source('rfq', $approval->id, $this->staff);
        $preview = $release->preview($s, $this->gmail);
        $e = app(MailOutbox::class)->authorize('rfq', $approval->id, $this->staff, Processing::hash($preview));
        $d = app(MailOutbox::class)->enqueue($e, $this->staff, (string) Str::uuid(), $e->digest);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('accepted', $d->fresh()->status, $d->fresh()->last_error ?? '');
        $sent = app(GmailFixture::class)->state($this->gmail)['messages'][$d->fresh()->provider_sent_id];
        $this->assertSame($preview['content']['subject'], GmailMime::headers($sent)['subject']);
        $this->assertSame(str_replace("\r\n", "\n", $preview['content']['body']), str_replace("\r\n", "\n", GmailMime::parts($sent['payload'])['text']));
    }

    public function test_default_switch_cannot_reroute_an_approved_gmail_dispatch(): void
    {
        $d = $this->dispatchQuote();
        CompanySetting::current()->forceFill(['outbound_mailbox_id' => $this->connection->id])->save();
        $this->assertSame($this->connection->id, MailboxConnection::current()->id);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('accepted', $d->fresh()->status, $d->fresh()->last_error ?? '');
        $this->assertSame($this->gmail->id, $d->envelope->mailbox_connection_id);
        $this->assertCount(1, app(GmailFixture::class)->state($this->gmail)['messages']);
        $this->assertSame('gmail', app(MailRelease::class)->connection(app(MailRelease::class)->source('client_quote', $d->envelope->client_quotation_approval_id, $this->staff))->provider);
    }

    public function test_external_draft_edits_block_and_missing_draft_is_never_sent_again(): void
    {
        $d = $this->dispatchQuote();
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['fault'] = ['path' => '/drafts/send', 'status' => 429, 'once' => true];
        app(GmailFixture::class)->state($this->gmail, $state);
        (new DispatchMail($d->id))->handle();
        $d = $d->fresh();
        $this->assertSame('ready', $d->status);
        $state = app(GmailFixture::class)->state($this->gmail);
        $draft = &$state['drafts'][$d->provider_draft_id];
        $draft['message']['id'] = 'externally-replaced-message';
        foreach ($draft['message']['payload']['headers'] as &$h) {
            if ($h['name'] === 'Subject') {
                $h['value'] = 'Unapproved provider edit';
            }
        }
        app(GmailFixture::class)->state($this->gmail, $state);
        $this->travel(31)->seconds();
        (new DispatchMail($d->id))->handle();
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertStringContainsString('differs', $d->fresh()->last_error);
        $this->assertCount(0, app(GmailFixture::class)->state($this->gmail)['messages']);
        $d->fresh()->update(['status' => 'uncertain']);
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['drafts'] = [];
        app(GmailFixture::class)->state($this->gmail, $state);
        (new ReconcileMail($d->id))->handle();
        $this->assertSame('uncertain', $d->fresh()->status);
        $this->assertStringContainsString('does not authorize', $d->fresh()->last_error);
        (new DispatchMail($d->id))->handle();
        $this->assertCount(0, app(GmailFixture::class)->state($this->gmail)['messages']);
    }

    public function test_ambiguous_send_reconciles_exact_sent_evidence_without_another_draft(): void
    {
        $d = $this->dispatchQuote();
        (new DispatchMail($d->id))->handle();
        $d = $d->fresh();
        $sentId = $d->provider_sent_id;
        $d->update(['status' => 'uncertain', 'provider_sent_id' => null]);
        (new ReconcileMail($d->id))->handle();
        $this->assertSame('observed', $d->fresh()->status, $d->fresh()->last_error ?? '');
        $this->assertSame($sentId, $d->fresh()->provider_sent_id);
        $this->assertCount(1, app(GmailFixture::class)->state($this->gmail)['messages']);
    }

    public function test_unverified_from_alias_is_blocked(): void
    {
        $this->gmail->update(['from_alias' => 'pending@meridian-logistics.example', 'identity_hash' => 'changed-alias']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('verified send-as');
        $this->dispatchQuote();
    }

    public function test_admin_only_controls_and_default_change_send_nothing(): void
    {
        $this->get(route('settings.mailbox', ['connection' => $this->gmail->id]))->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->get(route('settings.mailbox', ['connection' => $this->gmail->id]))->assertOk()->assertSee('Connected mail')->assertSee('Authorized Google identity');
        $this->post(route('settings.mailbox.action'), ['connection' => $this->connection->id, 'action' => 'default', 'confirm' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->connection->id, MailboxConnection::current()->id);
        $this->assertSame(0, MailDispatch::count());
        $this->post(route('settings.mailbox.store'), ['provider' => 'gmail', 'email' => 'other@meridian-logistics.example', 'name' => 'Another company inbox'])->assertRedirect();
        $this->assertSame(3, MailboxConnection::count());
    }

    private function oauthStart(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        config(['mailbox.google_client_id' => 'web-client', 'mailbox.google_client_secret' => 'private-web-secret']);
        $this->gmail->update(['is_demo' => false, 'state' => 'disconnected', 'access_token' => null]);
        $response = $this->post(route('settings.gmail.connect', $this->gmail))->assertRedirect();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringNotContainsString('mail.google.com', $query['scope']);

        return $query;
    }

    private function oauthResponses(string $email, ?string $refresh = 'replacement-refresh'): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600, 'scope' => implode(' ', GmailOAuth::SCOPES)] + ($refresh ? ['refresh_token' => $refresh] : [])),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => $this->gmail->google_subject, 'email' => $email, 'email_verified' => true]),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => $email, 'historyId' => '99999999999999999999999999']),
            'https://gmail.googleapis.com/gmail/v1/users/me/settings/sendAs' => Http::response(['sendAs' => [['sendAsEmail' => $email, 'verificationStatus' => 'accepted', 'isPrimary' => true]]]),
        ]);
    }

    public function test_oauth_state_account_validation_replay_and_refresh_token_preservation(): void
    {
        $q = $this->oauthStart();
        $this->oauthResponses($this->gmail->account_email, null);
        $this->get(route('settings.gmail.callback', ['state' => $q['state'], 'code' => 'test-code']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('test-refresh', $this->gmail->fresh()->refresh_token);
        $this->assertSame('connected', $this->gmail->fresh()->state);
        $this->assertStringNotContainsString('new-access', DB::table('mailbox_connections')->where('id', $this->gmail->id)->value('access_token'));
        Http::assertSent(function ($r) use ($q): bool {
            return $r->url() === 'https://oauth2.googleapis.com/token' && GmailMime::encode(hash('sha256', $r['code_verifier'], true)) === $q['code_challenge'];
        });
        $this->get(route('settings.gmail.callback', ['state' => $q['state'], 'code' => 'replay']))->assertRedirect()->assertSessionHasErrors('processing');
    }

    public function test_wrong_google_account_does_not_connect(): void
    {
        $q = $this->oauthStart();
        $this->oauthResponses('unexpected@unrelated.example');
        $this->get(route('settings.gmail.callback', ['state' => $q['state'], 'code' => 'test-code']))->assertRedirect()->assertSessionHasErrors('processing');
        $this->assertSame('disconnected', $this->gmail->fresh()->state);
        $this->assertNull($this->gmail->fresh()->access_token);
    }

    public function test_google_refresh_without_new_refresh_token_and_revoked_consent_pause_only_its_connection(): void
    {
        $this->gmail->update(['is_demo' => false, 'expires_at' => now()->subMinute()]);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::sequence()->push(['access_token' => 'refreshed-access', 'expires_in' => 3600])->push(['error' => 'invalid_grant'], 400)]);
        $token = app(Mailboxes::class)->token($this->gmail->fresh());
        $this->assertSame('refreshed-access', $token);
        $this->assertSame('test-refresh', $this->gmail->fresh()->refresh_token);
        $this->gmail = $this->gmail->fresh();
        $this->gmail->update(['expires_at' => now()->subMinute()]);

        try {
            app(Mailboxes::class)->token($this->gmail->fresh());
            $this->fail('Expected revoked consent');
        } catch (GmailFailure $e) {
            $this->assertSame(401, $e->status);
        }
        $this->assertSame('paused', $this->gmail->fresh()->state);
        $this->assertSame('connected', $this->connection->fresh()->state);
    }

    public function test_quota_403_is_backoff_and_permission_403_is_reconnect(): void
    {
        $this->gmail->update(['is_demo' => false]);
        Http::fake(['https://gmail.googleapis.com/*' => Http::sequence()->push(['error' => ['errors' => [['reason' => 'userRateLimitExceeded']]]], 403, ['Retry-After' => '90'])->push(['error' => ['errors' => [['reason' => 'domainPolicy']]]], 403)]);
        try {
            app(GmailMail::class)->call($this->gmail, 'GET', '/profile');
            $this->fail('Expected quota');
        } catch (GmailFailure $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(90, $e->retryAfter);
        }

        try {
            app(GmailMail::class)->call($this->gmail, 'GET', '/profile');
            $this->fail('Expected denied access');
        } catch (GmailFailure $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function test_gmail_threaded_reminder_stays_pinned_and_human_reviewed_reply_stops_later_stages(): void
    {
        $d = $this->dispatchQuote();
        (new DispatchMail($d->id))->handle();
        (new ReconcileMail($d->id))->handle();
        $d = $d->fresh();
        $this->connection->update(['incoming_enabled' => false]);
        $folder = MailboxFolder::factory()->create(['mailbox_connection_id' => $this->gmail->id, 'mailbox_id' => $this->gmail->target_id, 'identity_hash' => $this->gmail->identity_hash, 'provider_id' => 'INBOX', 'kind' => 'incoming', 'last_sync_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        $action = app(ManageFollowups::class);
        $policy = $action->policy($admin, array_replace(ManageFollowups::defaults('client_quote'), ['kind' => 'client_quote', 'expected_number' => 0, 'enabled' => true, 'subject' => 'Re: [original_subject]', 'reason' => 'Explicit Gmail-thread fixture policy']));
        CompanySetting::current()->forceFill(['outbound_mailbox_id' => $this->connection->id])->save();
        $approval = $d->envelope->clientQuotationApproval;
        $preview = $action->preview('client_quote', $approval->id, $this->staff, 'automatic', false);
        $this->assertSame($d->provider_thread_id, $preview['content']['thread_provider_id']);
        $this->assertSame($d->internet_id, $preview['content']['in_reply_to']);
        $plan = $action->activate('client_quote', $approval->id, $this->staff, ['mode' => 'automatic', 'attach' => false, 'digest' => Processing::hash($preview), 'reason' => 'Human-approved exact Gmail reminders']);
        $this->travelTo($plan->next_due_at);
        $folder->update(['last_sync_at' => now()]);
        $action->tick($plan);
        $stage = $plan->stages()->firstOrFail();
        $reminder = $stage->envelope->dispatches()->firstOrFail();
        $this->assertSame($this->gmail->id, $stage->envelope->mailbox_connection_id);
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['fault'] = ['path' => '/drafts/send', 'status' => 429, 'retry' => 90, 'once' => true];
        app(GmailFixture::class)->state($this->gmail, $state);
        (new DispatchMail($reminder->id))->handle();
        $this->assertSame('ready', $reminder->fresh()->status);
        $this->assertSame(1, $plan->fresh()->send_count);
        $this->travelTo($reminder->fresh()->next_attempt_at);
        $folder->update(['last_sync_at' => now()]);
        (new DispatchMail($reminder->id))->handle();
        $this->assertSame('accepted', $reminder->fresh()->status, $reminder->fresh()->last_error ?? '');
        $this->assertSame($d->provider_thread_id, $reminder->fresh()->provider_thread_id);
        $this->assertSame(1, $plan->fresh()->send_count);
        $this->actingAs($admin);
        app(GmailFixture::class)->incoming($this->gmail, $plan->id);
        $folder->update(['next_attempt_at' => null]);
        app(MailboxSync::class)->handle($folder->id);
        $message = MailMessage::where('mailbox_connection_id', $this->gmail->id)->where('direction', 'incoming')->firstOrFail();
        $this->assertSame($approval->client_quotation_revision_id, $message->client_quotation_revision_id);
        (new ImportMailAttachments($message->id, $folder->id))->handle();
        $this->actingAs($this->staff);
        app(MailIngest::class)->review($message->fresh(), $this->staff, ['decision' => 'associate', 'classification' => 'question', 'inquiry_id' => $this->case->id, 'rfq_revision_id' => null, 'client_quotation_revision_id' => $approval->client_quotation_revision_id, 'lock_version' => $message->fresh()->lock_version, 'reason' => 'Human reviewed the exact Gmail question and quotation revision']);
        $this->assertSame('stopped', $plan->fresh()->state);
        $this->assertNull($plan->fresh()->next_due_at);
        $this->artisan('lrs:followup-tick', ['--plan' => $plan->id])->assertSuccessful();
        $this->assertSame('accepted', $stage->fresh()->state);
        $this->assertSame(2, MailDispatch::count());
        $this->assertSame(1, $plan->fresh()->send_count);
    }

    public function test_mime_preserves_unicode_names_and_filenames_and_rejects_header_injection_or_changed_bytes(): void
    {
        $d = $this->dispatchQuote();
        $snapshot = $d->envelope->snapshot;
        $bytes = "item,quantity\nPrecision parts,3\n";
        Storage::disk('inquiry_documents')->put('fixture/unicode.csv', $bytes);
        $doc = InquiryDocument::factory()->create(['inquiry_id' => $this->case->id, 'storage_path' => 'fixture/unicode.csv', 'original_name' => 'Pièces cargo.csv', 'mime' => 'text/csv', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes)]);
        $snapshot['content']['subject'] = 'Quotation · pièces mécaniques';
        $snapshot['envelope']['from']['name'] = 'Méridian Logistics';
        $snapshot['content']['manifest'] = [['document_id' => $doc->id, 'name' => 'Pièces cargo.csv', 'mime' => 'text/csv', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes)]];
        $raw = app(GmailMime::class)->build($d, $snapshot);
        $payload = GmailFixture::payload($raw);
        $files = GmailMime::parts($payload)['files'];
        $this->assertSame('Pièces cargo.csv', $files[0]['filename']);
        $this->assertSame($bytes, GmailMime::decode($files[0]['body']['data']));
        app(GmailMime::class)->verify($this->gmail, ['id' => 'unicode-draft', 'payload' => $payload, 'labelIds' => ['DRAFT']], $snapshot, $d);
        $altered = $payload;
        foreach ($altered['headers'] as &$header) {
            if (strtolower($header['name']) === 'from') {
                $header['value'] = 'Unapproved Company <'.$snapshot['envelope']['from']['email'].'>';
            }
        }
        unset($header);
        try {
            app(GmailMime::class)->verify($this->gmail, ['id' => 'changed-name', 'payload' => $altered, 'labelIds' => ['DRAFT']], $snapshot, $d);
            $this->fail('Expected changed From display name to block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('differs', json_encode($e->errors()));
        }
        $snapshot['content']['subject'] .= "\r\nBcc: hidden@unrelated.example";
        try {
            app(GmailMime::class)->build($d, $snapshot);
            $this->fail('Expected header-injection block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('unsafe', json_encode($e->errors()));
        }
        $snapshot['content']['subject'] = 'Safe subject';
        Storage::disk('inquiry_documents')->put('fixture/unicode.csv', 'changed content');
        try {
            app(GmailMime::class)->build($d, $snapshot);
            $this->fail('Expected private-byte block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('changed', json_encode($e->errors()));
        }
    }

    public function test_missing_permissions_and_expired_state_cannot_commit_tokens(): void
    {
        $q = $this->oauthStart();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'partial-grant', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'scope' => 'openid email https://www.googleapis.com/auth/gmail.readonly'])]);
        $this->get(route('settings.gmail.callback', ['state' => $q['state'], 'code' => 'partial']))->assertRedirect()->assertSessionHasErrors('connection');
        $this->assertSame('disconnected', $this->gmail->fresh()->state);
        $this->assertNull($this->gmail->fresh()->access_token);
        $q = $this->oauthStart();
        DB::table('mail_oauth_attempts')->where('state_hash', hash('sha256', $q['state']))->update(['expires_at' => now()->subMinute()]);
        $before = Http::recorded()->count();
        $this->get(route('settings.gmail.callback', ['state' => $q['state'], 'code' => 'expired']))->assertRedirect()->assertSessionHasErrors('processing');
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_uncertain_send_transport_failure_never_creates_another_draft_or_changes_reminder_counts(): void
    {
        $d = $this->dispatchQuote();
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['fault'] = ['path' => '/drafts/send', 'status' => 0, 'ambiguous' => true, 'once' => true];
        app(GmailFixture::class)->state($this->gmail, $state);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('uncertain', $d->fresh()->status);
        $this->assertCount(1, app(GmailFixture::class)->state($this->gmail)['drafts']);
        (new DispatchMail($d->id))->handle();
        (new ReconcileMail($d->id))->handle();
        $this->assertSame('uncertain', $d->fresh()->status);
        $this->assertCount(1, app(GmailFixture::class)->state($this->gmail)['drafts']);
        $this->assertCount(0, app(GmailFixture::class)->state($this->gmail)['messages']);
    }

    public function test_verified_alias_revoked_after_approval_is_blocked_before_draft_creation(): void
    {
        $d = $this->dispatchQuote();
        $state = app(GmailFixture::class)->state($this->gmail);
        $state['aliases'] = [['sendAsEmail' => $this->gmail->account_email, 'verificationStatus' => 'pending']];
        app(GmailFixture::class)->state($this->gmail, $state);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertNull($d->fresh()->provider_draft_id);
        $this->assertCount(0, app(GmailFixture::class)->state($this->gmail)['drafts']);
    }

    public function test_interrupted_creation_recovers_existing_exact_draft_only_after_staff_retry(): void
    {
        $d = $this->dispatchQuote();
        $d->update(['status' => 'uncertain', 'draft_started_at' => now(), 'internet_id' => '<'.$d->dispatch_key.'@lrs.invalid>']);
        $draft = app(GmailMail::class)->call($this->gmail, 'POST', '/drafts', ['message' => ['raw' => GmailMime::encode(app(GmailMime::class)->build($d, $d->envelope->snapshot))]]);
        (new ReconcileMail($d->id))->handle();
        $d = $d->fresh();
        $this->assertSame('failed', $d->status, $d->last_error ?? '');
        $this->assertSame($draft['id'], $d->provider_draft_id);
        $this->assertNull($d->submission_started_at);
        $this->assertCount(0, app(GmailFixture::class)->state($this->gmail)['messages']);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('failed', $d->fresh()->status);
        app(MailOutbox::class)->recover($d->fresh(), $this->staff, 'Staff explicitly reviewed the recovered exact unsent draft');
        (new DispatchMail($d->id))->handle();
        $this->assertSame('accepted', $d->fresh()->status, $d->fresh()->last_error ?? '');
        $this->assertSame($draft['id'], $d->fresh()->provider_draft_id);
        $this->assertCount(1, app(GmailFixture::class)->state($this->gmail)['messages']);
        $this->assertCount(0, app(GmailFixture::class)->state($this->gmail)['drafts']);
    }
}
