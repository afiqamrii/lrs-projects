<?php

use App\Actions\SubmitPublicInquiry;
use App\Http\Requests\PublicInquiryRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['filesystems.disks.inquiry_documents.root' => $input['document_root']]);
$session = new Store('concurrency-test', new ArraySessionHandler(120));
$session->put('public_intake_nonce', $input['nonce']);
$request = PublicInquiryRequest::create('/request-quote', 'POST', $input['data'], [], ['files' => [new UploadedFile($input['file'], 'concurrency-packing.pdf', null, null, true)]]);
$request->setContainer($app);
$request->setRedirector($app['redirect']);
$request->setLaravelSession($session);
$request->setUserResolver(fn () => null);
$request->validateResolved();
$inquiry = $app->make(SubmitPublicInquiry::class)->handle($request);
echo $inquiry->id;
