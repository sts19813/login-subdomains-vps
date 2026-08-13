<?php

namespace Tests\Feature;

use App\Models\SsoCode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BillingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_subscription_redirects_to_billing_instead_of_issuing_sso_code(): void
    {
        [$user, $workspace] = $this->membership();

        $this->actingAs($user)
            ->post(route('workspaces.launch', $workspace))
            ->assertRedirect(route('billing.show', $workspace));

        $this->assertDatabaseEmpty('sso_codes');
    }

    public function test_active_subscription_can_launch_workspace(): void
    {
        [$user, $workspace] = $this->membership(['subscription_status' => 'active']);

        $this->actingAs($user)
            ->post(route('workspaces.launch', $workspace))
            ->assertRedirectContains($workspace->callback_url);

        $this->assertSame(1, SsoCode::query()->count());
    }

    public function test_user_override_can_enter_without_active_subscription(): void
    {
        [$user, $workspace] = $this->membership([], ['billing_access_override' => true]);

        $this->actingAs($user)
            ->post(route('workspaces.launch', $workspace))
            ->assertRedirectContains($workspace->callback_url);
    }

    public function test_failed_payment_keeps_access_during_configured_grace_period(): void
    {
        [$user, $workspace] = $this->membership([
            'subscription_status' => 'past_due',
            'billing_grace_ends_at' => now()->addDays(3),
        ]);

        $this->actingAs($user)
            ->post(route('workspaces.launch', $workspace))
            ->assertRedirectContains($workspace->callback_url);
    }

    public function test_only_billing_manager_sees_payment_action(): void
    {
        [$manager, $workspace] = $this->membership([
            'property_count' => 1,
            'metrics_reported_at' => now(),
        ], ['is_billing_manager' => true]);

        $this->actingAs($manager)
            ->get(route('billing.show', $workspace))
            ->assertOk()
            ->assertSee('Domiciliar pago mensual');

        $member = User::factory()->create();
        $member->workspaces()->attach($workspace, ['is_active' => true]);

        $this->actingAs($member)
            ->get(route('billing.show', $workspace))
            ->assertOk()
            ->assertDontSee('Domiciliar pago mensual')
            ->assertSee('Se requiere un responsable de facturación');
    }

    public function test_instance_can_check_entitlement_for_existing_session_and_user_override(): void
    {
        [$user, $workspace] = $this->membership([], ['billing_access_override' => true]);

        $this->withBasicAuth($workspace->client_id, 'workspace-secret')
            ->postJson(route('api.billing.entitlement'), ['user_sub' => (string) $user->id])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('access_allowed', true)
            ->assertJsonPath('reason', 'override');

        $member = User::factory()->create();
        $member->workspaces()->attach($workspace, ['is_active' => true]);

        $this->withBasicAuth($workspace->client_id, 'workspace-secret')
            ->postJson(route('api.billing.entitlement'), ['user_sub' => (string) $member->id])
            ->assertOk()
            ->assertJsonPath('access_allowed', false)
            ->assertJsonPath('reason', 'blocked');
    }

    private function membership(array $workspaceAttributes = [], array $pivotAttributes = []): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(array_merge([
            'name' => 'Tipi',
            'slug' => 'tipi',
            'base_url' => 'https://tipi.naboo.cloud',
            'callback_url' => 'https://tipi.naboo.cloud/sso/callback',
            'client_id' => 'client-tipi',
            'client_secret_hash' => Hash::make('workspace-secret'),
            'is_active' => true,
            'billing_enforced' => true,
            'property_count' => 1,
            'metrics_reported_at' => now(),
        ], $workspaceAttributes));
        $user->workspaces()->attach($workspace, array_merge(['is_active' => true], $pivotAttributes));

        return [$user, $workspace];
    }
}
