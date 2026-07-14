<?php

namespace App\Services;

use App\Models\SsoCode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SsoBroker
{
    public function redirect(User $user, Workspace $workspace, Request $request): RedirectResponse
    {
        abort_unless($user->is_active && $workspace->is_active, 403);
        abort_unless($user->activeWorkspaces()->whereKey($workspace->getKey())->exists(), 403);

        SsoCode::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();

        $plainCode = Str::random(80);

        SsoCode::query()->create([
            'code_hash' => hash('sha256', $plainCode),
            'user_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'expires_at' => now()->addSeconds(max(30, (int) config('sso.code_ttl_seconds', 60))),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        $separator = str_contains($workspace->callback_url, '?') ? '&' : '?';
        $query = http_build_query([
            'code' => $plainCode,
            'workspace' => $workspace->slug,
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away($workspace->callback_url.$separator.$query);
    }
}
