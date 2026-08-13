<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BillingAccessService;
use App\Services\WorkspaceClientAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingEntitlementController extends Controller
{
    public function __invoke(
        Request $request,
        WorkspaceClientAuthenticator $authenticator,
        BillingAccessService $access,
    ): JsonResponse {
        $workspace = $authenticator->fromRequest($request);
        if (! $workspace) {
            return response()->json([
                'error' => 'invalid_client',
                'message' => 'Las credenciales del cliente no son válidas.',
            ], 401);
        }

        $validated = $request->validate([
            'user_sub' => ['nullable', 'string', 'max:255', 'regex:/^\d+$/'],
        ]);
        $user = isset($validated['user_sub'])
            ? User::query()->find($validated['user_sub'])
            : null;
        $reason = $access->accessReason($user, $workspace);

        return response()->json([
            'workspace' => $workspace->slug,
            'access_allowed' => $reason !== 'blocked',
            'reason' => $reason,
            'subscription_status' => $workspace->subscription_status,
            'grace_ends_at' => $workspace->billing_grace_ends_at?->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
