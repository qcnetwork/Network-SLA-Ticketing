<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Failed-login limits within DECAY_SECONDS. The username+IP pair stops plain guessing, the
     * per-username limit stops guessing one account from many IPs, and the per-IP limit stops one
     * IP trying a few passwords on every account. The per-IP limit is loose because the whole
     * office may share one public IP.
     *
     * @var array<string, int>
     */
    private const LIMITS = ['pair' => 5, 'user' => 10, 'ip' => 50];

    private const DECAY_SECONDS = 900;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], ['username' => 'username', 'password' => 'password']);

        $username = Str::lower(trim($credentials['username']));
        $keys = [
            'pair' => "login:pair:{$username}|{$request->ip()}",
            'user' => "login:user:{$username}",
            'ip' => "login:ip:{$request->ip()}",
        ];
        foreach ($keys as $limit => $key) {
            if (RateLimiter::tooManyAttempts($key, self::LIMITS[$limit])) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages([
                    'username' => "Terlalu banyak percobaan login. Coba lagi dalam {$minutes} menit.",
                ]);
            }
        }

        $attempt = [
            'username' => $username,
            'password' => $credentials['password'],
            'active' => true,
        ];

        if (! Auth::attempt($attempt, $request->boolean('remember'))) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }
            Log::warning('Failed login', ['username' => Str::limit($username, 50), 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['username' => 'Username atau password salah']);
        }

        // The per-IP count stays: a correct login from the office must not reset another user's guessing.
        RateLimiter::clear($keys['pair']);
        RateLimiter::clear($keys['user']);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))
            ->with('success', 'Selamat datang, '.$request->user()->name);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
