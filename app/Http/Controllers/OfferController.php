<?php

namespace App\Http\Controllers;

use App\Actions\ManageOffer;
use App\Actions\StartOfferProposals;
use App\Http\Requests\OfferRequest;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Inquiry;
use App\Models\MailMessage;
use App\Models\OfferSelection;
use App\Models\RfqRevision;
use App\Models\VendorOffer;
use App\Support\AiUsage;
use App\Support\OfferEligibility;
use App\Support\OfferProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OfferController extends Controller
{
    private function case(Inquiry $inquiry): void
    {
        Gate::authorize('view', $inquiry);
        Gate::authorize('viewAny', VendorOffer::class);
    }

    private function offer(Inquiry $inquiry, VendorOffer $offer): void
    {
        $this->case($inquiry);
        abort_unless($offer->inquiry_id === $inquiry->id, 404);
    }

    public function index(Inquiry $inquiry): View
    {
        $this->case($inquiry);
        $comparison = OfferEligibility::currentComparison($inquiry);

        return view('offers.index', ['inquiry' => $inquiry, 'comparison' => $comparison, 'rows' => OfferEligibility::matrix($inquiry, $comparison), 'selection' => OfferSelection::where('inquiry_id', $inquiry->id)->whereNull('superseded_at')->first(), 'captured' => VendorOffer::where('inquiry_id', $inquiry->id)->where('current_number', 0)->with('vendor')->get(), 'history' => OfferSelection::where('inquiry_id', $inquiry->id)->latest('id')->get()]);
    }

    public function create(Inquiry $inquiry): View
    {
        $this->case($inquiry);

        return view('offers.capture', ['inquiry' => $inquiry, 'priorOffers' => VendorOffer::where('inquiry_id', $inquiry->id)->with('vendor')->get(), 'requests' => RfqRevision::whereHas('rfq', fn ($q) => $q->where('inquiry_id', $inquiry->id))->whereHas('approval')->with('rfq.vendor', 'rfq.round.version')->get(), 'messages' => MailMessage::where('inquiry_id', $inquiry->id)->whereNotNull('rfq_revision_id')->get(), 'documents' => $inquiry->documents()->where('is_archived', false)->get()]);
    }

    public function capture(Request $request, Inquiry $inquiry, ManageOffer $action): RedirectResponse
    {
        $this->case($inquiry);
        $offer = $action->capture($inquiry, $request->user(), $request->all());

        return redirect()->route('offers.edit', [$inquiry, $offer])->with('status', 'Original evidence captured privately. Enter and review commercial terms.');
    }

    public function edit(Request $request, Inquiry $inquiry, VendorOffer $offer): View
    {
        $this->offer($inquiry, $offer);
        $revision = $request->filled('version') ? $offer->revisions()->where('number', $request->integer('version'))->firstOrFail() : $offer->current();
        $p = $revision?->payload ?? ManageOffer::blank($offer);
        $sources = $revision ? OfferProposal::sources($revision) : [];

        return view('offers.edit', ['inquiry' => $inquiry, 'offer' => $offer, 'revision' => $revision, 'p' => $p, 'sources' => $sources, 'runs' => AiRun::where('vendor_offer_revision_id', $revision?->id)->where('purpose', 'vendor_quotation')->latest('id')->get(), 'aiReasons' => AiUsage::unavailable(AiSetting::findOrFail(1)), 'historical' => $offer->supersededBy() || ($revision && $revision->number !== $offer->current_number)]);
    }

    public function save(OfferRequest $request, Inquiry $inquiry, VendorOffer $offer, ManageOffer $action): RedirectResponse
    {
        $this->offer($inquiry, $offer);
        $review = $request->input('intent') === 'review';
        $revision = $action->save($offer, $request->user(), $request->validated(), $review);

        return redirect()->route('offers.edit', [$inquiry, $offer])->with('status', $revision->status === 'reviewed_complete' ? 'Commercial review complete. Check comparison eligibility before selection.' : ($review ? 'Review recorded with gaps. Resolve the checklist before final selection.' : 'New draft saved. Explicit commercial review is still required.'));
    }

    public function comparison(Request $request, Inquiry $inquiry, ManageOffer $action): RedirectResponse
    {
        $this->case($inquiry);
        $action->comparison($inquiry, $request->user(), $request->all());

        return redirect()->route('offers.index', $inquiry)->with('status', 'Comparison currency and reviewed FX saved as an immutable version.');
    }

    public function select(Request $request, Inquiry $inquiry, ManageOffer $action): RedirectResponse
    {
        $this->case($inquiry);
        $selection = $action->select($inquiry, $request->user(), $request->all());

        return redirect()->route('offers.selection', [$inquiry, $selection])->with('status', $selection->kind === 'final' ? 'Exact vendor cost basis saved.' : 'Provisional preference recorded; it cannot be used for pricing.');
    }

    public function selection(Inquiry $inquiry, OfferSelection $selection): View
    {
        $this->case($inquiry);
        abort_unless($selection->inquiry_id === $inquiry->id, 404);

        return view('offers.selection', ['inquiry' => $inquiry, 'selection' => $selection, 's' => $selection->snapshot, 'reasons' => OfferEligibility::selectionReasons($selection)]);
    }

    public function scope(Request $request, Inquiry $inquiry, VendorOffer $offer): View
    {
        $this->offer($inquiry, $offer);
        $revision = $offer->current();
        abort_unless($revision, 404);
        $catalogue = OfferProposal::sources($revision);
        $ids = $request->input('source_ids', array_column($catalogue, 'id'));
        $sources = array_values(array_filter($catalogue, fn (array $s): bool => in_array($s['id'], (array) $ids, true)));
        $settings = AiSetting::findOrFail(1);

        return view('offers.ai', ['inquiry' => $inquiry, 'offer' => $offer, 'revision' => $revision, 'catalogue' => $catalogue, 'sources' => $sources, 'settings' => $settings, 'scope' => AiUsage::scope($sources, $settings, 'vendor_quotation'), 'estimate' => AiUsage::estimate($sources, $settings, 'vendor_quotation'), 'bound' => AiUsage::bound($sources, 'vendor_quotation'), 'reasons' => AiUsage::unavailable($settings)]);
    }

    public function proposals(Request $request, Inquiry $inquiry, VendorOffer $offer, StartOfferProposals $action): RedirectResponse
    {
        $this->offer($inquiry, $offer);
        $d = $request->validate(['expected_revision' => 'required|integer', 'scope' => 'required|string|size:64', 'source_ids' => 'required|array|min:1|max:200', 'source_ids.*' => 'string|max:200', 'authorize_paid' => 'required|accepted', 'retry' => 'nullable|boolean', 'retry_reason' => 'nullable|string|max:2000']);
        $action->handle($offer, $request->user(), (int) $d['expected_revision'], $d['scope'], $d['source_ids'], ! empty($d['retry']), $d['retry_reason'] ?? null);

        return redirect()->route('offers.edit', [$inquiry, $offer])->with('status', 'Quotation extraction queued under the reviewed budget. No commercial fields are approved.');
    }

    public function apply(Request $request, Inquiry $inquiry, VendorOffer $offer, AiRun $run, StartOfferProposals $action): RedirectResponse
    {
        $this->offer($inquiry, $offer);
        $action->apply($offer, $run, $request->user(), $request->all());

        return redirect()->route('offers.edit', [$inquiry, $offer])->with('status', 'Per-field decisions recorded as a new draft. Review every commercial section and charge.');
    }
}
