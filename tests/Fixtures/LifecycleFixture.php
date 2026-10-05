<?php

namespace Tests\Fixtures;

use App\Actions\ManageLifecycle;
use App\Actions\ManageQuotation;
use App\Models\ClientDecision;
use App\Models\ClientQuotationRevision;
use App\Models\User;
use App\Models\VendorReconfirmation;
use App\Support\Processing;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait LifecycleFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->quotationFixture();
    }

    private function quote(): ClientQuotationRevision
    {
        $q = app(ManageQuotation::class)->save($this->case, $this->staff, $this->quotationInput());
        app(ManageQuotation::class)->approve($q, $this->staff, Processing::hash(app(ManageQuotation::class)->reviewSnapshot($q)));
        $this->travel(2)->seconds();

        return $q->fresh();
    }

    private function decisionInput(array $extra = []): array
    {
        return array_replace(['action_key' => (string) Str::uuid(), 'expected_decision' => 0, 'outcome' => 'accepted', 'channel' => 'phone', 'decided_at' => now()->toIso8601String(), 'contact_id' => $this->case->client_contact_id,
            'identity_confirmed' => true, 'scope_confirmed' => true, 'communicated_confirmed' => true, 'notes' => 'Fictional client confirmed this exact quotation, total and terms by recorded phone conversation.'], $extra);
    }

    private function accept(ClientQuotationRevision $q): ClientDecision
    {
        return app(ManageLifecycle::class)->decision($q, $this->staff, $this->decisionInput());
    }

    private function vendorInput(VendorReconfirmation $r, array $extra = []): array
    {
        return array_replace(['action_key' => (string) Str::uuid(), 'expected_revision' => $r->fresh()->current_number, 'status' => 'confirmed', 'channel' => 'phone', 'confirmed_at' => now()->toIso8601String(),
            'contact_id' => $r->selection->revision->offer->vendor->contacts()->where('is_active', true)->first()->id, 'identity_confirmed' => true,
            'rate_total' => $r->selection->revision->complete_total, 'currency' => $r->selection->revision->currency, 'rate_agreed' => true, 'scope_agreed' => true, 'capacity_confirmed' => true, 'dates_agreed' => true,
            'available_date' => $this->case->shipment['cargo_ready_date'], 'arrival_date' => $this->case->shipment['arrival_date'], 'notes' => 'Fictional vendor confirms complete selected rate, scope, dates and actual capacity.'], $extra);
    }

    private function policy(array $extra = []): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        app(ManageLifecycle::class)->policy($admin, array_replace(['expected_revision' => 0, 'reason' => 'Explicit simulated company workflow: no PO/deposit/customs requirement.'], $extra));
    }

    private function ready(array $policy = []): array
    {
        $q = $this->quote();
        $d = $this->accept($q);
        $r = VendorReconfirmation::where('client_decision_id', $d->id)->firstOrFail();
        $this->travel(2)->seconds();
        app(ManageLifecycle::class)->confirmation($r, $this->staff, $this->vendorInput($r));
        $this->policy($policy);
        $e = ['expected_revision' => 0, 'operations_owner_id' => $this->staff->id, 'pickup_contact' => 'Fictional supplier receiving desk · +60 3 5550 0200', 'delivery_contact' => 'Fictional warehouse manager · +60 3 5550 0300',
            'cargo_ready_confirmed' => true, 'cargo_evidence' => 'Client confirms cargo packed and available on the agreed date.', 'reason' => 'Review exact fictional operations readiness.'];
        $h = app(ManageLifecycle::class)->saveHandoff($this->case, $this->staff, $e);

        return [$q, $d, $r, $h, $e];
    }

    private function blocked(callable $action, string $fragment): void
    {
        try {
            $action();
            $this->fail('Expected a blocked action');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($fragment, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }
}
