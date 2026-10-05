<?php

namespace Tests\Feature;

use App\Actions\ManageLifecycle;
use App\Actions\ManageQuotation;
use App\Models\AiRun;
use App\Models\Inquiry;
use App\Models\MailMessage;
use App\Models\Rfq;
use App\Models\User;
use App\Support\OperationalReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\LifecycleFixture;
use Tests\Fixtures\QuotationFixture;
use Tests\TestCase;

class OperationalReportsTest extends TestCase
{
    use LifecycleFixture, QuotationFixture, RefreshDatabase;

    private function filters(array $extra = []): array
    {
        return app(OperationalReports::class)->filters(Request::create('/reports', 'GET', array_replace(['data' => 'samples', 'from' => now()->subDays(90)->format('Y-m-d'), 'to' => now()->addDays(90)->format('Y-m-d')], $extra)));
    }

    public function test_current_quotes_corrections_and_amounts_count_once_and_expiry_is_separate(): void
    {
        $q = $this->quote();
        $d = $this->accept($q);
        app(ManageLifecycle::class)->decision($q, $this->staff, $this->decisionInput(['expected_decision' => $d->id, 'corrects_id' => $d->id, 'outcome' => 'declined']));
        $reports = app(OperationalReports::class);
        $summary = $reports->summary($this->filters());
        $this->assertSame(1, $summary['totals']->quotations);
        $this->assertSame(0, $summary['totals']->accepted);
        $this->assertSame(1, $summary['totals']->declined);
        $this->assertSame('1300.00', $summary['money']->first()->cost);
        $this->assertSame('1560.00', $summary['money']->first()->selling);
        $this->assertSame('260.00', $summary['money']->first()->profit);
        $this->get(route('reports.index', ['data' => 'samples']))->assertOk()->assertSee('Approved quotation economics')->assertSee('Accepted ÷ (Accepted + Declined)');
        $new = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput(1));
        $summary = $reports->summary($this->filters());
        $this->assertSame(1, $summary['totals']->quotations);
        $this->assertSame(0, $summary['totals']->declined);
        $this->assertSame(1, $summary['totals']->pending);
        $this->assertCount(0, $summary['money']);
        $this->travelTo($new->expires_at->addSecond());
        $summary = $reports->summary($this->filters());
        $this->assertSame(1, $summary['totals']->expired);
        $this->assertSame(0, $summary['totals']->pending);
    }

    public function test_recorded_client_decisions_are_not_reclassified_when_the_quote_later_expires(): void
    {
        $quote = $this->quote();
        $this->accept($quote);
        $this->travelTo($quote->expires_at->addSecond());
        $summary = app(OperationalReports::class)->summary($this->filters());
        $this->assertSame(1, $summary['totals']->accepted);
        $this->assertSame(0, $summary['totals']->expired);
        $row = app(OperationalReports::class)->cases($this->filters())->where('i.id', $this->case->id)->first();
        $this->assertSame('Review reconfirmation and handoff prerequisites', OperationalReports::nextAction($row));
        $this->assertSame('1560.00', $summary['money']->first()->selling);
    }

    public function test_filters_date_boundaries_pagination_guest_inactive_and_csv_formula_protection(): void
    {
        $this->case->update(['title' => '  =HYPERLINK("https://untrusted.example")']);
        $filters = $this->filters(['owner' => $this->staff->id, 'status' => 'ready_for_sourcing', 'source' => 'email']);
        $reports = app(OperationalReports::class);
        $this->assertTrue($reports->cohort($filters)->exists());
        $csv = $this->get(route('reports.export', ['data' => 'samples']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        $this->assertStringContainsString("'  =HYPERLINK", $csv);
        foreach (["\t+cmd", "\n@SUM(1)", '-formula', "\u{FEFF}=1"] as $value) {
            $this->assertStringStartsWith("'", OperationalReports::csvCell($value));
        }
        $this->assertSame('1300.00', OperationalReports::csvCell('1300.00'));
        $this->get(route('reports.index', ['from' => '2020-01-01', 'to' => '2026-10-05']))->assertSessionHasErrors('from');
        $this->get(route('reports.index', ['owner' => 999999]))->assertSessionHasErrors('owner');
        $this->actingAs(User::factory()->create(['role' => 'agent']))->get(route('reports.index'))->assertOk();
        $this->actingAs(User::factory()->create(['is_active' => false]))->get(route('reports.export'))->assertRedirect(route('login'));
        $this->app['auth']->forgetGuards();
        $this->get(route('reports.export'))->assertRedirect(route('login'));
    }

    public function test_response_time_uses_exact_sent_revision_excludes_automated_duplicate_and_presend_evidence(): void
    {
        $r = Rfq::where('inquiry_id', $this->case->id)->firstOrFail();
        $revision = $r->current();
        $approval = $revision->approval;
        $this->assertNotNull($approval);
        DB::table('rfq_dispatches')->insert(['rfq_approval_id' => $approval->id, 'action_key' => fake()->uuid(), 'recorded_by' => $this->staff->id, 'actor_name' => $this->staff->name, 'sent_at' => now()->subHours(8), 'recorded_at' => now(), 'channel' => 'email', 'recipients' => json_encode(['to' => $approval->snapshot['to'], 'cc' => []]), 'evidence' => 'Explicit fictional external sending evidence']);
        foreach ([['out_of_office', 'matched', -7], ['bounce', 'matched', -6], ['quote', 'duplicate_copy', -5], ['quote', 'matched', -9], ['quote', 'unmatched', -4], ['quote', 'matched', -3]] as [$class, $match, $hours]) {
            MailMessage::factory()->create(['is_demo' => true, 'inquiry_id' => $this->case->id, 'rfq_revision_id' => $revision->id, 'classification' => $class, 'match_state' => $match, 'received_at' => now()->addHours($hours)]);
        }
        $row = app(OperationalReports::class)->requests($this->filters(['vendor' => $r->vendor_id]))->first();
        $this->assertEquals(5, $row->response_hours);
        $this->assertSame('Manually recorded send', $row->send_basis);
        $this->get(route('reports.index', ['data' => 'samples', 'tab' => 'rfqs']))->assertOk()->assertSee('Manually recorded send');
        $csv = $this->get(route('reports.export', ['data' => 'samples', 'tab' => 'rfqs']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Response hours', $csv);
        $this->assertStringContainsString('Manually recorded send', $csv);
    }

    public function test_ai_unknowns_fixture_costs_and_live_costs_stay_separate_and_export_is_bounded(): void
    {
        $priorUnknown = AiRun::where('is_demo', true)->whereNull('usage')->count();
        AiRun::factory()->create(['inquiry_id' => $this->case->id, 'is_demo' => true, 'estimated_cost' => null, 'reservation' => '0.02', 'cost_uncertain' => true]);
        AiRun::factory()->create(['inquiry_id' => $this->case->id, 'is_demo' => false, 'estimated_cost' => '0.01000001', 'usage' => ['input_tokens' => 123, 'output_tokens' => 45], 'reservation' => '0']);
        AiRun::factory()->create(['inquiry_id' => $this->case->id, 'is_demo' => true, 'estimated_cost' => null, 'usage' => ['input_tokens' => 5, 'output_tokens' => 9], 'cost_uncertain' => false]);
        $summary = app(OperationalReports::class)->summary($this->filters());
        $this->assertCount(2, $summary['ai']);
        $this->assertSame($priorUnknown + 1, $summary['ai']->firstWhere('is_demo', true)->unknown_usage);
        $this->assertSame('0.00000000', $summary['ai']->firstWhere('is_demo', true)->cost);
        $this->assertGreaterThanOrEqual(1, $summary['ai']->firstWhere('is_demo', true)->unknown_cost);
        $this->assertSame('0.01000001', $summary['ai']->firstWhere('is_demo', false)->cost);
        $this->get(route('reports.index', ['data' => 'samples', 'tab' => 'ai']))->assertOk()->assertSee('Unknown');
        $csv = $this->get(route('reports.export', ['data' => 'samples', 'tab' => 'ai']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Recorded input tokens', $csv);
        $this->assertStringContainsString('Recorded output tokens', $csv);
        $this->assertStringContainsString('123,45,0.01000001', $csv);
        $this->assertStringContainsString('Unknown estimate', $csv);
        config(['operations.export_limit' => 1]);
        $this->get(route('reports.export', ['data' => 'samples']))->assertSessionHasErrors('export');
        $real = Inquiry::factory()->create(['is_demo' => false, 'title' => 'Separate genuine intake']);
        $this->get(route('reports.index', ['data' => 'real']))->assertOk()->assertSee($real->title)->assertDontSee($this->case->title);
    }
}
