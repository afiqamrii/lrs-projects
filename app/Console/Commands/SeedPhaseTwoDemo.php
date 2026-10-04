<?php

namespace App\Console\Commands;

use App\Actions\SaveClient;
use App\Actions\SaveInquiry;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\User;
use App\Support\InquiryWorkflow;
use Illuminate\Console\Command;

class SeedPhaseTwoDemo extends Command
{
    protected $signature = 'lrs:demo-inquiries';

    protected $description = 'Explicitly add fictional clients and draft inquiries in local/testing only';

    public function handle(SaveClient $clients, SaveInquiry $inquiries): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Demo data is available only in local/testing environments.');

            return self::FAILURE;
        }
        $owner = User::where('is_active', true)->orderBy('id')->first();
        if (! $owner) {
            $this->error('Create an active staff account first with lrs:admin.');

            return self::FAILURE;
        }
        $client = Client::where('reference_identifier', 'DEMO-PHASE-2')->first() ?? $clients->handle(['company_name' => 'Harbour Manufacturing (Demo)', 'reference_identifier' => 'DEMO-PHASE-2', 'internal_notes' => 'Fictional local example, explicitly requested through lrs:demo-inquiries.', 'contact_name' => 'Demo Client Desk', 'contact_email' => 'phase2-demo@example.test']);
        foreach (['LCL', 'FCL'] as $mode) {
            $title = $mode.' shipment (Demo Phase 2)';
            $existing = Inquiry::where('client_id', $client->id)->where('title', $title)->first();
            if ($existing) {
                $existing->update(['is_demo' => true]);

                continue;
            }
            $inquiry = $inquiries->handle(['client_id' => $client->id, 'client_contact_id' => $client->primaryContact?->id, 'title' => $title, 'owner_id' => $owner->id, 'priority' => 'normal', 'received_at' => InquiryWorkflow::local(now()), 'response_due_at' => InquiryWorkflow::local(now()->addDays(2)), 'source_channel' => 'other', 'original_source_text' => 'Fictional example only. No real request or communication occurred.', 'shipment' => ['mode' => $mode, 'scope' => 'unknown', 'cargo_description' => 'General cargo (Demo)']]);
            $inquiry->update(['is_demo' => true]);
        }
        $this->info('Fictional client and draft inquiries added. No communication or confirmation occurred.');

        return self::SUCCESS;
    }
}
