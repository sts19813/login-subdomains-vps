<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SsoCode;
use App\Services\BillingAccessService;
use App\Services\WorkspaceClientAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SsoExchangeController extends Controller
{
    public function __invoke(
        Request $request,
        WorkspaceClientAuthenticator $authenticator,
        BillingAccessService $billingAccess,
    ): JsonResponse {
        $workspace = $authenticator->fromRequest($request);
        if (! $workspace) {
            return $this->error('invalid_client', 'Las credenciales del cliente no son válidas.', 401);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:80'],
        ]);

        $payload = DB::transaction(function () use ($validated, $workspace, $billingAccess): ?array {
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
                || ! $billingAccess->canAccess($authorizationCode->user, $workspace)
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
                'billing' => [
                    'subscription_status' => $workspace->subscription_status,
                    'access_allowed' => true,
                    'grace_ends_at' => $workspace->billing_grace_ends_at?->toIso8601String(),
                ],
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
