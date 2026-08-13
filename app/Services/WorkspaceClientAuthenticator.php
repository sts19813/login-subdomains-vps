<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class WorkspaceClientAuthenticator
{
    public function fromRequest(Request $request): ?Workspace
    {
        $clientId = (string) ($request->getUser() ?: $request->input('client_id', ''));
        $clientSecret = (string) ($request->getPassword() ?: $request->input('client_secret', ''));

        if ($clientId === '' || $clientSecret === '' || strlen($clientSecret) > 255) {
            return null;
        }

        $workspace = Workspace::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->first();

        return $workspace && Hash::check($clientSecret, $workspace->client_secret_hash)
            ? $workspace
            : null;
    }
}
