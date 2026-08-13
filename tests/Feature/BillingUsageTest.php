<?php

namespace Tests\Feature;

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BillingUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_reports_usage_and_central_calculates_the_monthly_amount(): void
    {
        $workspace = $this->workspace();

        $this->withBasicAuth($workspace->client_id, 'workspace-secret')
            ->postJson(route('api.billing.usage'), [
                'property_count' => 3,
                'rented_property_count' => 1,
                'measured_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('usage.properties', 3)
            ->assertJsonPath('usage.vacant_properties', 2)
            ->assertJsonPath('billing.calculated_amount', 8000)
            ->assertJsonPath('billing.currency', 'mxn');

        $this->assertDatabaseHas('workspace_usage_snapshots', [
            'workspace_id' => $workspace->id,
            'property_count' => 3,
            'rented_property_count' => 1,
            'calculated_amount' => 8000,
        ]);
    }

    public function test_rented_properties_cannot_exceed_total_properties(): void
    {
        $workspace = $this->workspace();

        $this->withBasicAuth($workspace->client_id, 'workspace-secret')
            ->postJson(route('api.billing.usage'), [
                'property_count' => 1,
                'rented_property_count' => 2,
                'measured_at' => now()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rented_property_count');
    }

    public function test_an_older_measurement_does_not_replace_the_latest_usage(): void
    {
        $workspace = $this->workspace();
        $latest = now()->subMinute()->startOfSecond();

        $workspace->forceFill([
            'property_count' => 10,
            'rented_property_count' => 4,
            'metrics_reported_at' => $latest,
        ])->save();

        $this->withBasicAuth($workspace->client_id, 'workspace-secret')
            ->postJson(route('api.billing.usage'), [
                'property_count' => 2,
                'rented_property_count' => 1,
                'measured_at' => $latest->copy()->subMinute()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('usage.properties', 10);

        $this->assertSame(10, $workspace->fresh()->property_count);
    }

    public function test_usage_endpoint_rejects_invalid_client_credentials(): void
    {
        $workspace = $this->workspace();

        $this->withBasicAuth($workspace->client_id, 'wrong-secret')
            ->postJson(route('api.billing.usage'), [
                'property_count' => 1,
                'rented_property_count' => 0,
                'measured_at' => now()->toIso8601String(),
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_client');
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->create([
            'name' => 'Tipi',
            'slug' => 'tipi',
            'base_url' => 'https://tipi.naboo.cloud',
            'callback_url' => 'https://tipi.naboo.cloud/sso/callback',
            'client_id' => 'client-tipi',
            'client_secret_hash' => Hash::make('workspace-secret'),
            'is_active' => true,
            'billing_enforced' => true,
        ]);
    }
}
