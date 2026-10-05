<?php

namespace Tests\Feature;

use App\Actions\MailIngest;
use App\Actions\MailOutbox;
use App\Actions\ManageFollowups;
use App\Actions\ManageQuotation;
use App\Actions\ManageRfq;
use App\Actions\PrepareRfqs;
use App\Jobs\DispatchMail;
use App\Jobs\SyncMailbox;
use App\Models\AttentionTask;
use App\Models\ClientQuotationApproval;
use App\Models\CompanySetting;
use App\Models\FollowupPlan;
use App\Models\FollowupPolicy;
use App\Models\FollowupStage;
use App\Models\MailboxFolder;
use App\Models\MailDispatch;
use App\Models\MailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Support\FollowupCalendar;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\InquiryWorkflow;
use App\Support\OutboundControl;
use App\Support\Processing;
use App\Support\RfqContent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class FollowupTest extends TestCase
{
    use QuotationFixture,RefreshDatabase;

    private ClientQuotationApproval $approval;

    private MailboxFolder $folder;

    private ManageFollowups $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05T01:00:00Z'));
        $this->quotationFixture();
        $this->action = app(ManageFollowups::class);
        $r = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput());
        $snapshot = app(ManageQuotation::class)->reviewSnapshot($r);
        $this->approval = app(ManageQuotation::class)->approve($r, $this->staff, Processing::hash($snapshot));
        $e = $this->approval->envelopes()->firstOrFail();
        $d = app(MailOutbox::class)->enqueue($e, $this->staff, (string) Str::uuid(), $e->digest);
        for ($n = 0; $n < 3; $n++) {
            (new DispatchMail($d->id))->handle();
            $this->travel(2)->seconds();
        }
        $this->assertSame('accepted', $d->fresh()->status);
        $this->folder = MailboxFolder::factory()->create(['last_sync_at' => now()]);
    }

    private function policy(array $changes = []): FollowupPolicy
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $latest = FollowupPolicy::latestFor('client_quote');

        return $this->action->policy($admin, array_replace(ManageFollowups::defaults('client_quote'), ['kind' => 'client_quote', 'expected_number' => $latest?->number ?? 0, 'enabled' => true, 'reason' => 'Controlled synthetic policy approval'], $changes));
    }

    private function activation(string $mode = 'automatic', bool $attach = false): FollowupPlan
    {
        if (! FollowupPolicy::latestFor('client_quote')) {
            $this->policy();
        }
        $s = $this->action->preview('client_quote', $this->approval->id, $this->staff, $mode, $attach);

        return $this->action->activate('client_quote', $this->approval->id, $this->staff, ['mode' => $mode, 'attach' => $attach, 'digest' => Processing::hash($s), 'reason' => 'Explicit synthetic bounded activation']);
    }

    private function due(FollowupPlan $plan): void
    {
        $this->travelTo($plan->fresh()->next_due_at);
        $this->folder->update(['last_sync_at' => now()]);
    }

    private function dispatch(FollowupPlan $plan): MailDispatch
    {
        $this->action->tick($plan);

        return $plan->stages()->latest('id')->firstOrFail()->envelope->dispatches()->firstOrFail();
    }

    private function blocked(callable $fn, string $fragment): void
    {
        try {
            $fn();
            $this->fail('Expected blocked follow-up');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($fragment, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    private function response(string $kind = 'question', bool $reviewed = true, bool $exact = true): MailMessage
    {
        return MailMessage::factory()->create(['is_demo' => true, 'inquiry_id' => $this->case->id, 'client_quotation_revision_id' => $exact ? $this->approval->client_quotation_revision_id : null, 'sender_email' => $this->approval->snapshot['content']['to']['email'], 'classification' => $kind, 'match_state' => $exact ? 'matched' : 'unmatched', 'attachment_state' => 'complete', 'received_at' => now(), 'response_reviewed_at' => $reviewed ? now() : null, 'response_reviewed_by' => $reviewed ? $this->staff->id : null]);
    }

    public function test_emergency_resume_cannot_reactivate_the_previous_reminder_authorization(): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $admin = User::factory()->create(['role' => 'admin']);
        $control = app(OutboundControl::class);
        $control->change($admin, true, 0, 'Controlled company outgoing emergency pause.');
        $control->change($admin, false, 1, 'Resume reviewed; no previous reminders authorized.');
        $this->action->tick($plan);
        $this->assertSame('paused', $plan->fresh()->state);
        $this->assertSame(0, FollowupStage::count());
        $this->assertSame(0, $plan->fresh()->send_count);
        $this->action->tick($plan->fresh());
        $this->assertSame(0, FollowupStage::count());
        $new = $this->activation();
        $this->assertSame($plan->id, $new->id);
        $this->assertSame(1, $new->outbound_epoch);
        $this->assertSame('active', $new->state);
        $this->assertSame(0, $new->send_count);
        Http::assertNothingSent();
    }

    public function test_disabled_policy_and_no_activation_never_create_a_reminder(): void
    {
        $this->blocked(fn () => $this->action->preview('client_quote', $this->approval->id, $this->staff, 'automatic'), 'Admin');
        $this->policy(['enabled' => false]);
        $this->artisan('lrs:followup-tick')->assertSuccessful();
        $this->assertSame(0, FollowupStage::count());
        $this->assertSame(1, MailDispatch::count());
        $this->actingAs($this->staff)->get(route('settings.followups'))->assertForbidden();
        $this->get(route('followups.show', ['client_quote', $this->approval->id]))->assertOk()->assertSee('Before activation');
    }

    public function test_frozen_preview_activation_due_stage_thread_and_duplicate_jobs(): void
    {
        $plan = $this->activation();
        $this->assertSame(0, $plan->send_count);
        $this->assertSame(0, FollowupStage::count());
        $this->get(route('followups.show', ['client_quote', $this->approval->id, 'mode' => 'automatic']))->assertOk()->assertSee('Exact messages')->assertSee('Cumulative sends');
        $this->due($plan);
        $d = $this->dispatch($plan);
        $this->action->tick($plan);
        $this->assertSame(1, FollowupStage::count());
        $this->assertSame(2, MailDispatch::count());
        $this->assertStringContainsString($this->approval->revision->quotation->reference, $d->envelope->snapshot['content']['body']);
        (new DispatchMail($d->id))->handle();
        (new DispatchMail($d->id))->handle();
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertSame(1, $plan->fresh()->send_count);
        $this->action->tick($plan);
        $plan = $plan->fresh();
        $this->assertSame(FollowupCalendar::next($d->fresh()->accepted_at, 3, $plan->authorization->snapshot['policy'])->toIso8601String(), $plan->next_due_at->toIso8601String());
        $this->assertSame(1, FollowupStage::count());
        $this->get(route('mail.dispatch', $d))->assertOk()->assertSee('Frozen message')->assertSee(route('followups.stage', $d->envelope->followup_stage_id));
        Http::assertNothingSent();
    }

    public function test_manual_review_never_automatically_enqueues_and_requires_exact_approval(): void
    {
        $plan = $this->activation('manual_review');
        $this->due($plan);
        $this->action->tick($plan);
        $stage = FollowupStage::firstOrFail();
        $this->assertSame('needs_review', $stage->state);
        $this->assertSame(1, MailDispatch::count());
        $this->assertSame(0, $plan->fresh()->send_count);
        $this->get(route('followups.stage', $stage))->assertOk()->assertSee('Approve this reminder');
        $this->post(route('followups.stage.approve', $stage), ['confirm' => 1, 'digest' => $stage->digest])->assertSessionHasNoErrors();
        $d = $stage->fresh()->envelope->dispatches()->firstOrFail();
        (new DispatchMail($d->id))->handle();
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertSame(1, $plan->fresh()->send_count);
    }

    public function test_cap_survives_policy_edits_and_reactivation_then_escalates(): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        (new DispatchMail($d->id))->handle();
        $this->action->tick($plan);
        $this->policy(['body' => 'Hello [recipient_name], please review [reference]. Original terms unchanged. [company_name]']);
        $plan = $this->activation();
        $this->assertSame(1, $plan->send_count);
        $this->due($plan);
        $d = $this->dispatch($plan);
        (new DispatchMail($d->id))->handle();
        $this->action->tick($plan);
        $this->assertSame('exhausted', $plan->fresh()->state);
        $this->assertSame(2, $plan->fresh()->send_count);
        $this->assertDatabaseHas('attention_tasks', ['followup_plan_id' => $plan->id, 'title' => 'No response after approved follow-ups']);
        $this->blocked(fn () => $this->activation(), 'cap');
        $this->assertSame('ready_for_sourcing', $this->case->fresh()->status);
    }

    public function test_downtime_sends_one_stage_and_anchors_next_to_actual_acceptance(): void
    {
        $plan = $this->activation();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T02:00:00Z'));
        $this->folder->update(['last_sync_at' => now()]);
        $d = $this->dispatch($plan);
        (new DispatchMail($d->id))->handle();
        $this->action->tick($plan);
        $this->action->tick($plan);
        $this->assertSame(1, FollowupStage::count());
        $this->assertTrue($plan->fresh()->next_due_at->gt(now()));
    }

    public static function responses(): array
    {
        return [
            'question' => ['question', true, true, 'stopped'], 'decline' => ['decline', true, true, 'stopped'], 'acceptance' => ['acceptance', true, true, 'stopped'], 'revision' => ['revision_request', true, true, 'stopped'], 'ooo' => ['out_of_office', false, true, 'held'], 'bounce' => ['bounce', false, true, 'stopped'], 'unreviewed-client' => ['question', false, true, 'held'], 'unmatched' => ['question', false, false, 'held'],
        ];
    }

    #[DataProvider('responses')]
    public function test_reply_classes_stop_or_hold_without_new_mail(string $kind, bool $reviewed, bool $exact, string $state): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        $m = $this->response($kind, $reviewed, $exact);
        $this->action->response($m);
        (new DispatchMail($d->id))->handle();
        $this->assertSame($state, $plan->fresh()->state);
        $this->assertSame('cancelled', $d->fresh()->status);
        $this->assertSame(0, $plan->fresh()->send_count);
        $this->assertSame(1, AttentionTask::count());
    }

    public function test_reply_during_provider_preparation_is_blocked_at_submission_barrier(): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        $real = app(GraphMail::class);
        $mock = \Mockery::mock(GraphMail::class)->makePartial();
        $mock->shouldReceive('call')->andReturnUsing(function ($c, $method, $path, $data = [], $token = null, $text = false) use ($real) {
            $result = $real->call($c, $method, $path, $data, $token, $text);
            if ($method === 'GET' && $text) {
                $this->response();
            }

            return $result;
        });
        app()->instance(GraphMail::class, $mock);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame(0, $plan->fresh()->send_count);
        $this->assertNull($d->fresh()->submission_started_at);
    }

    public function test_uncertain_submission_consumes_one_count_and_blocks_next_stage(): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        $real = app(GraphMail::class);
        $mock = \Mockery::mock(GraphMail::class)->makePartial();
        $mock->shouldReceive('call')->andReturnUsing(function ($c, $method, $path, $data = [], $token = null, $text = false) use ($real) {
            if ($method === 'POST' && str_ends_with($path, '/send')) {
                throw new GraphFailure(0, 30, true);
            }

            return $real->call($c, $method, $path, $data, $token, $text);
        });
        app()->instance(GraphMail::class, $mock);
        (new DispatchMail($d->id))->handle();
        (new DispatchMail($d->id))->handle();
        $this->assertSame('uncertain', $d->fresh()->status);
        $this->assertSame(1, $plan->fresh()->send_count);
        $this->action->tick($plan);
        $this->assertSame('held', $plan->fresh()->state);
        $this->assertSame(1, FollowupStage::count());
        $this->blocked(fn () => $this->activation(), 'unresolved');
    }

    public function test_stale_sync_disabled_policy_and_disconnected_mailbox_block_prepared_send(): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        $this->folder->update(['last_sync_at' => now()->subMinutes(16)]);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame(0, $plan->fresh()->send_count);
        $this->action->tick($plan);
        $this->assertSame('held', $plan->fresh()->state);
        $this->connection->update(['state' => 'disconnected']);
        $this->blocked(fn () => $this->activation(), 'Connect');
        $this->policy(['enabled' => false]);
        $this->assertSame('paused', $plan->fresh()->state);
    }

    public function test_expiry_parent_change_and_inactive_contact_stop_without_sending(): void
    {
        $plan = $this->activation();
        $this->case->update(['status' => 'on_hold']);
        $this->action->tick($plan);
        $this->assertSame('stopped', $plan->fresh()->state);
        $this->assertSame(0, FollowupStage::count());
    }

    public function test_database_freezes_approval_content_and_cumulative_count(): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        (new DispatchMail($d->id))->handle();
        try {
            DB::transaction(fn () => FollowupPlan::whereKey($plan->id)->update(['send_count' => 0]));
            $this->fail('Count reset accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cumulative', $e->getMessage());
        }
        $stage = FollowupStage::firstOrFail();
        try {
            DB::transaction(fn () => $stage->update(['digest' => str_repeat('a', 64)]));
            $this->fail('Content edit accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Frozen', $e->getMessage());
        }
    }

    public function test_policy_validation_authorization_and_task_resolution_do_not_resume(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->get(route('settings.followups'))->assertOk()->assertSee('Automatic follow-ups disabled');
        $d = ['kind' => 'client_quote', 'expected_number' => 0, 'enabled' => 1, 'interval_days' => '2,3', 'max_sends' => 2, 'weekdays' => [1, 2, 3, 4, 5], 'holiday_dates' => '2026-10-12', 'opens' => '09:00', 'closes' => '17:00', 'freshness_minutes' => 15, 'subject' => '[reference]', 'body' => 'Unknown [price]', 'attachment_mode' => 'none', 'reason' => 'Approval'];
        $this->post(route('settings.followups.save'), $d)->assertSessionHasErrors('body');
        $this->post(route('settings.followups.save'), array_replace($d, ['body' => '[reference]', 'interval_days' => '2.5,3']))->assertSessionHasErrors('intervals.0');
        $this->post(route('settings.followups.save'), array_replace($d, ['body' => '[reference]', 'weekdays' => ['1.5']]))->assertSessionHasErrors('weekdays.0');
        $this->postJson(route('settings.followups.save'), array_replace($d, ['interval_days' => ['2', '3'], 'weekdays' => 'Monday', 'subject' => ['invalid']]))->assertUnprocessable()->assertJsonValidationErrors(['interval_days', 'weekdays', 'subject']);
        $this->assertSame(0, FollowupPolicy::count());
        $this->post(route('settings.followups.save'), array_replace($d, ['body' => '[reference]', 'enabled' => 0, 'holiday_dates' => '', 'weekdays' => ['1', '2', '3', '4', '5']]))->assertRedirect()->assertSessionHasNoErrors();
        $saved = FollowupPolicy::latestFor('client_quote');
        $this->assertFalse($saved->enabled);
        $this->assertSame([1, 2, 3, 4, 5], $saved->snapshot['weekdays']);
        $this->assertSame([2, 3], $saved->snapshot['intervals']);
        $this->assertSame([], $saved->snapshot['holidays']);
        $this->policy();
        $this->actingAs($this->staff);
        $plan = $this->activation();
        $this->action->halt($plan, 'paused', 'Staff review required', $this->staff);
        $task = AttentionTask::firstOrFail();
        $this->post(route('followups.task', $task), ['action' => 'resolve', 'resolution' => 'Reviewed original evidence'])->assertSessionHasNoErrors();
        $this->assertSame('paused', $plan->fresh()->state);
        $this->get(route('followups.index'))->assertOk()->assertSee('Recent follow-up plans');
    }

    public static function boundaries(): array
    {
        return [
            'expiry' => ['expiry'], 'contact' => ['contact'], 'revision' => ['revision'], 'shipment' => ['shipment'], 'sender' => ['sender'], 'authorization' => ['authorization'], 'timezone' => ['timezone'], 'catchup' => ['catchup'], 'processing' => ['processing'], 'disabled' => ['disabled'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_current_parent_sender_and_reply_health_boundaries_are_rechecked(string $change): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        match ($change) {
            'expiry' => $this->travelTo($this->approval->revision->expires_at),
            'contact' => $this->case->contact->update(['is_active' => false, 'is_primary' => false]),
            'revision' => $this->approval->revision->quotation->update(['current_number' => 2]),
            'shipment' => $this->case->update(['shipment_revision' => $this->case->shipment_revision + 1]),
            'sender' => $this->connection->update(['identity_hash' => hash('sha256', 'changed identity')]),
            'authorization' => $this->staff->update(['is_active' => false]),
            'timezone' => CompanySetting::current()->update(['timezone' => 'UTC']),
            'catchup' => $this->folder->update(['page' => ['value' => []]]),
            'processing' => $this->response('other', false)->update(['attachment_state' => 'pending']),
            'disabled' => $this->policy(['enabled' => false]),
        };
        (new DispatchMail($d->id))->handle();
        $this->assertContains($d->fresh()->status, ['failed', 'cancelled']);
        $this->assertNull($d->fresh()->submission_started_at);
        $this->assertSame(0, $plan->fresh()->send_count);
    }

    public function test_exact_client_pdf_is_optional_and_checksum_is_frozen(): void
    {
        $this->policy(['attachment_mode' => 'approved']);
        $plan = $this->activation('automatic', true);
        $this->due($plan);
        $d = $this->dispatch($plan);
        $manifest = $d->envelope->snapshot['content']['manifest'];
        $this->assertCount(1, $manifest);
        $this->assertSame($this->approval->revision->pdf_checksum, $manifest[0]['checksum']);
        $this->assertArrayHasKey('quotation_revision_id', $manifest[0]);
        for ($n = 0; $n < 3; $n++) {
            (new DispatchMail($d->id))->handle();
            $this->travel(2)->seconds();
        }
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertSame(1, $plan->fresh()->send_count);
    }

    public function test_incoming_thread_and_human_client_review_stop_exact_plan(): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        (new DispatchMail($d->id))->handle();
        $this->action->tick($plan);
        $m = app(MailIngest::class)->handle($this->folder, ['id' => 'exact-client-reply', 'internetMessageId' => '<reply@example.test>', 'subject' => 'Re: '.$d->envelope->snapshot['content']['subject'], 'from' => ['emailAddress' => ['address' => $this->approval->snapshot['content']['to']['email']]], 'receivedDateTime' => now()->toIso8601String(), 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => $d->fresh()->internet_id]], 'body' => ['contentType' => 'Text', 'content' => 'Can you confirm the cargo cutoff?']]);
        $this->assertSame($this->approval->client_quotation_revision_id, $m->client_quotation_revision_id);
        $this->assertSame('stopped', $plan->fresh()->state);
        $this->assertDatabaseCount('client_decisions', 0);
        $this->assertDatabaseHas('attention_tasks', ['mail_message_id' => $m->id, 'kind' => 'client_response']);
        $sourceHash = $m->source_hash;
        $this->post(route('mail.review', $m), ['lock_version' => $m->lock_version, 'decision' => 'associate', 'classification' => 'question', 'inquiry_id' => $this->case->id, 'client_quotation_revision_id' => $this->approval->client_quotation_revision_id, 'reason' => 'Reviewed exact recipient, thread and meaningful question'])->assertSessionHasNoErrors();
        $this->assertSame('stopped', $plan->fresh()->state);
        $this->assertSame($sourceHash, $m->fresh()->source_hash);
        $this->assertDatabaseHas('mail_events', ['mail_message_id' => $m->id, 'kind' => 'staff_review']);
    }

    public function test_expiry_does_not_extend_to_fit_a_schedule_and_window_blocks_release(): void
    {
        $this->policy(['intervals' => [30, 30]]);
        $this->blocked(fn () => $this->activation(), 'validity');
        $this->policy();
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        $this->travelTo(now()->setTimezone('Asia/Kuala_Lumpur')->setTime(17, 0)->utc());
        (new DispatchMail($d->id))->handle();
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame(0, $plan->fresh()->send_count);
    }

    public function test_vendor_manual_evidence_starts_only_after_activation_and_meaningful_quote_stops(): void
    {
        $vendor = Vendor::factory()->create(['services' => ['LCL']]);
        $vendor->contacts()->create(['name' => 'Synthetic vendor desk', 'email' => 'vendor-followup@example.test', 'is_primary' => true, 'is_active' => true]);
        $round = app(PrepareRfqs::class)->handle($this->case, $this->staff, [$vendor->id], $this->case->fresh()->lock_version);
        $rfq = $round->rfqs()->where('vendor_id', $vendor->id)->firstOrFail();
        $p = $rfq->current()->payload;
        app(ManageRfq::class)->save($rfq, $this->staff, array_replace($p, ['expected_revision' => $rfq->current_number, 'to_contact_id' => $p['to']['id'], 'cc_contact_ids' => [], 'document_ids' => [], 'attachments_reviewed' => true, 'disclose_addresses' => true, 'disclosure_notes' => 'Necessary fictional service address confirmed', 'response_due_at' => InquiryWorkflow::local(now()->addDays(2))]));
        $rfq = $rfq->fresh();
        $r = $rfq->current();
        $a = app(ManageRfq::class)->approve($rfq, $this->staff, $r->number, Processing::hash(RfqContent::snapshot($r)));
        app(ManageRfq::class)->manual($rfq, $this->staff, ['expected_revision' => $r->number, 'digest' => $a->digest, 'action_key' => (string) Str::uuid(), 'recipient' => $a->snapshot['to']['email'], 'sent_at' => InquiryWorkflow::local(now()), 'channel' => 'email', 'evidence' => 'Synthetic actual declared vendor send']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->action->policy($admin, array_replace(ManageFollowups::defaults('rfq'), ['intervals' => [1, 1], 'kind' => 'rfq', 'expected_number' => 0, 'enabled' => true, 'reason' => 'Synthetic RFQ reminder policy']));
        $s = $this->action->preview('rfq', $a->id, $this->staff, 'automatic');
        $this->assertNull($s['baseline']['dispatch_id']);
        $this->assertStringContainsString('Synthetic actual', $s['baseline']['evidence']);
        $plan = $this->action->activate('rfq', $a->id, $this->staff, ['mode' => 'automatic', 'digest' => Processing::hash($s), 'reason' => 'Exact vendor activation']);
        $this->due($plan);
        $d = $this->dispatch($plan);
        $m = MailMessage::factory()->create(['is_demo' => true, 'inquiry_id' => $this->case->id, 'rfq_revision_id' => $r->id, 'sender_email' => $a->snapshot['to']['email'], 'classification' => 'quote', 'match_state' => 'matched', 'attachment_state' => 'pending']);
        $this->action->response($m);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('stopped', $plan->fresh()->state);
        $this->assertSame('cancelled', $d->fresh()->status);
        $this->assertSame(0, $plan->fresh()->send_count);
    }

    public function test_rejected_submission_retry_does_not_increment_cap_or_prematurely_escalate(): void
    {
        $this->policy(['intervals' => [2], 'max_sends' => 1]);
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        $real = app(GraphMail::class);
        $mock = \Mockery::mock(GraphMail::class)->makePartial();
        $rejected = false;
        $mock->shouldReceive('call')->andReturnUsing(function ($c, $method, $path, $data = [], $token = null, $text = false) use ($real, &$rejected) {
            if ($method === 'POST' && str_ends_with($path, '/send') && ! $rejected) {
                $rejected = true;
                throw new GraphFailure(429, 17);
            }

            return $real->call($c, $method, $path, $data, $token, $text);
        });
        app()->instance(GraphMail::class, $mock);
        (new DispatchMail($d->id))->handle();
        $this->assertSame('ready', $d->fresh()->status);
        $this->assertSame(1, $plan->fresh()->send_count);
        $this->action->tick($plan);
        $this->assertSame('active', $plan->fresh()->state);
        $this->travel(18)->seconds();
        (new DispatchMail($d->id))->handle();
        $this->action->tick($plan);
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertSame(1, $plan->fresh()->send_count);
        $this->assertSame('exhausted', $plan->fresh()->state);
    }

    public function test_admin_can_import_a_synthetic_question_from_the_browser_control(): void
    {
        $plan = $this->activation();
        $this->due($plan);
        $d = $this->dispatch($plan);
        (new DispatchMail($d->id))->handle();
        $this->action->tick($plan);
        Queue::fake([SyncMailbox::class]);
        $admin = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($admin)->post(route('settings.mailbox.action'), ['action' => 'followup_response', 'confirm' => '1', 'plan_id' => $plan->id])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');
        Queue::assertPushed(SyncMailbox::class);
    }
}
