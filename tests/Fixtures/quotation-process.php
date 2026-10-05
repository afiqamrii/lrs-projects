<?php

use App\Actions\ManageQuotation;
use App\Models\ClientQuotationRevision;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new RuntimeException('Dedicated test database required');
}
config(['filesystems.disks.inquiry_documents.root' => getenv('QUOTATION_TEST_DISK_ROOT'), 'mailbox.demo_enabled' => true]);
$data = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$staff = User::findOrFail($data['staff_id']);
try {
    if ($data['operation'] === 'save') {
        $r = app(ManageQuotation::class)->save(Inquiry::findOrFail($data['inquiry_id']), $staff, $data['data']);
        echo json_encode(['state' => 'saved', 'id' => $r->id]);
    } else {
        $a = app(ManageQuotation::class)->approve(ClientQuotationRevision::findOrFail($data['revision_id']), $staff, $data['digest']);
        echo json_encode(['state' => 'approved', 'id' => $a->id]);
    }
} catch (ValidationException $e) {
    echo json_encode(['state' => 'stale_or_blocked']);
}
