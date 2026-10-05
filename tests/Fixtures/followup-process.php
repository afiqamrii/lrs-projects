<?php

use App\Actions\ManageFollowups;
use App\Jobs\DispatchMail;
use App\Models\FollowupPlan;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new RuntimeException('Dedicated test database required');
}
config(['filesystems.disks.inquiry_documents.root' => getenv('FOLLOWUP_TEST_DISK_ROOT'), 'filesystems.disks.mailbox.root' => getenv('FOLLOWUP_TEST_MAIL_ROOT'), 'mailbox.demo_enabled' => true]);
$data = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
try {
    if ($data['operation'] === 'dispatch') {
        (new DispatchMail($data['dispatch_id']))->handle();
    } else {
        app(ManageFollowups::class)->tick(FollowupPlan::findOrFail($data['plan_id']));
    }
    echo json_encode(['state' => 'checked']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
