<?php

namespace Tests\Feature;

use App\Actions\ManageQuotation;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationApproval;
use App\Models\ClientQuotationRevision;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class QuotationConcurrencyTest extends TestCase
{
    use DatabaseMigrations, QuotationFixture;

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

    public function test_competing_edits_preserve_revisions_and_approvals_bind_one_exact_version(): void
    {
        $this->quotationFixture();
        $second = User::factory()->create(['role' => 'admin']);
        app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput());
        $payload = ['operation' => 'save', 'inquiry_id' => $this->case->id, 'staff_id' => $this->staff->id, 'data' => $this->quotationInput(1)];
        $outputs = $this->processes([$payload, array_replace($payload, ['staff_id' => $second->id])]);
        $states = array_column($outputs, 'state');
        sort($states);
        $this->assertSame(['saved', 'stale_or_blocked'], $states);
        $this->assertSame(2, ClientQuotationRevision::count());
        $quote = ClientQuotation::firstOrFail();
        $this->assertSame(2, $quote->current_number);
        $r = $quote->current();
        $snapshot = app(ManageQuotation::class)->reviewSnapshot($r);
        $payload = ['operation' => 'approve', 'revision_id' => $r->id, 'staff_id' => $this->staff->id, 'digest' => Processing::hash($snapshot)];
        $outputs = $this->processes([$payload, array_replace($payload, ['staff_id' => $second->id])]);
        $this->assertSame(['approved', 'approved'], array_column($outputs, 'state'));
        $this->assertSame($outputs[0]['id'], $outputs[1]['id']);
        $this->assertSame(1, ClientQuotationApproval::count());
    }

    private function processes(array $payloads): array
    {
        $db = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'QUEUE_CONNECTION' => 'database', 'QUOTATION_TEST_DISK_ROOT' => Storage::disk('inquiry_documents')->path('')];
        $processes = array_map(fn (array $p): Process => new Process([PHP_BINARY, base_path('tests/Fixtures/quotation-process.php')], base_path(), $env, json_encode($p, JSON_THROW_ON_ERROR), 45), $payloads);
        try {
            foreach ($processes as $process) {
                $process->start();
            } $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
