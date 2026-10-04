<?php

namespace Tests\Feature;

use App\Actions\RequestMailboxVerification;
use App\Jobs\SendInquiryReceipt;
use App\Mail\InquiryReceipt;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailboxVerificationTest extends TestCase
{
    use DatabaseMigrations;

    private function intake(): Inquiry
    {
        $token = $this->get('/request-quote')->viewData('intakeToken');
        $this->post('/request-quote', ['intake_token' => $token, 'contact' => ['name' => 'Mailbox QA', 'email' => 'mailbox@example.test', 'company' => 'QA company'], 'shipment' => ['mode' => 'unknown', 'scope' => 'unknown', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore', 'cargo_description' => 'General QA cargo'], 'classification' => 'other', 'privacy_acknowledged' => '1', 'privacy_version' => CompanySetting::current()->public_privacy_version])->assertSessionHasNoErrors();

        return Inquiry::latest('id')->firstOrFail();
    }

    private function enabled(): void
    {
        config(['public-intake.receipt_mailer' => 'smtp', 'queue.default' => 'database']);
        CompanySetting::current()->update(['receipt_mail_enabled' => true]);
        Mail::fake();
        Queue::fake();
    }

    private function dispatch(Inquiry $inquiry): array
    {
        $record = $inquiry->mailboxVerifications()->firstOrFail();
        (new SendInquiryReceipt($record->id))->handle();
        $mail = Mail::sent(InquiryReceipt::class)->last();

        return [$record->fresh(), $mail->confirmationToken];
    }

    public function test_disabled_and_log_transport_accept_case_without_tokens_or_external_email(): void
    {
        Mail::fake();
        Queue::fake();
        $inquiry = $this->intake();
        $this->assertSame('disabled', $inquiry->mailboxVerifications()->first()->transport_state);
        $this->assertNull($inquiry->mailboxVerifications()->first()->token_hash);
        CompanySetting::current()->update(['receipt_mail_enabled' => true]);
        config(['public-intake.receipt_mailer' => 'log']);
        $record = app(RequestMailboxVerification::class)->handle($inquiry);
        $this->assertSame('unavailable', $record->transport_state);
        $this->get('/request-quote/received')->assertSee('not externally sent');
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame('needs_review', $inquiry->fresh()->status);
    }

    public function test_fixed_receipt_queues_only_after_commit_job_retries_are_deduplicated_and_no_sensitive_cargo_is_sent(): void
    {
        $this->enabled();
        DB::beginTransaction();
        $inquiry = $this->intake();
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SendInquiryReceipt::class, 1);
        [$record,$token] = $this->dispatch($inquiry);
        (new SendInquiryReceipt($record->id))->handle();
        Mail::assertSent(InquiryReceipt::class, 1);
        $this->assertSame('transport_submitted', $record->transport_state);
        $this->assertSame(hash('sha256', $token), $record->token_hash);
        $this->assertNotSame($token, $record->token_hash);
        $this->assertSame(24, (int) $record->claimed_at->diffInHours($record->expires_at));
        $mail = Mail::sent(InquiryReceipt::class)->first();
        $html = $mail->render();
        $this->assertStringContainsString($inquiry->reference, $html);
        $this->assertStringContainsString('/request-quote/confirm#', $html);
        $this->assertStringNotContainsString('General QA cargo', $html);
        $this->assertStringNotContainsString('QA company', $html);
        $this->assertStringNotContainsString('Port Klang', $html);
        $this->assertEmpty($mail->attachments);
        $job = serialize(new SendInquiryReceipt($record->id));
        $this->assertStringNotContainsString($token, $job);
        $this->assertStringNotContainsString($token, DB::table('audit_entries')->get()->toJson());
    }

    public function test_confirmation_get_never_consumes_and_post_is_atomic_idempotent_and_separate_from_readiness(): void
    {
        $this->enabled();
        $inquiry = $this->intake();
        [$record,$token] = $this->dispatch($inquiry);
        $this->get('/request-quote/confirm')->assertOk()->assertDontSee($inquiry->reference)->assertDontSee('General QA cargo');
        $this->assertNull($record->fresh()->confirmed_at);
        $this->post('/request-quote/confirm', ['confirmation_token' => $token])->assertOk()->assertSee('Email address confirmed.');
        $at = $record->fresh()->confirmed_at;
        $this->post('/request-quote/confirm', ['confirmation_token' => $token])->assertSee('Already confirmed.');
        $this->assertTrue($at->equalTo($record->fresh()->confirmed_at));
        $this->assertTrue($inquiry->mailboxConfirmed());
        $this->assertSame('needs_review', $inquiry->fresh()->status);
        $this->assertNull($inquiry->client_id);
        $this->assertSame(0, $inquiry->versions()->count());
        $this->get('/inquiries/'.$inquiry->id)->assertRedirect('/login');
    }

    public function test_expired_invalid_and_resent_tokens_have_safe_states_and_email_changes_invalidate_access_check(): void
    {
        $this->enabled();
        $inquiry = $this->intake();
        [$record,$token] = $this->dispatch($inquiry);
        $record->update(['expires_at' => now()->subSecond()]);
        $this->post('/request-quote/confirm', ['confirmation_token' => $token])->assertSee('expired')->assertDontSee($inquiry->reference);
        $this->post('/request-quote/confirm', ['confirmation_token' => str_repeat('a', 64)])->assertSee('unavailable');
        $new = app(RequestMailboxVerification::class)->handle($inquiry);
        $this->assertNotNull($record->fresh()->invalidated_at);
        $this->post('/request-quote/confirm', ['confirmation_token' => $token])->assertSee('unavailable');
        (new SendInquiryReceipt($new->id))->handle();
        $newToken = Mail::sent(InquiryReceipt::class)->last()->confirmationToken;
        $this->post('/request-quote/confirm', ['confirmation_token' => $newToken])->assertSee('confirmed');
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->patch('/inquiries/'.$inquiry->id.'/public-contact', ['lock_version' => 0, 'resolution' => 'new_client', 'contact' => ['name' => 'Corrected QA', 'email' => 'corrected@example.test', 'company' => 'Assessed QA company'], 'identity_assessed' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse($inquiry->fresh()->mailboxConfirmed());
        $this->assertSame('mailbox@example.test', $inquiry->publicSubmission->snapshot['contact']['email']);
        $this->post('/request-quote/confirm', ['confirmation_token' => $newToken])->assertSee('unavailable');
    }

    public function test_failed_or_uncertain_transport_preserves_inquiry_and_duplicate_job_does_not_blindly_resend(): void
    {
        $this->enabled();
        $inquiry = $this->intake();
        $record = $inquiry->mailboxVerifications()->firstOrFail();
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('Transport failed with secret details'));
        (new SendInquiryReceipt($record->id))->handle();
        (new SendInquiryReceipt($record->id))->handle();
        $this->assertSame('failed', $record->fresh()->transport_state);
        $this->assertSame('needs_review', $inquiry->fresh()->status);
        $this->assertStringNotContainsString('secret details', DB::table('audit_entries')->get()->toJson());
        $next = app(RequestMailboxVerification::class)->handle($inquiry);
        $next->update(['transport_state' => 'dispatching']);
        (new SendInquiryReceipt($next->id))->handle();
        $this->assertSame('dispatching', $next->fresh()->transport_state);
    }

    public function test_resend_requires_receipt_session_or_authorized_active_staff_and_has_rate_limits(): void
    {
        $this->enabled();
        $inquiry = $this->intake();
        $this->withSession(['public_receipt_inquiry' => null])->post('/request-quote/received/resend')->assertNotFound();
        $this->post('/inquiries/'.$inquiry->id.'/public-contact/resend')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'agent', 'is_active' => true]))->post('/inquiries/'.$inquiry->id.'/public-contact/resend', ['lock_version' => $inquiry->lock_version])->assertRedirect();
        $this->post('/inquiries/'.$inquiry->id.'/public-contact/resend')->assertStatus(429);
    }

    public function test_rolled_back_intake_never_dispatches_a_receipt(): void
    {
        $this->enabled();
        DB::beginTransaction();
        $this->intake();
        DB::rollBack();
        $this->assertSame(0, Inquiry::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }
}
