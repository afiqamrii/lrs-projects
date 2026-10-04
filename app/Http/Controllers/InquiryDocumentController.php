<?php

namespace App\Http\Controllers;

use App\Actions\StoreInquiryDocuments;
use App\Http\Requests\DocumentUploadRequest;
use App\Models\DocumentRun;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InquiryDocumentController extends Controller
{
    public function store(DocumentUploadRequest $request, Inquiry $inquiry, StoreInquiryDocuments $store): RedirectResponse
    {
        $duplicates = $store->handle($inquiry, $request->file('files'), $request->validated('classification'), preparedFrom: $request->validated('prepared_from_id'), preparedNote: $request->validated('prepared_note'));
        $response = to_route('inquiries.show', ['inquiry' => $inquiry, 'section' => 'documents'])->with('status', 'Documents saved. Originals are private; local extraction starts only when staff requests it.');

        return $duplicates ? $response->with('warning', 'Already present in this inquiry; no extra copy stored: '.implode(', ', $duplicates)) : $response;
    }

    public function show(Inquiry $inquiry, InquiryDocument $document): View
    {
        Gate::authorize('view', $inquiry);

        $processingState = DocumentRun::where('inquiry_id', $inquiry->id)->where('document_id', $document->id)->latest('id')->value('state');

        return view('inquiries.document', compact('inquiry', 'document', 'processingState'));
    }

    public function file(Inquiry $inquiry, InquiryDocument $document, Request $request): BinaryFileResponse
    {
        Gate::authorize('view', $inquiry);
        $download = $request->boolean('download');
        abort_unless($download || $document->previewable(), 404);
        $path = Storage::disk('inquiry_documents')->path($document->storage_path);
        abort_unless(is_file($path), 404, 'The original file is unavailable. Contact your administrator.');
        $headers = ['Content-Type' => $document->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"];

        return ($download ? response()->download($path, $document->original_name, $headers) : response()->file($path, $headers))->setPrivate();
    }

    public function update(Request $request, Inquiry $inquiry, InquiryDocument $document): RedirectResponse
    {
        Gate::authorize('update', $inquiry);
        $data = $request->validate(['classification' => ['required', Rule::in(array_keys(InquiryDocument::CLASSES))], 'is_archived' => ['required', 'boolean']]);
        DB::transaction(function () use ($inquiry, $document, $data): void {
            Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $record = $inquiry->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $before = Audit::snapshot($record);
            if ($record->classification !== $data['classification'] || $record->is_archived !== (bool) $data['is_archived']) {
                $data['version'] = $record->version + 1;
            }
            $record->update($data);
            Audit::record('Document classification / archive updated', $record, $before);
        });

        return back()->with('status', 'Document updated. The private original and history are preserved.');
    }
}
