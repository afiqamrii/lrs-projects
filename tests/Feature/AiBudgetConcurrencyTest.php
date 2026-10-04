<?php

namespace Tests\Feature;

use App\Models\AiBudgetDay;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\DocumentRun;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\User;
use App\Support\AiSources;
use App\Support\AiUsage;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AiBudgetConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function settings(): AiSetting
    {
        $settings = AiSetting::current();
        $settings->update(['enabled' => true, 'model' => 'synthetic-concurrency-model', 'configuration' => ['input_rate' => '1', 'output_rate' => '2', 'rate_version' => 'fixture-1', 'rate_date' => '2026-10-04', 'structured_verified' => true, 'run_cap' => '1', 'inquiry_cap' => '1', 'daily_cap' => '1'], 'model_check' => ['state' => 'accessible', 'model' => 'synthetic-concurrency-model']]);

        return $settings;
    }

    private function payload(Inquiry $inquiry, User $staff, AiSetting $settings): array
    {
        $sources = AiSources::selected($inquiry, ['original-'.$inquiry->id]);

        return ['mode' => 'ai', 'inquiry' => $inquiry->id, 'staff' => $staff->id, 'sources' => array_column($sources, 'id'), 'shipment_hash' => $inquiry->snapshotHash(), 'scope_hash' => AiUsage::scope($sources, $settings)];
    }

    private function compete(array $payloads): array
    {
        $database = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'QUEUE_CONNECTION' => 'database'];
        $processes = array_map(fn (array $payload): Process => new Process([PHP_BINARY, base_path('tests/Fixtures/start-processing.php')], base_path(), $environment, json_encode($payload, JSON_THROW_ON_ERROR), 30), $payloads);
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
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

    public function test_competing_inquiries_cannot_bypass_the_daily_reservation_cap(): void
    {
        $staff = User::factory()->create();
        $settings = $this->settings();
        $one = Inquiry::factory()->create(['original_source_text' => 'SYNTHETIC concurrency cargo source, 250.5 kg, Malaysia to Singapore.']);
        $two = Inquiry::factory()->create(['original_source_text' => $one->original_source_text]);
        $reservation = AiUsage::estimate(AiSources::selected($one, ['original-'.$one->id]), $settings);
        $settings->update(['configuration' => [...$settings->configuration, 'daily_cap' => (string) BigDecimal::of($reservation)->multipliedBy('1.5')]]);
        $results = $this->compete([$this->payload($one, $staff, $settings), $this->payload($two, $staff, $settings)]);
        $this->assertSame(1, collect($results)->where('state', 'created')->count());
        $this->assertSame(1, collect($results)->where('state', 'blocked')->count());
        $this->assertDatabaseCount('ai_runs', 1);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'ai')->count());
        $this->assertLessThanOrEqual($settings->configuration['daily_cap'], AiBudgetDay::firstOrFail()->reserved);
    }

    public function test_competing_identical_ai_scope_creates_one_run_reservation_and_after_commit_job(): void
    {
        $staff = User::factory()->create();
        $settings = $this->settings();
        $inquiry = Inquiry::factory()->create(['original_source_text' => 'SYNTHETIC exact scope source.']);
        $payload = $this->payload($inquiry, $staff, $settings);
        $results = $this->compete([$payload, $payload]);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('ai_runs', 1);
        $this->assertSame(AiRun::firstOrFail()->reservation, AiBudgetDay::firstOrFail()->reserved);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'ai')->count());
    }

    public function test_competing_local_extraction_has_one_active_run_and_one_after_commit_job(): void
    {
        Storage::fake('inquiry_documents');
        $staff = User::factory()->create();
        $inquiry = Inquiry::factory()->create();
        $document = InquiryDocument::factory()->create(['inquiry_id' => $inquiry->id]);
        $payload = ['mode' => 'extract', 'staff' => $staff->id, 'inquiry' => $inquiry->id, 'document' => $document->id, 'document_root' => Storage::disk('inquiry_documents')->path('')];
        $results = $this->compete([$payload, $payload]);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('document_runs', 1);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'extraction')->count());
        $this->assertSame('queued', DocumentRun::firstOrFail()->state);
    }
}
