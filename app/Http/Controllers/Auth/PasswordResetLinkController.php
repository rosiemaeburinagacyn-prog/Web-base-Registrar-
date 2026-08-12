<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetOtpNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:50'],
        ]);

        $user = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('student_number', trim($validated['student_number']))
            ->first();

        if ($user && $user->isActive() && $user->school_email) {
            $otp = (string) random_int(100000, 999999);

            $user->forceFill([
                'password_reset_otp_hash' => hash('sha256', $otp),
                'password_reset_otp_expires_at' => now()->addMinutes(10),
            ])->save();

            Notification::send($user, new PasswordResetOtpNotification($otp));
        }

        return redirect()
            ->route('password.reset')
            ->with('status', 'If the student number exists, an OTP was sent to the registered school email.');
    }
}
