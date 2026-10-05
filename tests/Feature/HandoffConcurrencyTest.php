<?php

namespace Tests\Feature;

use App\Actions\ManageLifecycle;
use App\Actions\ManageQuotation;
use App\Models\HandoffApproval;
use App\Models\HandoffRevision;
use App\Models\User;
use App\Models\VendorReconfirmation;
use App\Support\Processing;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class HandoffConcurrencyTest extends TestCase
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

    public function test_competing_handoffs_and_approvals_preserve_one_current_frozen_revision(): void
    {
        $this->quotationFixture();
        $a = app(ManageLifecycle::class);
        $q = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput());
        app(ManageQuotation::class)->approve($q, $this->staff, Processing::hash(app(ManageQuotation::class)->reviewSnapshot($q)));
        $d = $a->decision($q, $this->staff, ['action_key' => (string) Str::uuid(), 'expected_decision' => 0, 'outcome' => 'accepted', 'channel' => 'phone', 'decided_at' => now()->toIso8601String(), 'contact_id' => $this->case->client_contact_id, 'identity_confirmed' => true, 'scope_confirmed' => true, 'communicated_confirmed' => true, 'notes' => 'Fictional exact scope and terms accepted.']);
        $this->assertSame('accepted', $d->outcome);
        $r = VendorReconfirmation::where('client_decision_id', $d->id)->firstOrFail();
        $a->confirmation($r, $this->staff, ['action_key' => (string) Str::uuid(), 'expected_revision' => 0, 'status' => 'confirmed', 'channel' => 'phone', 'confirmed_at' => now()->toIso8601String(),
            'contact_id' => $r->selection->revision->offer->vendor->contacts()->where('is_active', true)->first()->id, 'identity_confirmed' => true, 'rate_total' => $r->selection->revision->complete_total, 'currency' => $r->selection->revision->currency,
            'rate_agreed' => true, 'scope_agreed' => true, 'capacity_confirmed' => true, 'dates_agreed' => true, 'available_date' => $this->case->shipment['cargo_ready_date'], 'arrival_date' => $this->case->shipment['arrival_date'], 'notes' => 'Fictional exact rate/capacity/date confirmation.']);
        $second = User::factory()->create(['role' => 'admin']);
        $a->policy($second, ['expected_revision' => 0, 'reason' => 'Isolated concurrency verification, explicit company prerequisites.']);
        $e = ['expected_revision' => 0, 'reason' => 'Frozen concurrency fixture', 'operations_owner_id' => $this->staff->id, 'cargo_ready_confirmed' => true, 'cargo_evidence' => 'Fictional customer agrees cargo available on exact date.', 'pickup_contact' => 'Supplier dispatch desk, +60 3 5550 0200', 'delivery_contact' => 'Receiving warehouse, +60 3 5550 0300'];
        $p = ['operation' => 'save', 'inquiry_id' => $this->case->id, 'staff_id' => $this->staff->id, 'data' => $e];
        $results = $this->processes([$p, array_replace($p, ['staff_id' => $second->id])]);
        $states = array_column($results, 'state');
        sort($states);
        $this->assertSame(['saved', 'stale_or_blocked'], $states);
        $this->assertSame(1, HandoffRevision::count());
        $h = HandoffRevision::firstOrFail();
        $p = ['operation' => 'approve', 'revision_id' => $h->id, 'staff_id' => $this->staff->id, 'digest' => $h->digest];
        $results = $this->processes([$p, array_replace($p, ['staff_id' => $second->id])]);
        $this->assertSame(['approved', 'approved'], array_column($results, 'state'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, HandoffApproval::count());
        $this->assertSame(2, count(Storage::disk('inquiry_documents')->allFiles('handoffs')));
    }

    private function processes(array $payloads): array
    {
        $db = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'QUEUE_CONNECTION' => 'database', 'QUOTATION_TEST_DISK_ROOT' => Storage::disk('inquiry_documents')->path('')];
        $processes = array_map(fn (array $p): Process => new Process([PHP_BINARY, base_path('tests/Fixtures/lifecycle-process.php')], base_path(), $env, json_encode($p, JSON_THROW_ON_ERROR), 60), $payloads);
        try {
            foreach ($processes as $process) {
                $process->start();
            }$results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $this->assertJson($process->getOutput(), 'Concurrent handoff worker must emit only its JSON result: '.substr($process->getOutput(), 0, 3000));
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
