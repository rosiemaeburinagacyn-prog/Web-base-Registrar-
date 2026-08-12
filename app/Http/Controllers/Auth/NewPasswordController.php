<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.reset-password', [
            'request' => $request,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:50'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('student_number', trim($validated['student_number']))
            ->first();

        $otpHash = hash('sha256', $validated['otp']);

        if (! $user
            || ! $user->password_reset_otp_hash
            || ! hash_equals($user->password_reset_otp_hash, $otpHash)
            || ! $user->password_reset_otp_expires_at
            || $user->password_reset_otp_expires_at->isPast()) {
            return back()
                ->withInput($request->only('student_number'))
                ->withErrors(['otp' => 'The OTP is invalid or expired.']);
        }

        $user->forceFill([
            'password' => password_hash($validated['password'], PASSWORD_BCRYPT),
            'password_reset_otp_hash' => null,
            'password_reset_otp_expires_at' => null,
            'remember_token' => null,
        ])->save();

        event(new PasswordReset($user));

        return redirect()
            ->route('login')
            ->with('status', 'Password reset successful. You can now log in with your student number.');
    }
}
