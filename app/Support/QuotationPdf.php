<?php

namespace App\Support;

use App\Models\ClientQuotationRevision;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class QuotationPdf
{
    public static function render(array $customer, bool $draft): string
    {
        $directory = storage_path('app/private/quotation-renderer');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'chroot' => resource_path('views/quotations'), 'tempDir' => $directory, 'fontCache' => $directory,
            'defaultFont' => 'DejaVu Sans', 'allowedProtocols' => ['file://', 'data://']]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('quotations.pdf', ['q' => $customer, 'draft' => $draft])->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->page_script(function (int $page, int $count, Canvas $canvas, FontMetrics $fonts) use ($customer): void {
            $regular = $fonts->getFont('DejaVu Sans', 'normal');
            $bold = $fonts->getFont('DejaVu Sans', 'bold');
            $canvas->filled_rectangle(42, 22, 30, 30, [0.03, 0.40, 0.90]);
            $canvas->text(50, 26, mb_strtoupper(mb_substr($customer['company']['name'], 0, 1)), $bold, 18, [1, 1, 1]);
            $canvas->text(86, 22, $customer['company']['name'], $bold, 11, [0.09, 0.12, 0.18]);
            $canvas->text(86, 43, $customer['company']['reply_email'], $regular, 8, [0.38, 0.42, 0.48]);
            $canvas->text(430, 22, 'QUOTATION', $regular, 8, [0.38, 0.42, 0.48]);
            $canvas->text(430, 38, $customer['reference'], $regular, 8, [0.09, 0.12, 0.18]);
            $canvas->text(430, 52, 'Revision '.$customer['revision'], $regular, 8, [0.09, 0.12, 0.18]);
            $canvas->line(42, 70, 553, 70, [0.86, 0.89, 0.93], 0.5);
        });
        $pdf->getCanvas()->page_text(42, 805, $customer['reference'].' / v'.$customer['revision'].'   •   Page {PAGE_NUM} of {PAGE_COUNT}', 'DejaVu Sans', 8, [0.38, 0.42, 0.48]);

        return $pdf->output();
    }

    public static function bytes(ClientQuotationRevision $r): string
    {
        $disk = Storage::disk('inquiry_documents');
        if (! $disk->exists($r->pdf_path)) {
            Processing::fail('The private quotation PDF is unavailable. Restore the exact file or create a newly reviewed revision.');
        }
        $bytes = $disk->get($r->pdf_path);
        if (strlen($bytes) !== $r->pdf_size || ! hash_equals($r->pdf_checksum, hash('sha256', $bytes))) {
            Processing::fail('The quotation PDF checksum changed. Release is blocked.');
        }

        return $bytes;
    }
}
