<?php

use App\Actions\ManageOffer;
use App\Models\Inquiry;
use App\Models\User;
use App\Models\VendorOffer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new RuntimeException('Dedicated test database required');
}
$data = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$staff = User::findOrFail($data['staff_id']);
try {
    if ($data['operation'] === 'save') {
        $r = app(ManageOffer::class)->save(VendorOffer::findOrFail($data['offer_id']), $staff, $data['data'], true);
        echo json_encode(['state' => 'saved', 'id' => $r->id]);
    } else {
        $s = app(ManageOffer::class)->select(Inquiry::findOrFail($data['inquiry_id']), $staff, $data['data']);
        echo json_encode(['state' => 'selected', 'id' => $s->id]);
    }
} catch (ValidationException $e) {
    echo json_encode(['state' => 'stale_or_blocked', 'errors' => array_keys($e->errors())]);
}
