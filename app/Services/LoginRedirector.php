<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoginRedirector
{
    public function __construct(private readonly SsoBroker $broker) {}

    public function afterLogin(Request $request, User $user): RedirectResponse
    {
        $requestedSlug = (string) $request->session()->pull('requested_workspace', '');

        if ($requestedSlug !== '') {
            $requested = $user->activeWorkspaces()
                ->where('slug', $requestedSlug)
                ->first();

            if ($requested) {
                return $this->broker->redirect($user, $requested, $request);
            }

            return redirect()
                ->route('workspaces.index')
                ->withErrors(['workspace' => 'Tu cuenta no tiene acceso al espacio solicitado.']);
        }

        $workspaces = $user->activeWorkspaces()->orderBy('name')->get();

        if ($workspaces->count() === 1) {
            return $this->broker->redirect($user, $workspaces->first(), $request);
        }

        return redirect()->route('workspaces.index');
    }
}
