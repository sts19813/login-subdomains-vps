<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SsoCode;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SsoExchangeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $clientId = (string) ($request->getUser() ?: $request->input('client_id', ''));
        $clientSecret = (string) ($request->getPassword() ?: $request->input('client_secret', ''));

        if ($clientId === '' || $clientSecret === '' || strlen($clientSecret) > 255) {
            return $this->error('invalid_client', 'Las credenciales del cliente no son válidas.', 401);
        }

        $workspace = Workspace::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->first();

        if (! $workspace || ! Hash::check($clientSecret, $workspace->client_secret_hash)) {
            return $this->error('invalid_client', 'Las credenciales del cliente no son válidas.', 401);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:80'],
        ]);

        $payload = DB::transaction(function () use ($validated, $workspace): ?array {
            $authorizationCode = SsoCode::query()
                ->with('user')
                ->where('code_hash', hash('sha256', $validated['code']))
                ->where('workspace_id', $workspace->getKey())
                ->lockForUpdate()
                ->first();

            if (
                ! $authorizationCode
                || $authorizationCode->consumed_at
                || $authorizationCode->expires_at->isPast()
                || ! $authorizationCode->user?->is_active
                || ! $authorizationCode->user->activeWorkspaces()->whereKey($workspace->getKey())->exists()
            ) {
                return null;
            }

            $authorizationCode->forceFill(['consumed_at' => now()])->save();
            $user = $authorizationCode->user;

            return [
                'sub' => (string) $user->getKey(),
                'email' => $user->email,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'email_verified' => $user->email_verified_at !== null,
                'workspace' => $workspace->slug,
            ];
        });

        if (! $payload) {
            return $this->error('invalid_grant', 'El código es inválido, expiró o ya fue utilizado.', 422);
        }

        return response()->json([
            'token_type' => 'sso_identity',
            'user' => $payload,
        ])->withHeaders($this->noStoreHeaders());
    }

    private function error(string $error, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'message' => $message,
        ], $status)->withHeaders($this->noStoreHeaders());
    }

    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ];
    }
}
