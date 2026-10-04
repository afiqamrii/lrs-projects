<?php

namespace Tests\Feature;

use App\Actions\MailboxSync;
use App\Models\MailboxConnection;
use App\Models\MailMessage;
use App\Models\User;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\InquiryWorkflow;
use App\Support\Mailboxes;
use App\Support\MailboxFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OutlookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('mailbox');
        config(['mailbox.tenant_id' => '11111111-1111-4111-8111-111111111111', 'mailbox.client_id' => '33333333-3333-4333-8333-333333333333', 'mailbox.client_secret' => 'test-server-secret', 'mailbox.demo_enabled' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    public function test_opt_in_fixture_delta_integration_imports_and_replays_original_sources_without_live_calls(): void
    {
        app(Mailboxes::class)->demo();
        app(MailboxFixture::class)->loadIncoming();
        $inbox = MailboxConnection::current()->folders()->where('kind', 'incoming')->firstOrFail();
        app(MailboxSync::class)->handle($inbox->id);
        $this->assertDatabaseCount('mail_messages', 4);
        $this->assertDatabaseCount('inquiries', 1);
        $this->assertNull($inbox->fresh()->last_error);
        $this->assertNotNull($inbox->fresh()->cursor);
        $this->assertDatabaseHas('inquiries', ['is_demo' => true, 'source_channel' => 'email', 'status' => 'needs_review']);
        $inbox->refresh()->update(['next_attempt_at' => null]);
        app(MailboxSync::class)->handle($inbox->id);
        $this->assertDatabaseCount('mail_messages', 4);
        $this->assertDatabaseCount('inquiries', 1);
        Http::assertNothingSent();
    }

    public function test_reauthorization_supersedes_inflight_refresh_without_overwriting_fresh_tokens(): void
    {
        $c = MailboxConnection::factory()->create(['expires_at' => now()->subMinute()]);
        Http::fake(function () use ($c) {
            $latest = $c->fresh();
            $latest->update(['access_token' => 'new-authorization-token', 'refresh_token' => 'new-authorization-refresh', 'refresh_lease' => null, 'refresh_until' => null, 'expires_at' => now()->addHour()]);

            return Http::response(['access_token' => 'stale-refresh-result', 'refresh_token' => 'stale-refresh-secret', 'expires_in' => 3600]);
        });
        try {
            app(Mailboxes::class)->token($c);
            $this->fail('Superseded refresh must defer');
        } catch (GraphFailure $e) {
            $this->assertSame(429, $e->status);
        }
        $this->assertSame('new-authorization-token', $c->fresh()->access_token);
        $this->assertSame('connected', $c->fresh()->state);
        $old = $c->fresh();
        $c->refresh()->update(['generation' => $old->generation + 1, 'identity_hash' => hash('sha256', 'new-pinned-mailbox')]);
        app(Mailboxes::class)->pause('Stale prior-mailbox failure', $old);
        $this->assertSame('connected', $c->fresh()->state);
    }

    public function test_signed_upload_session_uses_exact_bytes_ranges_and_never_a_bearer_token(): void
    {
        $c = MailboxConnection::factory()->create();
        $url = "https://outlook.office.com/api/v2.0/Users('pinned')/Messages('draft')/AttachmentSessions('signed')?authtoken=fixture-secret";
        Http::fake([$url => Http::response(['nextExpectedRanges' => ['3-']], 200)]);
        $result = app(GraphMail::class)->upload($c, $url, 'PUT', 'abc', 'bytes 0-2/6');
        $this->assertSame(200, $result['status']);
        Http::assertSent(fn ($r) => $r->body() === 'abc' && ! $r->hasHeader('Authorization') && $r->header('Content-Range') === ['bytes 0-2/6'] && $r->header('Content-Length') === ['3']);
        $this->expectException(GraphFailure::class);
        app(GraphMail::class)->upload($c, 'https://attacker.invalid/AttachmentSessions(x)', 'GET');
    }

    public function test_only_active_admin_manages_connection_and_tokens_remain_encrypted_hidden_and_absent_from_pages(): void
    {
        $c = MailboxConnection::factory()->create();
        $raw = DB::table('mailbox_connections')->first();
        $this->assertNotSame('test-access', $raw->access_token);
        $this->assertNotSame('test-refresh', $raw->refresh_token);
        $this->assertSame('test-access', $c->fresh()->access_token);
        $this->assertArrayNotHasKey('access_token', $c->toArray());
        $this->get(route('settings.mailbox'))->assertOk()->assertDontSee('test-access')->assertDontSee('test-server-secret')->assertSee('Pin the company identity');
        $this->actingAs(User::factory()->create(['role' => 'agent']))->get(route('settings.mailbox'))->assertForbidden();
        $this->post(route('settings.mailbox.action'), ['action' => 'disconnect', 'confirm' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_active' => false]))->get(route('mail.index'))->assertRedirect(route('login'));
    }

    private function begin(): array
    {
        MailboxConnection::factory()->create(['state' => 'disconnected', 'access_token' => null, 'refresh_token' => null]);
        $r = $this->post(route('settings.mailbox.connect'))->assertRedirect();
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());

        return $query;
    }

    private function fakeIdentity(string $id = '22222222-2222-4222-8222-222222222222'): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'new-secret-access', 'refresh_token' => 'new-secret-refresh', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/me*' => Http::response(['id' => $id, 'mail' => 'operations@example.test']),
            'https://graph.microsoft.com/v1.0/users/*' => fn ($r) => Http::response(['id' => basename(parse_url($r->url(), PHP_URL_PATH)), 'displayName' => 'Synthetic folder']),
        ]);
    }

    public function test_pkce_state_is_session_bound_one_use_and_connection_does_not_dispatch_backlog(): void
    {
        $q = $this->begin();
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertArrayNotHasKey('client_secret', $q);
        $a = DB::table('mail_oauth_attempts')->first();
        $this->assertNotSame($q['state'], $a->state_hash);
        $this->assertStringNotContainsString($q['code_challenge'], $a->verifier);
        $this->fakeIdentity();
        $this->get(route('settings.mailbox.callback', ['state' => $q['state'], 'code' => 'ephemeral-code']))->assertRedirect(route('settings.mailbox'))->assertSessionHasNoErrors();
        $this->assertSame('connected', MailboxConnection::current()->state);
        $this->assertDatabaseCount('mailbox_folders', 2);
        $this->assertDatabaseCount('mail_dispatches', 0);
        Queue::assertNothingPushed();
        $this->get(route('settings.mailbox.callback', ['state' => $q['state'], 'code' => 'ephemeral-code']))->assertSessionHasErrors('connection');
        $this->assertCount(7, Http::recorded());
    }

    public function test_bad_expired_foreign_session_and_mismatched_account_cannot_replace_connection(): void
    {
        $q = $this->begin();
        $this->get(route('settings.mailbox.callback', ['state' => 'bad', 'code' => 'code']))->assertSessionHasErrors('connection');
        Http::assertNothingSent();
        DB::table('mail_oauth_attempts')->update(['expires_at' => now()->subMinute()]);
        $this->get(route('settings.mailbox.callback', ['state' => $q['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        Http::assertNothingSent();
        DB::table('mail_oauth_attempts')->update(['expires_at' => now()->addMinute(), 'session_hash' => hash('sha256', 'other-session')]);
        $this->get(route('settings.mailbox.callback', ['state' => $q['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        Http::assertNothingSent();
        DB::table('mail_oauth_attempts')->update(['session_hash' => hash('sha256', $this->app['session']->getId())]);
        $this->fakeIdentity('44444444-4444-4444-8444-444444444444');
        $this->get(route('settings.mailbox.callback', ['state' => $q['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        $this->assertSame('disconnected', MailboxConnection::current()->state);
        $this->assertNull(MailboxConnection::current()->access_token);
    }

    public function test_refresh_is_serialized_rotates_encrypted_tokens_and_preserves_identity(): void
    {
        $c = MailboxConnection::factory()->create(['expires_at' => now()->subMinute(), 'refresh_lease' => '55555555-5555-4555-8555-555555555555', 'refresh_until' => now()->addMinute()]);
        try {
            app(Mailboxes::class)->token($c);
            $this->fail('Overlapping refresh should defer');
        } catch (GraphFailure $e) {
            $this->assertSame(429, $e->status);
        }
        Http::assertNothingSent();
        $c->update(['refresh_until' => now()->subMinute()]);
        $this->fakeIdentity();
        $hash = $c->identity_hash;
        $this->assertSame('new-secret-access', app(Mailboxes::class)->token($c));
        $this->assertSame($hash, $c->fresh()->identity_hash);
        $this->assertSame('new-secret-refresh', $c->fresh()->refresh_token);
        $this->assertSame('new-secret-access', app(Mailboxes::class)->token($c->fresh()));
        $this->assertCount(1, Http::recorded());
        $this->assertNotSame('new-secret-refresh', DB::table('mailbox_connections')->value('refresh_token'));
    }

    public function test_revoked_refresh_pauses_and_disconnect_invalidates_identity_without_deleting_sources(): void
    {
        $c = MailboxConnection::factory()->create(['expires_at' => now()->subMinute()]);
        $m = MailMessage::factory()->create();
        $before = $c->identity_hash;
        Http::fake(['https://login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'PRIVATE RAW ERROR'], 400)]);
        try {
            app(Mailboxes::class)->token($c);
            $this->fail('Revoked refresh must fail');
        } catch (GraphFailure $e) {
            $this->assertSame(401, $e->status);
        }
        $this->assertSame('paused', $c->fresh()->state);
        $this->assertStringNotContainsString('PRIVATE', $c->fresh()->last_error);
        $this->post(route('settings.mailbox.action'), ['action' => 'disconnect', 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertNull($c->fresh()->access_token);
        $this->assertNotSame($before, $c->fresh()->identity_hash);
        $this->assertDatabaseHas('mail_messages', ['id' => $m->id]);
    }

    public function test_admin_configuration_validates_personal_identity_rights_limit_and_bounded_import(): void
    {
        $c = MailboxConnection::factory()->create();
        $data = ['account_id' => $c->account_id, 'account_email' => $c->account_email, 'target_id' => $c->target_id, 'target_email' => $c->target_email, 'target_name' => 'Actual configured name', 'mailbox_type' => 'personal', 'send_mode' => 'send_as', 'import_from' => InquiryWorkflow::local(now()), 'transport_limit_mb' => 20, 'rights_confirmed' => 1];
        $this->patch(route('settings.mailbox.update'), array_replace($data, ['target_email' => 'different@example.test']))->assertSessionHasErrors('target_id');
        $this->patch(route('settings.mailbox.update'), array_replace($data, ['import_from' => InquiryWorkflow::local(now()->subDays(91))]))->assertSessionHasErrors('import_from');
        $this->patch(route('settings.mailbox.update'), array_replace($data, ['rights_confirmed' => 0]))->assertSessionHasErrors('rights_confirmed');
        $this->patch(route('settings.mailbox.update'), $data)->assertSessionHasNoErrors();
        $this->assertSame('disconnected', $c->fresh()->state);
        $this->assertSame(20 * 1024 * 1024, $c->fresh()->transport_limit);
    }

    public function test_fixture_activation_is_explicit_local_only_and_never_sends_existing_records(): void
    {
        DB::statement("SELECT setval('mailbox_connections_id_seq',42,true)");
        $this->get(route('settings.mailbox'))->assertOk();
        $this->assertSame('disconnected', MailboxConnection::current()->state);
        $this->post(route('settings.mailbox.action'), ['action' => 'demo', 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertTrue(MailboxConnection::current()->is_demo);
        $this->assertDatabaseCount('mail_dispatches', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        config(['mailbox.demo_enabled' => false]);
        $this->post(route('settings.mailbox.action'), ['action' => 'demo', 'confirm' => 1])->assertNotFound();
    }
}
