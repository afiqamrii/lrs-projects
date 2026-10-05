<?php

namespace Tests\Feature;

use App\Actions\ManageOffer;
use App\Models\Inquiry;
use App\Models\OfferSelection;
use App\Models\User;
use App\Models\VendorOffer;
use App\Support\OfferEligibility;
use App\Support\WorkspaceData;
use Database\Seeders\ProfessionalSampleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OfferConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_review_and_selection_processes_never_overwrite_newer_commercial_evidence(): void
    {
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('inquiry_documents');
        $staff = User::factory()->create(['role' => 'admin']);
        $second = User::factory()->create(['role' => 'agent']);
        $this->actingAs($staff);
        app(ProfessionalSampleSeeder::class)->seed($staff);
        $case = Inquiry::where('sample_set', WorkspaceData::SAMPLE_SET)->where('title', 'like', 'Precision%')->firstOrFail();
        $original = VendorOffer::where('inquiry_id', $case->id)->whereHas('revisions', fn ($q) => $q->where('complete_total', '1150'))->firstOrFail();
        $offer = app(ManageOffer::class)->capture($case, $staff, ['rfq_revision_id' => $original->rfq_revision_id, 'alternative' => 'Concurrent reviewed fixture', 'source_kind' => 'manual', 'manual_text' => 'Fictional freight MYR 1050 plus required delivery MYR 100. Full total MYR 1150.', 'association_confirmed' => '1', 'association_reason' => 'Dedicated commercial concurrency fixture']);
        $p = $original->current()->payload;
        $p['expected_revision'] = 0;
        $p['change_reason'] = 'Review complete concurrency fixture';
        app(ManageOffer::class)->save($offer, $staff, $p, true);
        $p['expected_revision'] = 1;
        $payload = ['operation' => 'save', 'staff_id' => $staff->id, 'offer_id' => $offer->id, 'data' => $p];
        $outputs = $this->processes([$payload, array_replace($payload, ['staff_id' => $second->id])]);
        $states = array_column($outputs, 'state');
        sort($states);
        $this->assertSame(['saved', 'stale_or_blocked'], $states);
        $offer->refresh();
        $this->assertSame(2, $offer->current_number);
        $this->assertSame(2, $offer->revisions()->count());
        $comparison = OfferEligibility::currentComparison($case);
        $selection = ['operation' => 'select', 'staff_id' => $staff->id, 'inquiry_id' => $case->id, 'data' => ['offer_revision_id' => $offer->current()->id, 'comparison_id' => $comparison->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => 'Explicit exact reviewed complete cost basis']];
        $outputs = $this->processes([$selection, array_replace($selection, ['staff_id' => $second->id])]);
        $states = array_column($outputs, 'state');
        sort($states);
        $this->assertSame(['selected', 'stale_or_blocked'], $states);
        $this->assertSame(1, OfferSelection::where('inquiry_id', $case->id)->whereNull('superseded_at')->count());
        $s = OfferSelection::where('inquiry_id', $case->id)->firstOrFail();
        $this->assertSame([], OfferEligibility::selectionReasons($s));
    }

    private function processes(array $payloads): array
    {
        $db = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'QUEUE_CONNECTION' => 'database'];
        $processes = array_map(fn (array $p): Process => new Process([PHP_BINARY, base_path('tests/Fixtures/offer-process.php')], base_path(), $env, json_encode($p, JSON_THROW_ON_ERROR), 30), $payloads);
        try {
            foreach ($processes as $process) {
                $process->start();
            }$outputs = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $outputs[] = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            }

            return $outputs;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
