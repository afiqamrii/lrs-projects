<?php

namespace App\Http\Controllers;

use App\Actions\ReviewProposals;
use App\Actions\StartAiProposals;
use App\Actions\StartDocumentExtraction;
use App\Http\Requests\ExtractionRequest;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\DocumentRun;
use App\Models\Inquiry;
use App\Models\ProposalReview;
use App\Support\AiSources;
use App\Support\AiUsage;
use App\Support\Processing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExtractionController extends Controller
{
    public function show(Request $request, Inquiry $inquiry): View
    {
        Gate::authorize('view', $inquiry);
        $data = $request->validate(['run' => ['nullable', 'integer'], 'source' => ['nullable', 'string', 'max:200'], 'view' => ['nullable', 'in:sources'], 'quote' => ['nullable', 'string', 'max:16000'], 'evidence' => ['nullable', 'regex:/^\\d+:\\d+$/']]);
        $runs = AiRun::where('inquiry_id', $inquiry->id)->where('purpose', 'shipment_proposals')->latest('id')->get();
        $run = ($data['view'] ?? null) === 'sources' ? null : (isset($data['run']) ? $runs->firstWhere('id', (int) $data['run']) : $runs->first());
        if (isset($data['run']) && ! $run) {
            abort(404);
        }
        $catalogue = $run?->sources ?? AiSources::catalogue($inquiry);
        if (isset($data['evidence'])) {
            abort_unless($run, 404);
            [$candidateId, $evidenceIndex] = explode(':', $data['evidence']);
            $candidate = collect($run->proposals ?? [])->firstWhere('id', $candidateId);
            $reference = $candidate['evidence'][(int) $evidenceIndex] ?? null;
            abort_unless($reference, 404);
            $data['source'] = $reference['source_id'];
            $data['quote'] = $reference['quote'];
        }
        $source = isset($data['source']) ? collect($catalogue)->firstWhere('id', $data['source']) : ($catalogue[0] ?? null);
        abort_if(isset($data['source']) && ! $source, 404);
        $pageAvailable = false;
        if ($source && ($source['lineage']['kind'] ?? null) === 'document' && isset($source['metadata']['page'])) {
            $extraction = DocumentRun::where('inquiry_id', $inquiry->id)->whereKey($source['lineage']['run_id'])->first();
            $pageAvailable = $extraction && ! empty(collect($extraction->pages)->firstWhere('page', $source['metadata']['page'])['image']);
        }

        return view('inquiries.extraction', ['inquiry' => $inquiry, 'documents' => $inquiry->documents()->get(), 'documentRuns' => DocumentRun::where('inquiry_id', $inquiry->id)->with('document')->latest('id')->get(), 'runs' => $runs, 'run' => $run, 'catalogue' => $catalogue, 'source' => $source, 'liveReasons' => AiUsage::unavailable(AiSetting::current()), 'aiSettings' => AiSetting::current(), 'preview' => null, 'quote' => $data['quote'] ?? null, 'pageAvailable' => $pageAvailable]);
    }

    public function extract(ExtractionRequest $request, Inquiry $inquiry, StartDocumentExtraction $start): RedirectResponse
    {
        $data = $request->validated();
        $documents = $inquiry->documents()->whereIn('id', $data['document_ids'])->get();
        abort_unless($documents->count() === count($data['document_ids']), 404);
        $pages = $request->pages('pages');
        $ocr = $request->pages('ocr_pages');
        if (array_diff($ocr, $pages) && $pages !== []) {
            Processing::fail('Forced OCR pages must be included in the selected page range.');
        }
        foreach ($documents as $document) {
            $start->handle($inquiry, $document, $request->user(), ['pages' => $pages, 'ocr_pages' => $ocr], $request->boolean('reprocess'), $data['reason'] ?? null);
        }

        return to_route('inquiries.extraction', $inquiry)->with('status', 'Local extraction queued or reused. Refresh for progress. Originals and working shipment fields are preserved.');
    }

    public function scope(Request $request, Inquiry $inquiry): View
    {
        Gate::authorize('update', $inquiry);
        $data = $request->validate(['sources' => ['required', 'array', 'min:1', 'max:200'], 'sources.*' => ['required', 'string', 'max:200', 'distinct']]);
        $sources = AiSources::selected($inquiry, $data['sources']);
        $settings = AiSetting::current();
        $scopeHash = AiUsage::scope($sources, $settings);

        return view('inquiries.ai-scope', ['inquiry' => $inquiry, 'sources' => $sources, 'settings' => $settings, 'scopeHash' => $scopeHash, 'estimate' => AiUsage::estimate($sources, $settings), 'reasons' => AiUsage::unavailable($settings), 'input' => AiSources::input($sources), 'bound' => AiUsage::bound($sources)]);
    }

    public function requestProposals(Request $request, Inquiry $inquiry, StartAiProposals $start): RedirectResponse
    {
        $data = $request->validate(['sources' => ['required', 'array', 'max:200'], 'sources.*' => ['required', 'string', 'max:200'], 'shipment_hash' => ['required', 'string', 'size:64'], 'scope_hash' => ['required', 'string', 'size:64'], 'authorize_paid' => ['accepted'], 'retry' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:1000', 'required_if:retry,1']]);
        $run = $start->handle($inquiry, $request->user(), $data['sources'], $data['shipment_hash'], $data['scope_hash'], $request->boolean('retry'), $data['reason'] ?? null);

        return to_route('inquiries.extraction', ['inquiry' => $inquiry, 'run' => $run])->with('status', 'Proposal request queued or an identical existing run reused. Review the source evidence when processing completes.');
    }

    public function preview(Request $request, Inquiry $inquiry, AiRun $run, ReviewProposals $review): View
    {
        $data = $request->validate(['decisions' => ['required', 'array', 'max:60'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $preview = $review->preview($inquiry, $run, $request->user(), $data['decisions'], (int) $data['lock_version']);
        $key = (string) Str::uuid();
        $request->session()->put('proposal-previews.'.$key, ['inquiry_id' => $inquiry->id, 'run_id' => $run->id, 'preview' => $preview, 'created_at' => now()->timestamp]);

        return view('inquiries.proposal-preview', compact('inquiry', 'run', 'preview', 'key'));
    }

    public function apply(Request $request, Inquiry $inquiry, AiRun $run, ReviewProposals $review): RedirectResponse
    {
        Gate::authorize('update', $inquiry);
        abort_unless($run->inquiry_id === $inquiry->id, 404);
        $data = $request->validate(['action_key' => ['required', 'uuid'], 'ack_material_effects' => ['accepted']]);
        $stored = $request->session()->get('proposal-previews.'.$data['action_key']);
        $existing = ProposalReview::where('action_key', $data['action_key'])->where('ai_run_id', $run->id)->first();
        if ($existing) {
            return to_route('inquiries.extraction', ['inquiry' => $inquiry, 'run' => $run])->with('status', 'This review was already applied once. No duplicate change was made.');
        }
        if (! $stored || $stored['inquiry_id'] !== $inquiry->id || $stored['run_id'] !== $run->id || $stored['created_at'] < now()->subMinutes(30)->timestamp) {
            Processing::fail('This change preview expired. Preview your decisions again before applying them.');
        }
        $result = $review->apply($inquiry, $run, $request->user(), $stored['preview'], $data['action_key']);
        $request->session()->forget('proposal-previews.'.$data['action_key']);

        return to_route('inquiries.extraction', ['inquiry' => $inquiry, 'run' => $run])->with('status', 'Review recorded for shipment revision '.$result->resulting_revision.'. Use the normal shipment review to confirm readiness.');
    }

    public function page(Inquiry $inquiry, DocumentRun $documentRun, int $page): BinaryFileResponse
    {
        Gate::authorize('view', $inquiry);
        abort_unless($documentRun->inquiry_id === $inquiry->id, 404);
        $source = collect($documentRun->pages)->firstWhere('page', $page);
        abort_unless($source && isset($source['image']), 404);
        $disk = Storage::disk('extraction');
        abort_unless($disk->exists($source['image']), 404);

        return response()->file($disk->path($source['image']), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
