1<?php

    namespace Tests\Feature;

    use App\Models\Workspace;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Hash;
    use Tests\TestCase;

    class StripeWebhookTest extends TestCase
    {
        use RefreshDatabase;

        public function test_payment_failure_starts_grace_period_and_event_is_idempotent(): void
        {
            config()->set('services.stripe.webhook_secret', 'whsec_test_secret');
            $workspace = $this->workspace();
            $payload = json_encode([
                'id' => 'evt_payment_failed_1',
                'object' => 'event',
                'type' => 'invoice.payment_failed',
                'data' => [
                    'object' => [
                        'id' => 'in_failed_1',
                        'object' => 'invoice',
                        'customer' => 'cus_workspace_1',
                        'metadata' => ['workspace_id' => (string) $workspace->id],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = 't=' . $timestamp . ',v1=' . hash_hmac(
                'sha256',
                $timestamp . '.' . $payload,
                'whsec_test_secret',
            );

            $this->withHeader('Stripe-Signature', $signature)
                ->postJson(route('api.stripe.webhook'), [], [], JSON_THROW_ON_ERROR)
                ->assertStatus(400);

            $response = $this->call('POST', route('api.stripe.webhook'), [], [], [], [
                'HTTP_STRIPE_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ], $payload);
            $response->assertOk();

            $workspace->refresh();
            $this->assertSame('past_due', $workspace->subscription_status);
            $this->assertTrue($workspace->billing_grace_ends_at->between(now()->addDays(4)->subMinute(), now()->addDays(4)->addMinute()));

            $this->call('POST', route('api.stripe.webhook'), [], [], [], [
                'HTTP_STRIPE_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ], $payload)->assertOk()->assertJsonPath('duplicate', true);

            $this->assertDatabaseCount('stripe_webhook_events', 1);
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
                'billing_grace_days' => 4,
                'stripe_customer_id' => 'cus_workspace_1',
            ]);
        }
    }
