<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_STUDENT, User::ROLE_ADMIN, User::ROLE_REGISTRAR, User::ROLE_CASHIER])],
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $login = trim($credentials['login']);
        $role = $credentials['role'];

        $query = User::query()->where('role', $role);

        if ($role === User::ROLE_STUDENT) {
            $query->where('student_number', $login);
        } else {
            $query->where('email', strtolower($login));
        }

        $user = $query->first();

        if (! $user || ! password_verify($credentials['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'login' => 'This account is inactive. Contact the registrar office.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
