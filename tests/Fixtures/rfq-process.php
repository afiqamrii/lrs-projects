<?php

use App\Actions\ManageRfq;
use App\Actions\PrepareRfqs;
use App\Models\Inquiry;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new RuntimeException('Dedicated PostgreSQL test database required');
}
$data = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$staff = User::findOrFail($data['staff_id']);
try {
    if ($data['operation'] === 'select') {
        $case = Inquiry::findOrFail($data['inquiry_id']);
        $round = app(PrepareRfqs::class)->handle($case, $staff, $data['vendor_ids'], $data['lock_version']);
        echo json_encode(['state' => 'selected', 'id' => $round->id]);
    } elseif ($data['operation'] === 'approve') {
        $rfq = Rfq::findOrFail($data['rfq_id']);
        $approval = app(ManageRfq::class)->approve($rfq, $staff, $data['expected'], $data['digest']);
        echo json_encode(['state' => 'approved', 'id' => $approval->id]);
    } else {
        $revision = app(ManageRfq::class)->save(Rfq::findOrFail($data['rfq_id']), $staff, $data['draft']);
        echo json_encode(['state' => 'saved', 'id' => $revision->id]);
    }
} catch (ValidationException $e) {
    echo json_encode(['state' => 'stale_or_blocked', 'errors' => array_keys($e->errors())]);
}
