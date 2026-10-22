<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\LoginRequest;
use App\Services\AuditLogger;
use App\Services\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly EventRecorder $events)
    {
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();

        // is_active ikut dicek di query autentikasi agar akun nonaktif tidak bisa masuk.
        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            $this->audit->record('auth.login_failed', null, null, ['email' => $credentials['email']]);
            $this->events->record('auth.login_failed', 'Percobaan login gagal untuk "'.mb_substr($credentials['email'], 0, 100).'".', null, null,
                ['email' => mb_substr($credentials['email'], 0, 255), 'ip' => $request->ip()]);

            throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        $request->session()->regenerate();
        $this->audit->record('auth.login', $request->user());

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->audit->record('auth.logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
