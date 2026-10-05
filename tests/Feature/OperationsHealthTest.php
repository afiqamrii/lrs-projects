<?php

namespace Tests\Feature;

use App\Actions\MailIngest;
use App\Actions\MailOutbox;
use App\Jobs\DispatchMail;
use App\Jobs\ReconcileMail;
use App\Jobs\WorkerHeartbeat;
use App\Models\CompanySetting;
use App\Models\MailboxFolder;
use App\Models\User;
use App\Support\OpenAiResponses;
use App\Support\OutboundControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\LifecycleFixture;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class OperationsHealthTest extends TestCase
{
    use LifecycleFixture, QuotationFixture, RefreshDatabase;

    public function test_admin_control_permissions_validation_audit_and_unknown_stale_observations(): void
    {
        $this->get(route('operations.health'))->assertForbidden();
        $this->post(route('operations.control'), ['action' => 'pause'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('operations.health'))->assertOk()->assertSee('No observation');
        $this->post(route('operations.control'), ['action' => 'pause', 'epoch' => 0, 'reason' => 'short'])->assertSessionHasErrors(['reason', 'confirm']);
        $this->post(route('operations.control'), ['action' => 'pause', 'epoch' => 0, 'reason' => 'Controlled emergency drill; no external recipients.', 'confirm' => 1])->assertRedirect(route('operations.health'));
        $this->assertTrue(CompanySetting::current()->outbound_paused);
        $this->assertSame(1, CompanySetting::current()->outbound_epoch);
        $this->assertDatabaseHas('audit_entries', ['action' => 'Emergency outbound pause']);
        $this->post(route('operations.control'), ['action' => 'resume', 'epoch' => 0, 'reason' => 'Reviewed and explicitly resumed.', 'confirm' => 1])->assertSessionHasErrors('control');
        $this->artisan('lrs:operations-heartbeat')->assertSuccessful();
        Queue::assertPushed(WorkerHeartbeat::class);
        $this->assertNull(CompanySetting::current()->worker_seen_at);
        (new WorkerHeartbeat)->handle();
        $this->get(route('operations.health'))->assertOk()->assertSee('Recently observed');
        $this->travel(6)->minutes();
        $this->get(route('operations.health'))->assertOk()->assertSee('Stale observation');
        $this->assertNull(CompanySetting::current()->getRawOriginal('access_token'));
    }

    public function test_pause_blocks_existing_dispatch_after_resume_until_exact_recovery_and_capture_continues(): void
    {
        $q = $this->quote();
        $envelope = $q->approval->envelopes()->firstOrFail();
        $d = app(MailOutbox::class)->enqueue($envelope, $this->staff, (string) Str::uuid(), $envelope->digest);
        $admin = User::factory()->create(['role' => 'admin']);
        $control = app(OutboundControl::class);
        $control->change($admin, true, 0, 'Controlled outgoing emergency pause.');
        $folder = MailboxFolder::factory()->create();
        $source = ['id' => 'pause-incoming-fixture', 'receivedDateTime' => now()->toIso8601String(), 'from' => ['emailAddress' => ['address' => 'new-client@fictional.example']], 'subject' => 'New fictional inquiry during outgoing pause', 'body' => ['contentType' => 'Text', 'content' => 'Please assess fictional sea freight.']];
        $this->assertNotNull(app(MailIngest::class)->handle($folder, $source));
        $this->assertDatabaseHas('inquiries', ['status' => 'needs_review', 'source_channel' => 'email', 'title' => $source['subject']]);
        $control->change($admin, false, 1, 'Reviewed control only; pending work requires separate recovery.');
        (new DispatchMail($d->id))->handle();
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame(0, $d->fresh()->attempts);
        $this->assertNull($d->fresh()->submission_started_at);
        Http::assertNothingSent();
        app(MailOutbox::class)->recover($d->fresh(), $this->staff, 'Reviewed exact current still-eligible dispatch after pause.');
        $this->assertSame(1, $d->fresh()->outbound_epoch);
        $this->assertSame('queued', $d->fresh()->status);
        $this->assertDatabaseCount('mail_dispatches', 1);
    }

    public function test_pause_preserves_ambiguous_draft_creation_as_reconciliation_only(): void
    {
        $quote = $this->quote();
        $envelope = $quote->approval->envelopes()->firstOrFail();
        $dispatch = app(MailOutbox::class)->enqueue($envelope, $this->staff, (string) Str::uuid(), $envelope->digest);
        $dispatch->update(['status' => 'preparing', 'draft_started_at' => now(), 'provider_draft_id' => null]);
        app(OutboundControl::class)->change(User::factory()->create(['role' => 'admin']), true, 0, 'Preserve interrupted provider draft evidence during emergency.');
        (new DispatchMail($dispatch->id))->handle();
        $this->assertSame('uncertain', $dispatch->fresh()->status);
        $this->assertNotNull($dispatch->fresh()->draft_started_at);
        $this->assertNull($dispatch->fresh()->submission_started_at);
        $this->assertSame(0, $dispatch->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_restore_lockdown_disables_connection_receipts_ai_jobs_and_resume_without_replaying_outbox(): void
    {
        $q = $this->quote();
        $d = app(MailOutbox::class)->enqueue($q->approval->envelopes()->firstOrFail(), $this->staff, (string) Str::uuid(), $q->approval->envelopes()->firstOrFail()->digest);
        config(['operations.restore_lockdown' => true]);
        $this->assertFalse($this->connection->usable());
        (new DispatchMail($d->id))->handle();
        $this->assertNull($d->fresh()->submission_started_at);
        $this->assertSame('failed', $d->fresh()->status);
        $this->artisan('lrs:mailbox-tick')->assertSuccessful();
        $this->artisan('lrs:operations-heartbeat')->assertSuccessful();
        (new WorkerHeartbeat)->handle();
        $this->assertNull(CompanySetting::current()->worker_seen_at);
        $this->assertFalse(app(OpenAiResponses::class)->checkModel('synthetic-fixture'));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('operations.health'))->assertOk()->assertSee('Restore lockdown is active');
        Http::assertNothingSent();
        $this->artisan('lrs:verify-restored-records')->assertFailed();
    }

    public function test_failed_payloads_and_oauth_tokens_never_enter_health_page(): void
    {
        $quote = $this->quote();
        $envelope = $quote->approval->envelopes()->firstOrFail();
        $dispatch = app(MailOutbox::class)->enqueue($envelope, $this->staff, (string) Str::uuid(), $envelope->digest);
        $dispatch->update(['status' => 'accepted', 'accepted_at' => now(), 'reconcile_attempts' => 10, 'next_attempt_at' => now()]);
        $this->artisan('lrs:mailbox-tick')->assertSuccessful();
        Queue::assertNotPushed(ReconcileMail::class, fn ($job): bool => $job->dispatchId === $dispatch->id);
        $secret = 'never-render-this-oauth-secret';
        $this->connection->update(['access_token' => $secret, 'refresh_token' => $secret]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'mail', 'payload' => $secret, 'exception' => $secret, 'failed_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('operations.health'))->assertOk()->assertDontSee($secret)->assertSee('Latest 1 failed job identities')->assertSee('Automatic observation exhausted; staff escalation required.')->assertSee(route('mail.dispatch', $dispatch));
        $this->assertStringNotContainsString($secret, $this->connection->getRawOriginal('access_token'));
    }
}
