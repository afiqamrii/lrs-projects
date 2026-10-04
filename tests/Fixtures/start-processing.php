<?php

use App\Actions\StartAiProposals;
use App\Actions\StartDocumentExtraction;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    exit(2);
}
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['ai.key' => 'synthetic-test-key', 'filesystems.disks.inquiry_documents.root' => $data['document_root'] ?? storage_path('app/private/inquiry-documents')]);
$staff = User::findOrFail($data['staff']);
auth()->login($staff);
$inquiry = Inquiry::findOrFail($data['inquiry']);
try {
    if ($data['mode'] === 'ai') {
        $run = $app->make(StartAiProposals::class)->handle($inquiry, $staff, $data['sources'], $data['shipment_hash'], $data['scope_hash']);
    } else {
        $run = $app->make(StartDocumentExtraction::class)->handle($inquiry, InquiryDocument::findOrFail($data['document']), $staff, ['pages' => [], 'ocr_pages' => []]);
    }
    echo json_encode(['state' => 'created', 'id' => $run->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['state' => 'blocked'], JSON_THROW_ON_ERROR);
}
