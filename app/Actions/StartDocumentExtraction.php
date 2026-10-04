<?php

namespace App\Actions;

use App\Jobs\ExtractDocument;
use App\Models\DocumentRun;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\User;
use App\Support\ExtractionTools;
use App\Support\Processing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StartDocumentExtraction
{
    public function __construct(private ExtractionTools $tools) {}

    public function handle(Inquiry $inquiry, InquiryDocument $document, User $staff, array $selection, bool $reprocess = false, ?string $reason = null): DocumentRun
    {
        Gate::forUser($staff)->authorize('update', $inquiry);
        abort_unless($document->inquiry_id === $inquiry->id, 404);
        $configuration = $this->tools->profile();
        sort($selection['pages']);
        sort($selection['ocr_pages']);
        $identity = Processing::hash(['document' => $document->id, 'checksum' => $document->checksum, 'selection' => $selection, 'configuration' => $configuration]);

        return DB::transaction(function () use ($inquiry, $document, $staff, $selection, $reprocess, $reason, $configuration, $identity): DocumentRun {
            Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $original = $inquiry->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($original->is_archived) {
                Processing::fail('Unarchive this source before processing it.');
            }
            $previous = DocumentRun::where('inquiry_id', $inquiry->id)->where('identity', $identity)->latest('generation')->first();
            if ($previous && (in_array($previous->state, ['queued', 'processing'], true) || (! $reprocess && in_array($previous->state, Processing::DOCUMENT_SUCCESS, true)))) {
                return $previous;
            }
            if ($previous && (! $reprocess || ! $reason)) {
                Processing::fail('Use Reprocess with a reason to preserve the earlier attempt and start a new local run.');
            }
            $run = DocumentRun::create([
                'inquiry_id' => $inquiry->id, 'document_id' => $document->id, 'requested_by' => $staff->id,
                'identity' => $identity, 'generation' => ($previous?->generation ?? 0) + 1, 'checksum' => $document->checksum,
                'configuration' => $configuration, 'selection' => $selection, 'state' => 'queued', 'retry_reason' => $reason,
            ]);
            ExtractDocument::dispatch($run->id)->onQueue('extraction')->afterCommit();

            return $run;
        });
    }
}
