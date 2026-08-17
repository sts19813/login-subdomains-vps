<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SsoProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_can_provision_a_central_user_and_membership(): void
    {
        $workspace = $this->workspace('tayde');
        $passwordHash = Hash::make('secret123');

        $response = $this->withBasicAuth('client-tayde', 'secret-tayde')
            ->postJson(route('api.sso.provision'), [
                'name' => 'Usuario Tayde',
                'email' => 'USUARIO@EXAMPLE.COM',
                'password_hash' => $passwordHash,
                'is_active' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'usuario@example.com')
            ->assertJsonPath('user.workspace', 'tayde')
            ->assertJsonPath('user.access_active', true)
            ->assertHeader('Cache-Control', 'no-store, private');

        $user = User::query()->where('email', 'usuario@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertTrue($user->activeWorkspaces()->whereKey($workspace->id)->exists());
        $this->assertTrue((bool) $user->workspaces()->firstOrFail()->pivot->can_sync_identity);
        $this->assertSame((string) $user->id, $response->json('user.sub'));
    }

    public function test_provisioning_updates_the_same_identity_without_duplicate_users(): void
    {
        $workspace = $this->workspace('tayde');
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $user->workspaces()->attach($workspace, [
            'is_active' => true,
            'can_sync_identity' => true,
        ]);

        $this->withBasicAuth('client-tayde', 'secret-tayde')
            ->postJson(route('api.sso.provision'), [
                'subject' => (string) $user->id,
                'name' => 'Nombre actualizado',
                'email' => 'new@example.com',
                'password_hash' => Hash::make('new-password'),
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('user.sub', (string) $user->id);

        $this->assertSame(1, User::query()->count());
        $this->assertSame('Nombre actualizado', $user->fresh()->name);
        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_a_second_workspace_cannot_replace_an_existing_identity_password(): void
    {
        $tayde = $this->workspace('tayde');
        $tipi = $this->workspace('tipi');
        $user = User::factory()->create([
            'email' => 'shared@example.com',
            'password' => Hash::make('central-password'),
        ]);
        $user->workspaces()->attach($tayde, [
            'is_active' => true,
            'can_sync_identity' => true,
        ]);

        $this->withBasicAuth('client-tipi', 'secret-tipi')
            ->postJson(route('api.sso.provision'), [
                'name' => 'Nombre local',
                'email' => 'shared@example.com',
                'password_hash' => Hash::make('replacement-password'),
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('user.credentials_updated', false);

        $this->assertTrue(Hash::check('central-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('replacement-password', $user->fresh()->password));
        $this->assertTrue($user->activeWorkspaces()->whereKey($tipi->id)->exists());
        $this->assertFalse((bool) $user->workspaces()->whereKey($tipi->id)->firstOrFail()->pivot->can_sync_identity);
    }

    public function test_invalid_workspace_credentials_cannot_provision_users(): void
    {
        $this->workspace('tayde');

        $this->withBasicAuth('client-tayde', 'incorrect-secret')
            ->postJson(route('api.sso.provision'), [
                'name' => 'Usuario',
                'email' => 'usuario@example.com',
                'password_hash' => Hash::make('secret123'),
                'is_active' => true,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_client');

        $this->assertDatabaseEmpty('users');
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'base_url' => "https://{$slug}.naboo.cloud",
            'callback_url' => "https://{$slug}.naboo.cloud/sso/callback",
            'client_id' => "client-{$slug}",
            'client_secret_hash' => Hash::make("secret-{$slug}"),
            'is_active' => true,
        ]);
    }
}
