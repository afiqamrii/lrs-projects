<?php

namespace App\Providers;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::define('manage-company', fn (User $user) => $user->is_active && $user->role === 'admin');
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('public-page', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('public-intake', fn (Request $request) => [Limit::perMinute(4)->by($request->ip()), Limit::perHour(8)->by($request->ip())]);
        RateLimiter::for('public-resend', fn (Request $request) => [Limit::perMinute(1)->by($request->user()?->id ?? $request->ip()), Limit::perHour(3)->by($request->user()?->id ?? $request->ip())]);
        RateLimiter::for('public-confirm', fn (Request $request) => [Limit::perMinute(5)->by($request->ip()), Limit::perHour(20)->by($request->ip())]);
        View::composer(['overview', 'components.layout', 'components.activity', 'clients.*', 'inquiries.*', 'sourcing.*', 'mail.*', 'mailbox.*', 'components.shipment-summary'], function (\Illuminate\View\View $view): void {
            $view->with('company', CompanySetting::current());
        });
        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers());
    }
}
