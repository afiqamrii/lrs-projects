<?php

namespace App\Http\Controllers;

use App\Actions\ManageClarification;
use App\Http\Requests\ClarificationRequest;
use App\Models\Clarification;
use App\Models\Inquiry;
use App\Support\InquiryWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClarificationController extends Controller
{
    public function store(Request $request, Inquiry $inquiry, ManageClarification $manage): RedirectResponse
    {
        Gate::authorize('update', $inquiry);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return to_route('inquiries.clarifications.show', [$inquiry, $manage->prepare($inquiry, (int) $data['lock_version'])])->with('status', 'Deterministic clarification prepared. Edit and approve the exact message before manual communication.');
    }

    public function show(Inquiry $inquiry, Clarification $clarification): View
    {
        Gate::authorize('view', $inquiry);

        return view('inquiries.clarification', ['inquiry' => $inquiry, 'clarification' => $clarification, 'contacts' => $inquiry->client?->contacts()->where('is_active', true)->get() ?? collect()]);
    }

    public function update(ClarificationRequest $request, Inquiry $inquiry, Clarification $clarification, ManageClarification $manage): RedirectResponse
    {
        $manage->edit($inquiry, $clarification, $request->validated());

        return back()->with('status', 'Clarification draft saved. This is not an approval or a send.');
    }

    public function approve(Request $request, Inquiry $inquiry, Clarification $clarification, ManageClarification $manage): RedirectResponse
    {
        Gate::authorize('update', $inquiry);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);
        $manage->approve($inquiry, $clarification, (int) $data['lock_version']);

        return back()->with('status', 'Exact message approved for manual communication. LRS has not sent it.');
    }

    public function communicated(Request $request, Inquiry $inquiry, Clarification $clarification, ManageClarification $manage): RedirectResponse
    {
        Gate::authorize('update', $inquiry);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'recipient' => ['required', 'email:rfc', 'max:255'], 'channel' => ['required', Rule::in(array_diff(array_keys(Inquiry::CHANNELS), ['website']))], 'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'], 'confirmed_manual' => ['accepted']]);
        if (InquiryWorkflow::utc($data['occurred_at'])->gt(now())) {
            throw ValidationException::withMessages(['occurred_at' => 'Record communication that has already occurred.']);
        }
        $manage->communicated($inquiry, $clarification, $data);

        return to_route('inquiries.show', ['inquiry' => $inquiry, 'section' => 'activity'])->with('status', 'Communication recorded as performed manually outside LRS. No email was sent by this application.');
    }
}
