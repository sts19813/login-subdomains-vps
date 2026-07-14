<?php

namespace Tests\Feature;

use App\Models\SsoCode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SsoExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_client_can_exchange_a_code_only_once(): void
    {
        [$workspace, $user, $plainCode] = $this->authorization();

        $response = $this->withBasicAuth($workspace->client_id, 'workspace-secret')
            ->postJson(route('api.sso.exchange'), ['code' => $plainCode]);

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('token_type', 'sso_identity')
            ->assertJsonPath('user.sub', (string) $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.workspace', $workspace->slug);

        $this->assertNotNull(SsoCode::query()->firstOrFail()->consumed_at);

        $this->withBasicAuth($workspace->client_id, 'workspace-secret')
            ->postJson(route('api.sso.exchange'), ['code' => $plainCode])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_wrong_client_secret_does_not_consume_the_code(): void
    {
        [$workspace, , $plainCode] = $this->authorization();

        $this->withBasicAuth($workspace->client_id, 'wrong-secret')
            ->postJson(route('api.sso.exchange'), ['code' => $plainCode])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_client');

        $this->assertNull(SsoCode::query()->firstOrFail()->consumed_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        [$workspace, , $plainCode] = $this->authorization(now()->subSecond());

        $this->withBasicAuth($workspace->client_id, 'workspace-secret')
            ->postJson(route('api.sso.exchange'), ['code' => $plainCode])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_code_cannot_be_exchanged_by_a_different_workspace(): void
    {
        [$workspace, , $plainCode] = $this->authorization();
        $other = Workspace::query()->create([
            'name' => 'Tayde',
            'slug' => 'tayde',
            'base_url' => 'https://tayde.naboo.cloud',
            'callback_url' => 'https://tayde.naboo.cloud/sso/callback',
            'client_id' => 'client-tayde',
            'client_secret_hash' => Hash::make('tayde-secret'),
            'is_active' => true,
        ]);

        $this->withBasicAuth($other->client_id, 'tayde-secret')
            ->postJson(route('api.sso.exchange'), ['code' => $plainCode])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_grant');

        $this->assertNull(SsoCode::query()->where('workspace_id', $workspace->id)->firstOrFail()->consumed_at);
    }

    private function authorization($expiresAt = null): array
    {
        $workspace = Workspace::query()->create([
            'name' => 'Tipi',
            'slug' => 'tipi',
            'base_url' => 'https://tipi.naboo.cloud',
            'callback_url' => 'https://tipi.naboo.cloud/sso/callback',
            'client_id' => 'client-tipi',
            'client_secret_hash' => Hash::make('workspace-secret'),
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $user->workspaces()->attach($workspace, ['is_active' => true]);
        $plainCode = Str::random(80);

        SsoCode::query()->create([
            'code_hash' => hash('sha256', $plainCode),
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'expires_at' => $expiresAt ?? now()->addMinute(),
        ]);

        return [$workspace, $user, $plainCode];
    }
}
