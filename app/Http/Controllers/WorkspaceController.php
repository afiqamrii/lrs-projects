<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileRequest;
use App\Http\Requests\SettingsRequest;
use App\Models\AuditEntry;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function overview(): View
    {
        return view('overview', ['total' => Vendor::count(), 'active' => Vendor::where('is_active', true)->count(), 'missing' => Vendor::where('is_active', true)->whereDoesntHave('primaryContact')->count(), 'ready' => Vendor::where('is_active', true)->whereHas('primaryContact')->count(), 'review' => Inquiry::workspace()->where('status', 'needs_review')->count(), 'waiting' => Inquiry::workspace()->where('status', 'needs_client_information')->count(), 'overdue' => Inquiry::workspace()->where('status', '!=', 'closed')->where('response_due_at', '<', now())->count(), 'queue' => Inquiry::with('client', 'owner')->workspace()->whereNotIn('status', ['closed', 'ready_for_sourcing'])->orderBy('response_due_at')->orderByDesc('id')->limit(5)->get(), 'activity' => AuditEntry::where(fn ($q) => $q->whereNotNull('vendor_id')->orWhereNotNull('client_id')->orWhereNotNull('inquiry_id'))->where(fn ($q) => $q->whereNull('inquiry_id')->orWhereIn('inquiry_id', Inquiry::workspace()->select('id')))->latest('id')->limit(8)->get(), 'recent' => Vendor::with('primaryContact')->where('is_active', true)->latest('updated_at')->limit(4)->get()]);
    }

    public function profile(): View
    {
        return view('profile');
    }

    public function updateProfile(ProfileRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $user = $request->user();
            $before = Audit::snapshot($user);
            $user->name = $request->validated('name');
            if ($request->filled('password')) {
                $user->password = $request->validated('password');
                $user->remember_token = null;
            }
            $user->save();
            Audit::record('Staff profile updated', $user, $before);
            if ($request->filled('password')) {
                Audit::record('Staff password changed', $user, Audit::snapshot($user));
                Auth::logoutOtherDevices($request->validated('password'));
                DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
                $request->session()->put('password_hash_web', $user->password);
                $request->session()->regenerate();
            }
        });

        return back()->with('status', 'Your profile has been updated.');
    }

    public function settings(): View
    {
        return view('settings', ['settings' => CompanySetting::current(), 'owners' => User::where('is_active', true)->orderBy('name')->get()]);
    }

    public function updateSettings(SettingsRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $settings = CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $before = Audit::snapshot($settings);
            $settings->update($request->validated());
            Audit::record('Company settings updated', $settings, $before);
        });

        return back()->with('status', 'Company settings saved.');
    }
}
