<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HandoffPdf
{
    public static function store(array $snapshot, bool $draft): array
    {
        $directory = storage_path('app/private/handoff-renderer');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'chroot' => resource_path('views/lifecycle'), 'tempDir' => $directory, 'fontCache' => $directory, 'defaultFont' => 'DejaVu Sans', 'allowedProtocols' => ['file://', 'data://']]));
        $pdf->loadHtml(view('lifecycle.pdf', ['s' => $snapshot, 'draft' => $draft])->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->page_text(40, 806, 'PRIVATE INTERNAL HANDOFF • Page {PAGE_NUM} of {PAGE_COUNT}', 'DejaVu Sans', 8, [.35, .39, .45]);
        $bytes = $pdf->output();
        $path = 'handoffs/'.$snapshot['inquiry_id'].'/'.Str::uuid().'.pdf';
        if (! Storage::disk('inquiry_documents')->put($path, $bytes)) {
            Processing::fail('The private handoff PDF could not be saved. Retry after private storage is available.');
        }

        return ['pdf_path' => $path, 'pdf_checksum' => hash('sha256', $bytes), 'pdf_size' => strlen($bytes)];
    }

    public static function bytes(object $record): string
    {
        $disk = Storage::disk('inquiry_documents');
        if (! $disk->exists($record->pdf_path)) {
            Processing::fail('Private handoff PDF is missing. Restore the exact artifact or prepare a new handoff revision.');
        }
        $bytes = $disk->get($record->pdf_path);
        if (strlen($bytes) !== $record->pdf_size || ! hash_equals($record->pdf_checksum, hash('sha256', $bytes))) {
            Processing::fail('Private handoff PDF checksum changed.');
        }

        return $bytes;
    }
}
