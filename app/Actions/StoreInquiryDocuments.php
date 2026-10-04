<?php

namespace App\Actions;

use App\Models\Inquiry;
use App\Support\Audit;
use App\Support\InquiryUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreInquiryDocuments
{
    public function handle(Inquiry $inquiry, array $files, string $classification, bool $public = false, array &$publicPaths = [], ?int $preparedFrom = null, ?string $preparedNote = null, ?string $systemActor = null): array
    {
        $stored = [];
        $duplicates = [];
        try {
            DB::transaction(function () use ($inquiry, $files, $classification, &$stored, &$duplicates, $public, &$publicPaths, $preparedFrom, $preparedNote, $systemActor): void {
                $record = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
                if ($preparedFrom && (! $record->documents()->whereKey($preparedFrom)->exists() || $public)) {
                    throw ValidationException::withMessages(['prepared_from_id' => 'Choose an original from this inquiry.']);
                }
                $count = $record->documents()->count();
                foreach ($files as $file) {
                    $type = InquiryUploads::type($file);
                    if (! $type) {
                        throw ValidationException::withMessages(['files' => 'The uploaded file content is not supported.']);
                    }
                    $checksum = hash_file('sha256', $file->getRealPath());
                    if ($record->documents()->where('checksum', $checksum)->exists()) {
                        $duplicates[] = InquiryUploads::safeName($file->getClientOriginalName());

                        continue;
                    }
                    if ($count >= config('inquiries.document_limit')) {
                        throw ValidationException::withMessages(['files' => 'This inquiry has reached its document limit, including archived evidence.']);
                    }
                    $path = $record->id.'/'.Str::uuid().'.'.$type['extension'];
                    Storage::disk('inquiry_documents')->putFileAs((string) $record->id, $file, basename($path));
                    $stored[] = $path;
                    if ($public || $systemActor) {
                        $publicPaths[] = $path;
                    }
                    $doc = $record->documents()->create(['prepared_from_id' => $preparedFrom, 'uploader_id' => ($public || $systemActor) ? null : auth()->id(), 'uploader_name' => $systemActor ?? ($public ? 'Public submission' : auth()->user()->name), 'provenance' => $systemActor ? 'mailbox_import' : ($public ? 'website_submission' : 'staff_upload'), 'scan_status' => 'unscanned', 'original_name' => InquiryUploads::safeName($file->getClientOriginalName()), 'storage_path' => $path, 'mime' => $type['mime'], 'size' => $file->getSize(), 'checksum' => $checksum, 'classification' => $classification]);
                    Audit::record('Private document uploaded', $doc, details: $preparedFrom ? ['prepared_copy' => ['before' => $preparedFrom, 'after' => $preparedNote]] : [], systemActor: $systemActor ?? ($public ? 'System / public submission' : null));
                    $count++;
                }
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('inquiry_documents')->delete($path);
            } throw $e;
        }

        return $duplicates;
    }
}
