<?php

namespace Tests\Feature;

use App\Actions\ManageFollowups;
use App\Actions\ManageQuotation;
use App\Models\FollowupPlan;
use App\Models\FollowupStage;
use App\Models\MailboxFolder;
use App\Models\MailDispatch;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class FollowupConcurrencyTest extends TestCase
{
    use DatabaseMigrations,QuotationFixture;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            DB::disconnect();
            RefreshDatabaseState::$migrated = false;
        });
    }

    private function plan(string $mode): FollowupPlan
    {
        $this->quotationFixture();
        $r = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput());
        $a = app(ManageQuotation::class)->approve($r, $this->staff, Processing::hash(app(ManageQuotation::class)->reviewSnapshot($r)));
        DB::table('quotation_manual_sends')->insert(['client_quotation_approval_id' => $a->id, 'recorded_by' => $this->staff->id, 'recorded_at' => now(), 'reason' => 'Synthetic declared send']);
        $admin = User::factory()->create(['role' => 'admin']);
        $action = app(ManageFollowups::class);
        $action->policy($admin, ManageFollowups::defaults('client_quote') + ['kind' => 'client_quote', 'expected_number' => 0, 'enabled' => true, 'reason' => 'Controlled concurrency policy']);
        MailboxFolder::factory()->create(['last_sync_at' => now()]);
        $s = $action->preview('client_quote', $a->id, $this->staff, $mode);
        $plan = $action->activate('client_quote', $a->id, $this->staff, ['mode' => $mode, 'digest' => Processing::hash($s), 'reason' => 'Controlled activation']);
        $plan->update(['fixture_at' => $plan->next_due_at]);

        return $plan->fresh();
    }

    private function processes(array $data): void
    {
        $db = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'database', 'FOLLOWUP_TEST_DISK_ROOT' => Storage::disk('inquiry_documents')->path(''), 'FOLLOWUP_TEST_MAIL_ROOT' => Storage::disk('mailbox')->path('')];
        $processes = [new Process([PHP_BINARY, base_path('tests/Fixtures/followup-process.php')], base_path(), $env, json_encode($data), 60), new Process([PHP_BINARY, base_path('tests/Fixtures/followup-process.php')], base_path(), $env, json_encode($data), 60)];
        try {
            foreach ($processes as $p) {
                $p->start();
            }foreach ($processes as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            }
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
    }

    public function test_two_independent_scheduler_processes_claim_one_manual_stage(): void
    {
        $plan = $this->plan('manual_review');
        $this->processes(['operation' => 'tick', 'plan_id' => $plan->id]);
        $this->assertSame(1, FollowupStage::count());
        $this->assertSame('needs_review', FollowupStage::first()->state);
        $this->assertSame(0, MailDispatch::count());
    }

    public function test_two_independent_dispatch_workers_submit_and_count_one_reminder(): void
    {
        $plan = $this->plan('automatic');
        app(ManageFollowups::class)->tick($plan);
        $d = MailDispatch::firstOrFail();
        $this->processes(['operation' => 'dispatch', 'dispatch_id' => $d->id]);
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertSame(1, $plan->fresh()->send_count);
        $this->assertSame(1, MailDispatch::count());
    }
}
