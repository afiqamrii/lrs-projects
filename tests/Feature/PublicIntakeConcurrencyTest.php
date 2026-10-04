<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\PublicSubmission;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PublicIntakeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_processes_share_one_database_key_case_original_file_and_queued_receipt(): void
    {
        Storage::fake('inquiry_documents');
        $nonce = bin2hex(random_bytes(32));
        $token = bin2hex(random_bytes(32));
        DB::table('public_intake_keys')->insert(['token_hash' => hash('sha256', $token), 'session_hash' => hash_hmac('sha256', $nonce, config('app.key')), 'created_at' => now(), 'expires_at' => now()->addHour()]);
        CompanySetting::current()->update(['receipt_mail_enabled' => true]);
        $file = tempnam(sys_get_temp_dir(), 'lrs-intake-test-');
        file_put_contents($file, "%PDF-1.4\nConcurrency test packing list\n%%EOF");
        $database = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'QUEUE_CONNECTION' => 'database', 'PUBLIC_RECEIPT_MAILER' => 'smtp'];
        $payload = json_encode(['nonce' => $nonce, 'file' => $file, 'document_root' => Storage::disk('inquiry_documents')->path(''), 'data' => ['intake_token' => $token, 'contact' => ['name' => 'Concurrent QA', 'email' => 'concurrent@example.test'], 'shipment' => ['mode' => 'LCL', 'scope' => 'unknown', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore', 'cargo_description' => 'General cargo'], 'classification' => 'packing_list', 'privacy_acknowledged' => '1', 'privacy_version' => CompanySetting::current()->public_privacy_version]]);
        $processes = [
            new Process([PHP_BINARY, base_path('tests/Fixtures/submit-public-inquiry.php')], base_path(), $environment, $payload, 30),
            new Process([PHP_BINARY, base_path('tests/Fixtures/submit-public-inquiry.php')], base_path(), $environment, $payload, 30),
        ];
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $this->assertSame($processes[0]->getOutput(), $processes[1]->getOutput());
            $this->assertSame(1, Inquiry::count());
            $this->assertSame(1, PublicSubmission::count());
            $record = Inquiry::firstOrFail();
            $this->assertSame(1, $record->documents()->count());
            $this->assertSame(1, $record->mailboxVerifications()->count());
            $this->assertSame(1, DB::table('jobs')->count());
            $this->assertCount(1, Storage::disk('inquiry_documents')->allFiles());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            unlink($file);
        }
    }
}
