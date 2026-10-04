<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiSettingsRequest;
use App\Models\AiBudgetDay;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Support\AiUsage;
use App\Support\ExtractionTools;
use App\Support\OpenAiResponses;
use App\Support\Processing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AiSettingsController extends Controller
{
    public function show(ExtractionTools $tools): View
    {
        Gate::authorize('manage-company');
        $settings = AiSetting::current();

        return view('ai-settings', ['settings' => $settings, 'reasons' => AiUsage::unavailable($settings), 'profile' => $tools->profile(), 'days' => AiBudgetDay::latest('day')->limit(14)->get(), 'runs' => AiRun::with('inquiry')->where('is_demo', false)->latest('id')->paginate(15)]);
    }

    public function update(AiSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data): void {
            $settings = AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $model = $data['model'] ?? null;
            if ($model !== $settings->model) {
                $settings->model_check = null;
            }
            $settings->enabled = (bool) $data['enabled'];
            $settings->model = $model;
            $settings->configuration = array_diff_key($data, array_flip(['enabled', 'model']));
            $settings->save();
        });

        return back()->with('status', 'AI configuration saved. Live requests remain blocked until all credentials, access, support, rates and caps are available.');
    }

    public function check(Request $request, OpenAiResponses $provider): RedirectResponse
    {
        Gate::authorize('manage-company');
        $settings = AiSetting::current();
        if (! config('ai.key') || ! $settings->model) {
            Processing::fail('Configure a server API key and explicit runtime model before checking account access.');
        }
        try {
            $accessible = $provider->checkModel($settings->model);
        } catch (\Throwable $exception) {
            $accessible = false;
        }
        DB::transaction(function () use ($settings, $accessible): void {
            $locked = AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($locked->model !== $settings->model) {
                Processing::fail('The model changed during access checking. Check the new model.');
            }
            $locked->update(['model_check' => ['state' => $accessible ? 'accessible' : 'unavailable', 'model' => $settings->model, 'checked_at' => now()->toIso8601String()]]);
        });

        return back()->with($accessible ? 'status' : 'warning', $accessible ? 'The configured account can access this model. Structured-output support still requires the documented model check.' : 'Model access could not be confirmed. Check account permissions and configuration; no proposal request was sent.');
    }

    public function reconcile(Request $request, AiRun $run): RedirectResponse
    {
        Gate::authorize('manage-company');
        $data = $request->validate(['cost' => ['required', 'regex:/^\d{1,6}(\.\d{1,8})?$/', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'min:12', 'max:2000'], 'evidence_checked' => ['accepted']]);
        AiUsage::reconcile($run, $data['cost'], $data['reason']);

        return back()->with('status', 'Provider evidence reconciliation recorded. The held reservation has been replaced by the documented cost; it is not a provider invoice.');
    }
}
