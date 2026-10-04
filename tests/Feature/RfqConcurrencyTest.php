<?php

namespace Tests\Feature;

use App\Actions\ManageRfq;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\Rfq;
use App\Models\RfqApproval;
use App\Models\User;
use App\Models\Vendor;
use App\Support\InquiryWorkflow;
use App\Support\Processing;
use App\Support\RfqContent;
use App\Support\Shipment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RfqConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_processes_coalesce_vendor_selection_and_exact_approval_and_reject_newer_content(): void
    {
        $staff = User::factory()->create(['role' => 'agent']);
        $second = User::factory()->create(['role' => 'agent']);
        $this->actingAs($staff);
        CompanySetting::current()->update(['rfq_reply_name' => 'Synthetic sourcing', 'rfq_reply_email' => 'reply@example.test', 'rfq_signature' => 'Synthetic sourcing desk']);
        $client = Client::factory()->create();
        $contact = ClientContact::factory()->create(['client_id' => $client->id]);
        $case = Inquiry::factory()->create(['client_id' => $client->id, 'client_contact_id' => $contact->id, 'owner_id' => $staff->id, 'status' => 'needs_review', 'response_due_at' => now()->addDays(3), 'shipment' => Shipment::normalize(['mode' => 'FCL', 'scope' => 'port_to_port', 'cargo_description' => 'Synthetic general parts', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore', 'cargo_ready_date' => '2026-11-01', 'containers' => [['type' => '40HC', 'quantity' => 1, 'gross_weight' => '1200', 'weight_unit' => 'kg']]])]);
        $this->post(route('inquiries.transition', $case), ['target' => 'ready_for_sourcing', 'lock_version' => 0])->assertSessionHasNoErrors();
        $vendor = Vendor::factory()->create();
        $to = $vendor->contacts()->create(['name' => 'Synthetic desk', 'email' => 'synthetic@example.test', 'is_primary' => true]);
        $select = ['operation' => 'select', 'staff_id' => $staff->id, 'inquiry_id' => $case->id, 'vendor_ids' => [$vendor->id], 'lock_version' => $case->fresh()->lock_version];
        $outputs = $this->processes([$select, array_replace($select, ['staff_id' => $second->id])]);
        $this->assertSame($outputs[0]['id'], $outputs[1]['id']);
        $this->assertSame(1, Rfq::count());
        $rfq = Rfq::firstOrFail();
        $this->assertSame(1, $rfq->revisions()->count());
        $p = $rfq->current()->payload;
        $draft = ['expected_revision' => 1, 'to_contact_id' => $to->id, 'subject' => $p['subject'], 'opening' => $p['opening'], 'closing' => $p['closing'], 'response_due_at' => InquiryWorkflow::local(now()->addDay()), 'currency' => 'USD', 'attachments_reviewed' => true];
        app(ManageRfq::class)->save($rfq, $staff, $draft);
        $rfq = $rfq->fresh();
        $approve = ['operation' => 'approve', 'staff_id' => $staff->id, 'rfq_id' => $rfq->id, 'expected' => $rfq->current_number, 'digest' => Processing::hash(RfqContent::snapshot($rfq->current()))];
        $outputs = $this->processes([$approve, array_replace($approve, ['staff_id' => $second->id])]);
        $this->assertSame('approved', $outputs[0]['state']);
        $this->assertSame($outputs[0]['id'], $outputs[1]['id']);
        $this->assertSame(1, RfqApproval::count());
        $this->assertSame(1, AuditEntry::where('action', 'Exact RFQ revision approved')->count());
        $draft['expected_revision'] = $rfq->current_number;
        $draft['opening'] = 'New concurrent edit requires its own human review.';
        $outputs = $this->processes([['operation' => 'save', 'staff_id' => $second->id, 'rfq_id' => $rfq->id, 'draft' => $draft], $approve]);
        $this->assertSame('saved', $outputs[0]['state']);
        $this->assertContains($outputs[1]['state'], ['approved', 'stale_or_blocked']);
        $this->assertSame('draft', $rfq->fresh()->current()->status);
        $this->assertNull($rfq->fresh()->current()->approval);
        $this->assertSame(1, RfqApproval::count());
        $this->assertSame(3, $rfq->revisions()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function processes(array $payloads): array
    {
        $db = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'QUEUE_CONNECTION' => 'database'];
        $processes = array_map(fn (array $payload): Process => new Process([PHP_BINARY, base_path('tests/Fixtures/rfq-process.php')], base_path(), $env, json_encode($payload, JSON_THROW_ON_ERROR), 30), $payloads);
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            $outputs = [];
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
