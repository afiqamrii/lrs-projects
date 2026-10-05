<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\MailboxConnection;
use App\Models\MailMessage;
use App\Models\Rfq;
use App\Models\RfqRevision;
use App\Models\ShipmentVersion;
use App\Models\SourcingRound;
use App\Models\User;
use App\Support\OperationalReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_and_inbox_queries_at_fictional_volume_are_paginated_set_based_and_measured(): void
    {
        $staff = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create();
        $base = Inquiry::factory()->make(['client_id' => $client->id, 'owner_id' => $staff->id, 'is_demo' => true])->getAttributes();
        $batch = [];
        for ($n = 1; $n <= 12000; $n++) {
            $batch[] = array_replace($base, ['reference' => 'PERF-FICTIONAL-'.$n, 'title' => 'Fictional volume inquiry '.$n,
                'source_channel' => $n % 2 ? 'website' : 'email', 'status' => $n % 3 ? 'needs_review' : 'closed',
                'received_at' => now()->subDays($n % 120)->toDateTimeString(), 'created_at' => now(), 'updated_at' => now()]);
            if (count($batch) === 250) {
                DB::table('inquiries')->insert($batch);
                $batch = [];
            }
        }
        $inquiry = Inquiry::firstOrFail();
        $version = ShipmentVersion::factory()->create(['inquiry_id' => $inquiry->id, 'reviewer_id' => $staff->id]);
        $round = SourcingRound::factory()->create(['shipment_version_id' => $version->id, 'created_by' => $staff->id]);
        $mailbox = MailboxConnection::factory()->create(['is_demo' => true]);
        $revisionIds = [];
        for ($n = 0; $n < 40; $n++) {
            $rfq = Rfq::factory()->create(['sourcing_round_id' => $round->id]);
            $revisionIds[] = RfqRevision::factory()->create(['rfq_id' => $rfq->id, 'created_by' => $staff->id, 'payload' => []])->id;
        }
        $mailBase = MailMessage::factory()->make(['is_demo' => true, 'mailbox_connection_id' => $mailbox->id, 'inquiry_id' => $inquiry->id, 'match_state' => 'matched'])->getAttributes();
        for ($n = 0; $n < 1600; $n++) {
            $batch[] = array_replace($mailBase, ['provider_id' => 'fictional-volume-mail-'.$n, 'rfq_revision_id' => $revisionIds[$n % 40], 'created_at' => now(), 'updated_at' => now()]);
            if (count($batch) === 200) {
                DB::table('mail_messages')->insert($batch);
                $batch = [];
            }
        }
        DB::statement('ANALYZE inquiries');
        DB::statement('ANALYZE mail_messages');
        $reports = app(OperationalReports::class);
        $filters = $reports->filters(Request::create('/reports', 'GET', ['data' => 'samples', 'from' => now()->subDays(29)->format('Y-m-d'), 'to' => now()->format('Y-m-d')]));
        $query = $reports->cases($filters)->orderByDesc('i.id')->limit(25);
        $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->toSql(), $query->getBindings())->{'QUERY PLAN'}, true);
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $this->actingAs($staff)->get(route('reports.index', ['data' => 'samples', 'from' => $filters['from'], 'to' => $filters['to']]))->assertOk();
        $ms = (hrtime(true) - $started) / 1000000;
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $response->assertSee('Page 1 of 120')->assertSee('3000');
        $this->assertLessThan(25, $count, 'Report SQL count must stay bounded rather than querying separately for each row.');
        $this->assertLessThan(10000, $ms, 'Detect an operationally unusable local report regression; this is not a production SLA.');
        $this->assertCount(25, $query->get());
        $this->assertSame(3000, $reports->cohort($filters)->count());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $mailStarted = hrtime(true);
        $this->get(route('mail.index', ['data' => 'fixtures']))->assertOk()->assertSee('Page 1 of 80');
        $mailMs = (hrtime(true) - $mailStarted) / 1000000;
        $mailQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(25, $mailQueries, 'Mail relationships must be eager loaded, including pinned mailbox and earlier RFQ lineage.');
        $this->assertLessThan(10000, $mailMs);
        file_put_contents(base_path('.tools/phase11-performance.json'), json_encode(['fictional_inquiries' => 12000, 'cohort' => 3000, 'page_rows' => 25, 'http_ms' => round($ms, 2), 'sql_queries' => $count, 'fictional_incoming_messages' => 1600, 'distinct_rfq_revisions' => 40, 'inbox_http_ms' => round($mailMs, 2), 'inbox_sql_queries' => $mailQueries, 'plan' => $plan], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
