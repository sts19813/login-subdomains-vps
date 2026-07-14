<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SsoProvisioningCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_commands_create_workspace_user_and_membership(): void
    {
        $this->artisan('sso:workspace', [
            'slug' => 'tipi',
            'name' => 'Tipi',
            'base_url' => 'https://tipi.naboo.cloud',
            'callback_url' => 'https://tipi.naboo.cloud/sso/callback',
        ])->assertSuccessful();

        $this->artisan('sso:user', [
            'email' => 'admin@example.com',
            '--name' => 'Admin Central',
            '--password' => 'secret123',
        ])->assertSuccessful();

        $this->artisan('sso:grant', [
            'email' => 'admin@example.com',
            'workspace' => 'tipi',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $workspace = Workspace::query()->where('slug', 'tipi')->firstOrFail();

        $this->assertTrue($user->activeWorkspaces()->whereKey($workspace->id)->exists());
        $this->assertNotSame('', $workspace->client_id);
        $this->assertNotSame('', $workspace->client_secret_hash);
    }

    public function test_workspace_command_rejects_callback_on_another_host(): void
    {
        $this->artisan('sso:workspace', [
            'slug' => 'tipi',
            'name' => 'Tipi',
            'base_url' => 'https://tipi.naboo.cloud',
            'callback_url' => 'https://attacker.example/sso/callback',
        ])->assertFailed();

        $this->assertDatabaseEmpty('workspaces');
    }
}
