<?php

namespace App\Console\Commands;

use App\Actions\SaveClient;
use App\Actions\SaveInquiry;
use App\Actions\StartDocumentExtraction;
use App\Actions\StoreInquiryDocuments;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\User;
use App\Support\InquiryWorkflow;
use App\Support\SyntheticProposals;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

class SeedExtractionDemo extends Command
{
    protected $signature = 'lrs:demo-extraction {--extract : Queue real local extraction of synthetic files} {--proposals : Create labeled synthetic proposals after extraction}';

    protected $description = 'Prepare the explicitly labeled local synthetic Phase 3B acceptance case; never calls a paid provider';

    public function handle(SaveClient $clients, SaveInquiry $inquiries, StoreInquiryDocuments $documents, StartDocumentExtraction $extraction, SyntheticProposals $proposals): int
    {
        if (! app()->environment('local', 'testing') || ! config('extraction.demo')) {
            $this->error('Enable EXTRACTION_DEMO_ENABLED only in local/testing to prepare this synthetic fixture.');

            return self::FAILURE;
        }
        $staff = User::where('is_active', true)->orderBy('id')->first();
        if (! $staff) {
            $this->error('Create active staff first.');

            return self::FAILURE;
        }
        auth()->login($staff);
        $client = Client::where('reference_identifier', 'DEMO-PHASE-3B')->first() ?? $clients->handle(['company_name' => 'Synthetic Evidence Manufacturing (Demo)', 'reference_identifier' => 'DEMO-PHASE-3B', 'internal_notes' => 'Fictional Phase 3B acceptance only.', 'contact_name' => 'Synthetic Demo Desk', 'contact_email' => 'phase3b-demo@example.test']);
        $inquiry = Inquiry::where('client_id', $client->id)->where('title', 'Evidence review (Synthetic Demo Phase 3B)')->first() ?? $inquiries->handle(['client_id' => $client->id, 'client_contact_id' => $client->primaryContact?->id, 'title' => 'Evidence review (Synthetic Demo Phase 3B)', 'owner_id' => $staff->id, 'priority' => 'normal', 'received_at' => InquiryWorkflow::local(now()), 'response_due_at' => InquiryWorkflow::local(now()->addDays(2)), 'source_channel' => 'other', 'original_source_text' => 'SYNTHETIC DEMO ONLY. General machine parts from Port Klang, Malaysia to Singapore port. Compare packing-list quantities with the contradictory Word table. No real client request or communication occurred.', 'shipment' => []]);
        $inquiry->update(['is_demo' => true]);
        foreach (['digital-packing-list.pdf', 'scanned-packing-list.pdf', 'mixed-packing-list.pdf', 'packing-list.png', 'packing-list.jpg', 'shipment.docx', 'shipment.xlsx', 'shipment.csv', 'malformed.pdf'] as $name) {
            $path = base_path('tests/Fixtures/extraction/'.$name);
            $documents->handle($inquiry, [new UploadedFile($path, $name, test: true)], $name === 'shipment.docx' ? 'client_rfq' : ($name === 'malformed.pdf' ? 'other' : 'packing_list'));
        }
        if ($this->option('extract')) {
            foreach ($inquiry->documents()->get() as $document) {
                $extraction->handle($inquiry, $document, $staff, ['pages' => [], 'ocr_pages' => []]);
            }
            $this->info('Real local extraction queued. Run the extraction worker; original shipment fields are unchanged.');
        }
        if ($this->option('proposals')) {
            $run = $proposals->create($inquiry, $staff);
            $this->info('Synthetic proposal run '.$run->id.' prepared. No provider request or provider usage is claimed.');
        }
        $this->info($inquiry->reference.' · '.route('inquiries.extraction', $inquiry));

        return self::SUCCESS;
    }
}
