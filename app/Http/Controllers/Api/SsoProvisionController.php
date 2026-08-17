<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkspaceClientAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SsoProvisionController extends Controller
{
    public function __invoke(Request $request, WorkspaceClientAuthenticator $authenticator): JsonResponse
    {
        $workspace = $authenticator->fromRequest($request);
        if (! $workspace) {
            return $this->error('invalid_client', 'Las credenciales del cliente no son válidas.', 401);
        }

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'regex:/^[1-9][0-9]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password_hash' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $passwordHash = (string) $validated['password_hash'];
        if (($this->passwordAlgorithm($passwordHash)) === 'unknown') {
            throw ValidationException::withMessages([
                'password_hash' => 'La contraseña debe enviarse como un hash compatible.',
            ]);
        }

        $email = Str::lower(trim((string) $validated['email']));
        $subject = filled($validated['subject'] ?? null) ? (int) $validated['subject'] : null;

        $result = DB::transaction(function () use ($validated, $workspace, $passwordHash, $email, $subject): array {
            $userBySubject = $subject
                ? User::query()->whereKey($subject)->lockForUpdate()->first()
                : null;
            $userByEmail = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($userBySubject && $userByEmail && ! $userBySubject->is($userByEmail)) {
                return ['conflict' => true];
            }

            $user = $userBySubject ?? $userByEmail;
            $existingMembership = $user?->workspaces()
                ->whereKey($workspace->getKey())
                ->first()?->pivot;

            if ($subject && $user && ! $existingMembership) {
                return ['conflict' => true];
            }

            $canSyncIdentity = ! $user || (bool) $existingMembership?->can_sync_identity;

            if (! $user) {
                $user = new User;
                $user->setRawAttributes([
                    'name' => trim((string) $validated['name']),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => $passwordHash,
                    'is_active' => true,
                ]);
                $user->save();
            } elseif ($canSyncIdentity) {
                $user->forceFill([
                    'name' => trim((string) $validated['name']),
                    'email' => $email,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                // El hash ya fue producido por la instancia de confianza. Se escribe
                // directamente para evitar que el cast "hashed" lo vuelva a cifrar.
                DB::table('users')->where('id', $user->getKey())->update([
                    'password' => $passwordHash,
                    'updated_at' => now(),
                ]);
                $user->refresh();
            }

            $user->workspaces()->syncWithoutDetaching([
                $workspace->getKey() => [
                    'is_active' => (bool) $validated['is_active'],
                    'can_sync_identity' => $canSyncIdentity,
                ],
            ]);
            $user->workspaces()->updateExistingPivot($workspace->getKey(), [
                'is_active' => (bool) $validated['is_active'],
                'can_sync_identity' => $canSyncIdentity,
            ]);

            $user->forceFill([
                'is_active' => $user->workspaces()->wherePivot('is_active', true)->exists(),
            ])->save();

            return [
                'conflict' => false,
                'user' => $user,
                'credentials_updated' => $canSyncIdentity,
            ];
        });

        if ($result['conflict']) {
            return $this->error(
                'identity_conflict',
                'El identificador central y el correo pertenecen a cuentas distintas.',
                409,
            );
        }

        /** @var User $user */
        $user = $result['user'];

        return response()->json([
            'user' => [
                'sub' => (string) $user->getKey(),
                'email' => $user->email,
                'workspace' => $workspace->slug,
                'access_active' => (bool) $validated['is_active'],
                'credentials_updated' => (bool) $result['credentials_updated'],
            ],
        ])->withHeaders($this->noStoreHeaders());
    }

    private function passwordAlgorithm(string $hash): string
    {
        return (string) (password_get_info($hash)['algoName'] ?? 'unknown');
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
