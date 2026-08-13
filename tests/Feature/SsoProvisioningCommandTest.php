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

    public function test_billing_commands_configure_prices_and_user_permissions(): void
    {
        $this->artisan('sso:workspace', [
            'slug' => 'tipi',
            'name' => 'Tipi',
            'base_url' => 'https://tipi.naboo.cloud',
            'callback_url' => 'https://tipi.naboo.cloud/sso/callback',
        ])->assertSuccessful();
        $this->artisan('sso:user', [
            'email' => 'billing@example.com',
            '--password' => 'secret123',
        ])->assertSuccessful();
        $this->artisan('sso:grant', [
            'email' => 'billing@example.com',
            'workspace' => 'tipi',
        ])->assertSuccessful();

        $this->artisan('billing:configure', [
            'workspace' => 'tipi',
            '--vacant' => '25.50',
            '--rented' => '45',
            '--grace' => '7',
            '--email' => 'pagos@example.com',
            '--enable' => true,
        ])->assertSuccessful();
        $this->artisan('billing:access', [
            'email' => 'billing@example.com',
            'workspace' => 'tipi',
            '--manager' => true,
            '--override' => true,
        ])->assertSuccessful();

        $workspace = Workspace::query()->where('slug', 'tipi')->firstOrFail();
        $membership = $workspace->users()->where('email', 'billing@example.com')->firstOrFail()->pivot;
        $this->assertSame(2550, $workspace->vacant_property_unit_amount);
        $this->assertSame(4500, $workspace->rented_property_unit_amount);
        $this->assertSame(7, $workspace->billing_grace_days);
        $this->assertSame('pagos@example.com', $workspace->billing_email);
        $this->assertTrue($workspace->billing_enforced);
        $this->assertSame(1, $membership->is_billing_manager);
        $this->assertSame(1, $membership->billing_access_override);
    }
}
