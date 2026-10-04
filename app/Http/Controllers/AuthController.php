<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AuthController extends Controller
{
    public function login(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt([...$request->validated(), 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'We could not sign you in. Check your credentials or contact your administrator.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        if (User::where('email', $email)->where('is_active', true)->exists()) {
            try {
                Password::sendResetLink(['email' => $email, 'is_active' => true]);
            } catch (TransportExceptionInterface $e) {
                return back()->with('warning', 'Password email is temporarily unavailable. Contact your administrator or try again later.');
            }
        }

        return back()->with('status', 'If an active staff account matches, a reset link has been sent. Please check your inbox.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], 'token' => ['required', 'string'], 'password' => ['required', 'confirmed', PasswordRule::defaults()]]);
        $data['email'] = mb_strtolower(trim($data['email']));
        $status = Password::reset([...$data, 'is_active' => true], function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (! $user->is_active) {
                    throw ValidationException::withMessages(['email' => 'This link is unavailable. Contact your administrator.']);
                }
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                Audit::record('Staff password reset', $user, Audit::snapshot($user), null, $user);
                event(new PasswordReset($user));
            });
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Password updated. Sign in with your new password.')
            : back()->withErrors(['email' => __($status)])->withInput($request->only('email'));
    }
}
