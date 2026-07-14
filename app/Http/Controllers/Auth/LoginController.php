<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\LoginRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('workspaces.index');
        }

        $workspaceSlug = (string) $request->query('workspace', '');
        if ($workspaceSlug !== '' && Workspace::query()->where('slug', $workspaceSlug)->where('is_active', true)->exists()) {
            $request->session()->put('requested_workspace', $workspaceSlug);
        } elseif ($request->has('workspace')) {
            $request->session()->forget('requested_workspace');
        }

        return view('auth.login');
    }

    public function store(Request $request, LoginRedirector $redirector): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'El correo o la contraseña no son correctos.',
            ]);
        }

        $request->session()->regenerate();
        $user = $request->user();

        if (! $user?->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Tu cuenta se encuentra desactivada.',
            ]);
        }

        return $redirector->afterLogin($request, $user);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
