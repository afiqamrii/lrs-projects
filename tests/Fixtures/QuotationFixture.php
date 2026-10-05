<?php

namespace Tests\Fixtures;

use App\Actions\ManageOffer;
use App\Models\Inquiry;
use App\Models\MailboxConnection;
use App\Models\OfferSelection;
use App\Models\User;
use App\Models\VendorOffer;
use App\Support\Mailboxes;
use App\Support\OfferEligibility;
use App\Support\QuotationContent;
use App\Support\WorkspaceData;
use Database\Seeders\ProfessionalSampleSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

trait QuotationFixture
{
    protected User $staff;

    protected Inquiry $case;

    protected OfferSelection $selection;

    protected MailboxConnection $connection;

    protected function quotationFixture(): void
    {
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('inquiry_documents');
        Storage::fake('mailbox');
        config(['mailbox.demo_enabled' => true]);
        $this->staff = User::factory()->create(['role' => 'agent']);
        $this->actingAs($this->staff);
        app(ProfessionalSampleSeeder::class)->seed($this->staff);
        $this->case = Inquiry::where('sample_set', WorkspaceData::SAMPLE_SET)->where('title', 'like', 'Precision%')->firstOrFail();
        $offer = VendorOffer::where('inquiry_id', $this->case->id)->whereHas('revisions', fn ($q) => $q->where('complete_total', '1300'))->firstOrFail();
        $comparison = OfferEligibility::currentComparison($this->case);
        $this->selection = app(ManageOffer::class)->select($this->case, $this->staff, ['offer_revision_id' => $offer->current()->id, 'comparison_id' => $comparison->id, 'expected_selection' => 0, 'kind' => 'final', 'reason' => 'Fictional reviewed complete shipment and timing']);
        $this->connection = MailboxConnection::factory()->create(['is_demo' => true]);
        $this->connection->update(['identity_hash' => app(Mailboxes::class)->identity($this->connection)]);
    }

    protected function quotationInput(int $expected = 0): array
    {
        $p = QuotationContent::defaults($this->case, $this->selection);
        foreach ($this->selection->snapshot['calculation']['lines'] as $line) {
            if (! $line['optional'] && $line['state'] === 'priced') {
                $p['descriptions'][$line['key']] = $line['service'] === 'freight' ? 'Ocean freight service' : 'Warehouse delivery service';
                $p['vendor_tax_rates'][$line['key']] = '0';
            }
        }

        return array_replace($p, ['expected_revision' => $expected, 'change_reason' => 'Fictional quotation verification', 'intent' => 'review',
            'markup_percent' => '20', 'markup_confirmed' => true, 'vendor_tax_handling' => 'recoverable',
            'vendor_tax_evidence' => 'Explicit fictional assumption: vendor confirms no tax included; rate zero.',
            'customer_tax_treatment' => 'none', 'customer_tax_evidence' => 'Explicit fictional assumption: no customer tax applies.',
            'inclusions' => 'Ocean freight and delivery to the receiving warehouse.', 'exclusions' => 'Insurance and services outside the stated shipment.', 'conditions' => 'Fictional commercial terms: payment before release. Capacity and estimated dates require operational confirmation.', 'terms_confirmed' => true, 'internal_notes' => 'PRIVATE PROFIT NOTES DO NOT DISCLOSE']);
    }
}
