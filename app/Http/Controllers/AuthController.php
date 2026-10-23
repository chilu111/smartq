<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Those sign-in details do not match an account.']);
        }

        if (! $request->user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This account is inactive. Contact a clinic administrator.']);
        }

        $request->session()->regenerate();

        $default = $request->user()->hasRole('system-administrator', 'clinic-manager')
            ? route('dashboard')
            : route('departments.workspace', $request->user()->departments()->where('is_active', true)->orderBy('name')->firstOrFail());

        return redirect()->intended($default);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
