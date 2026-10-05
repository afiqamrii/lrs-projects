<?php

namespace App\Http\Controllers;

use App\Actions\ManageQuotation;
use App\Http\Requests\QuotationRequest;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationRevision;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\OfferSelection;
use App\Support\Audit;
use App\Support\Processing;
use App\Support\QuotationContent;
use App\Support\QuotationEligibility;
use App\Support\QuotationPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class QuotationController extends Controller
{
    private function case(Inquiry $inquiry): void
    {
        Gate::authorize('view', $inquiry);
        Gate::authorize('update', $inquiry);
    }

    private function revision(Inquiry $inquiry, ClientQuotationRevision $revision): void
    {
        $this->case($inquiry);
        abort_unless($revision->quotation->inquiry_id === $inquiry->id, 404);
    }

    public function index(Request $request, Inquiry $inquiry): View
    {
        $this->case($inquiry);
        $quote = ClientQuotation::where('inquiry_id', $inquiry->id)->first();
        $r = $quote?->current();
        $selection = $r?->selection ?? OfferSelection::where('inquiry_id', $inquiry->id)->whereNull('superseded_at')->first();
        $p = $r?->payload ?? ($selection ? QuotationContent::defaults($inquiry, $selection) : null);

        return view('quotations.edit', ['company' => CompanySetting::current(), 'inquiry' => $inquiry, 'quote' => $quote, 'revision' => $r, 'selection' => $selection, 'p' => $p,
            'selections' => OfferSelection::where('inquiry_id', $inquiry->id)->latest('id')->get(),
            'reasons' => $r ? QuotationEligibility::reasons($r) : [],
            'contacts' => $inquiry->client?->contacts()->where('is_active', true)->get() ?? collect(),
            'dispatches' => MailDispatch::whereHas('envelope', fn ($q) => $q->where('inquiry_id', $inquiry->id)->whereNotNull('client_quotation_approval_id'))->latest('id')->get()]);
    }

    public function save(QuotationRequest $request, Inquiry $inquiry, ManageQuotation $action): RedirectResponse
    {
        $this->case($inquiry);
        $r = $action->save($inquiry, $request->user(), $request->validated());

        return to_route($r->state === 'needs_review' ? 'quotations.review' : 'quotations.index', $r->state === 'needs_review' ? [$inquiry, $r] : [$inquiry])->with('status', 'Revision '.$r->number.' saved with its private PDF. Nothing has been sent.');
    }

    public function review(Inquiry $inquiry, ClientQuotationRevision $revision, ManageQuotation $action): View
    {
        $this->revision($inquiry, $revision);
        $snapshot = null;
        $reasons = QuotationEligibility::reasons($revision);
        if (! $reasons) {
            try {
                $snapshot = $action->reviewSnapshot($revision);
            } catch (ValidationException $e) {
                $reasons = array_merge(...array_values($e->errors()));
            }
        }
        $approval = $revision->approval;

        return view('quotations.review', ['inquiry' => $inquiry, 'revision' => $revision, 'snapshot' => $snapshot, 'reasons' => $reasons,
            'approval' => $approval, 'mail' => $approval?->snapshot['content'] ?? QuotationContent::mail($revision),
            'manual' => $approval ? DB::table('quotation_manual_sends')->where('client_quotation_approval_id', $approval->id)->first() : null]);
    }

    public function approve(Request $request, Inquiry $inquiry, ClientQuotationRevision $revision, ManageQuotation $action): RedirectResponse
    {
        $this->revision($inquiry, $revision);
        $data = $request->validate(['digest' => 'required|string|size:64', 'approve_exact' => 'accepted']);
        $action->approve($revision, $request->user(), $data['digest']);

        return to_route('quotations.review', [$inquiry, $revision])->with('status', 'Exact quotation and sender approved. Send remains a separate explicit action.');
    }

    public function pdf(Request $request, Inquiry $inquiry, ClientQuotationRevision $revision): Response
    {
        $this->revision($inquiry, $revision);
        $bytes = QuotationPdf::bytes($revision);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$revision->quotation->reference.'-v'.$revision->number.'.pdf"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'"]);
    }

    public function manual(Request $request, Inquiry $inquiry, ClientQuotationRevision $revision, ManageQuotation $action): RedirectResponse
    {
        $this->revision($inquiry, $revision);
        $data = $request->validate(['confirm' => 'accepted', 'reason' => 'required|string|max:2000']);
        DB::transaction(function () use ($action, $revision, $request, $data): void {
            $action->lockRevision($revision, $request->user());
            $a = $revision->approval;
            if (! $a) {
                Processing::fail('Approve the exact quotation before recording a manual communication.');
            }
            QuotationEligibility::approved($a);
            if ($a->envelopes()->whereHas('dispatches', fn ($q) => $q->where('status', '!=', 'cancelled'))->exists()) {
                Processing::fail('A provider dispatch already exists. Inspect it before recording communication.');
            }
            DB::table('quotation_manual_sends')->insertOrIgnore(['client_quotation_approval_id' => $a->id, 'recorded_by' => $request->user()->id, 'recorded_at' => now(), 'reason' => $data['reason']]);
            Audit::record('Client quotation manually recorded as communicated', $a, actor: $request->user(), details: ['manual' => ['before' => null, 'after' => $data['reason']]]);
        });

        return back()->with('status', 'Manual communication declared. No provider send occurred.');
    }
}
