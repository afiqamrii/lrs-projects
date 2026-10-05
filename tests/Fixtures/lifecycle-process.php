<?php

use App\Actions\ManageLifecycle;
use App\Models\HandoffRevision;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new RuntimeException('Dedicated PostgreSQL test database required.');
}
config(['filesystems.disks.inquiry_documents.root' => getenv('QUOTATION_TEST_DISK_ROOT'), 'mailbox.demo_enabled' => true]);
$d = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$staff = User::findOrFail($d['staff_id']);
try {
    if ($d['operation'] === 'approve') {
        $a = app(ManageLifecycle::class)->approveHandoff(HandoffRevision::findOrFail($d['revision_id']), $staff, $d['digest']);
        echo json_encode(['state' => 'approved', 'id' => $a->id]);
    } else {
        $r = app(ManageLifecycle::class)->saveHandoff(Inquiry::findOrFail($d['inquiry_id']), $staff, $d['data']);
        echo json_encode(['state' => 'saved', 'id' => $r->id]);
    }
} catch (ValidationException $e) {
    echo json_encode(['state' => 'stale_or_blocked']);
}
