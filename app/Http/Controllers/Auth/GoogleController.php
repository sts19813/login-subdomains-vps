<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('login')->withErrors([
                'email' => 'El acceso con Google no está configurado.',
            ]);
        }

        $state = Str::random(64);
        $request->session()->put('google_oauth_state', $state);

        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => config('services.google.prompt', 'select_account'),
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request, LoginRedirector $redirector): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('google_oauth_state', '');
        $returnedState = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($expectedState === '' || ! hash_equals($expectedState, $returnedState) || $code === '') {
            return redirect()->route('login')->withErrors([
                'email' => 'No fue posible validar la respuesta de Google.',
            ]);
        }

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->callbackUrl(),
            'grant_type' => 'authorization_code',
        ]);

        $accessToken = (string) $tokenResponse->json('access_token', '');
        if (! $tokenResponse->successful() || $accessToken === '') {
            return redirect()->route('login')->withErrors([
                'email' => 'Google rechazó la autenticación.',
            ]);
        }

        $profileResponse = Http::withToken($accessToken)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        $email = Str::lower((string) $profileResponse->json('email', ''));
        $googleId = (string) $profileResponse->json('sub', '');
        $emailVerified = filter_var($profileResponse->json('email_verified', false), FILTER_VALIDATE_BOOL);

        if (! $profileResponse->successful() || $email === '' || $googleId === '' || ! $emailVerified) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google no devolvió una cuenta verificada válida.',
            ]);
        }

        $user = User::query()
            ->where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => (string) $profileResponse->json('name', 'Usuario'),
                'email' => $email,
                'password' => Hash::make(Str::password(40)),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }

        $user->forceFill([
            'google_id' => $googleId,
            'avatar_url' => (string) $profileResponse->json('picture', '') ?: null,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        if (! $user->is_active) {
            return redirect()->route('login')->withErrors([
                'email' => 'Tu cuenta se encuentra desactivada.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return $redirector->afterLogin($request, $user);
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled($this->callbackUrl());
    }

    private function callbackUrl(): string
    {
        return (string) (config('services.google.redirect') ?: route('auth.google.callback'));
    }
}
