<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use RuntimeException;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\StripeClient;

class StripeBillingService
{
    public function configured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    public function createCheckout(Workspace $workspace, User $user): CheckoutSession
    {
        if (! $this->configured()) {
            throw new RuntimeException('Stripe no está configurado.');
        }

        if ($workspace->property_count < 1) {
            throw new RuntimeException('La instancia debe reportar al menos una propiedad antes de suscribirse.');
        }

        $customerId = $this->ensureCustomer($workspace, $user);
        $this->ensurePrices($workspace);

        $lineItems = [];
        if ($workspace->vacantPropertyCount() > 0) {
            $lineItems[] = [
                'price' => $workspace->stripe_vacant_price_id,
                'quantity' => $workspace->vacantPropertyCount(),
            ];
        }
        if ($workspace->rented_property_count > 0) {
            $lineItems[] = [
                'price' => $workspace->stripe_rented_price_id,
                'quantity' => $workspace->rented_property_count,
            ];
        }

        return $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $workspace->getKey(),
            'line_items' => $lineItems,
            'locale' => 'es',
            'billing_address_collection' => 'required',
            'payment_method_collection' => 'always',
            'success_url' => route('billing.show', $workspace).'?checkout=success',
            'cancel_url' => route('billing.show', $workspace).'?checkout=cancelled',
            'metadata' => [
                'workspace_id' => (string) $workspace->getKey(),
                'workspace_slug' => $workspace->slug,
            ],
            'subscription_data' => [
                'metadata' => [
                    'workspace_id' => (string) $workspace->getKey(),
                    'workspace_slug' => $workspace->slug,
                ],
            ],
        ], [
            'idempotency_key' => 'checkout-'.$workspace->getKey().'-'.now()->format('YmdHi'),
        ]);
    }

    public function createPortal(Workspace $workspace): BillingPortalSession
    {
        if (! $this->configured() || ! $workspace->stripe_customer_id) {
            throw new RuntimeException('El espacio todavía no tiene un cliente de Stripe.');
        }

        return $this->client()->billingPortal->sessions->create([
            'customer' => $workspace->stripe_customer_id,
            'return_url' => route('billing.show', $workspace),
            'locale' => 'es',
        ]);
    }

    public function syncUsage(Workspace $workspace): void
    {
        if (! $this->configured() || ! $workspace->stripe_subscription_id) {
            return;
        }

        $this->ensurePrices($workspace);
        $subscription = $this->client()->subscriptions->retrieve(
            $workspace->stripe_subscription_id,
            ['expand' => ['items.data.price']],
        );

        if (in_array($subscription->status, ['canceled', 'incomplete_expired'], true)) {
            return;
        }

        foreach ($subscription->items->data as $item) {
            if ($item->price->id === $workspace->stripe_vacant_price_id) {
                $workspace->stripe_vacant_item_id = $item->id;
            }
            if ($item->price->id === $workspace->stripe_rented_price_id) {
                $workspace->stripe_rented_item_id = $item->id;
            }
        }

        $items = [];
        $this->appendSubscriptionItem(
            $items,
            $workspace->stripe_vacant_item_id,
            $workspace->stripe_vacant_price_id,
            $workspace->vacantPropertyCount(),
        );
        $this->appendSubscriptionItem(
            $items,
            $workspace->stripe_rented_item_id,
            $workspace->stripe_rented_price_id,
            $workspace->rented_property_count,
        );

        if ($items !== []) {
            $subscription = $this->client()->subscriptions->update($subscription->id, [
                'items' => $items,
                'proration_behavior' => 'none',
                'metadata' => [
                    'workspace_id' => (string) $workspace->getKey(),
                    'workspace_slug' => $workspace->slug,
                ],
            ], [
                'idempotency_key' => 'usage-'.$workspace->getKey().'-'.$workspace->metrics_reported_at?->timestamp,
            ]);
        }

        $workspace->forceFill([
            'subscription_status' => $subscription->status,
            'subscription_period_ends_at' => $this->periodEnd($subscription),
            'stripe_sync_pending' => false,
        ])->save();

        $this->rememberSubscriptionItems($workspace, $subscription);
    }

    public function refreshSubscription(Workspace $workspace, string $subscriptionId): void
    {
        $subscription = $this->client()->subscriptions->retrieve(
            $subscriptionId,
            ['expand' => ['items.data.price']],
        );

        $this->applySubscription($workspace, $subscription);
        $this->syncUsage($workspace->fresh());
    }

    public function applySubscription(Workspace $workspace, object $subscription): void
    {
        $workspace->forceFill([
            'stripe_subscription_id' => (string) $subscription->id,
            'stripe_customer_id' => (string) ($subscription->customer ?? $workspace->stripe_customer_id),
            'subscription_status' => (string) $subscription->status,
            'subscription_period_ends_at' => $this->periodEnd($subscription),
            'billing_grace_ends_at' => in_array($subscription->status, ['active', 'trialing'], true)
                ? null
                : $workspace->billing_grace_ends_at,
        ])->save();

        $this->rememberSubscriptionItems($workspace, $subscription);
    }

    private function ensureCustomer(Workspace $workspace, User $user): string
    {
        if ($workspace->stripe_customer_id) {
            return $workspace->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'name' => $workspace->name,
            'email' => $workspace->billing_email ?: $user->email,
            'metadata' => [
                'workspace_id' => (string) $workspace->getKey(),
                'workspace_slug' => $workspace->slug,
            ],
        ], [
            'idempotency_key' => 'customer-workspace-'.$workspace->getKey(),
        ]);

        $workspace->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    private function ensurePrices(Workspace $workspace): void
    {
        if (! $workspace->stripe_product_id) {
            $product = $this->client()->products->create([
                'name' => 'Naboo · '.$workspace->name,
                'metadata' => [
                    'workspace_id' => (string) $workspace->getKey(),
                    'workspace_slug' => $workspace->slug,
                ],
            ], [
                'idempotency_key' => 'product-workspace-'.$workspace->getKey(),
            ]);
            $workspace->stripe_product_id = $product->id;
        }

        if (! $workspace->stripe_vacant_price_id) {
            $price = $this->createPrice($workspace, 'vacant', $workspace->vacant_property_unit_amount);
            $workspace->stripe_vacant_price_id = $price->id;
        }

        if (! $workspace->stripe_rented_price_id) {
            $price = $this->createPrice($workspace, 'rented', $workspace->rented_property_unit_amount);
            $workspace->stripe_rented_price_id = $price->id;
        }

        $workspace->save();
    }

    private function createPrice(Workspace $workspace, string $type, int $amount): object
    {
        return $this->client()->prices->create([
            'product' => $workspace->stripe_product_id,
            'currency' => $workspace->billing_currency,
            'unit_amount' => $amount,
            'recurring' => ['interval' => 'month'],
            'nickname' => $type === 'rented' ? 'Propiedad rentada' : 'Propiedad sin renta activa',
            'metadata' => [
                'workspace_id' => (string) $workspace->getKey(),
                'property_type' => $type,
            ],
        ], [
            'idempotency_key' => "price-{$workspace->getKey()}-{$type}-{$amount}-{$workspace->billing_currency}",
        ]);
    }

    private function appendSubscriptionItem(array &$items, ?string $itemId, string $priceId, int $quantity): void
    {
        if (! $itemId && $quantity === 0) {
            return;
        }

        $item = [
            'price' => $priceId,
            'quantity' => $quantity,
        ];

        if ($itemId) {
            $item['id'] = $itemId;
        }

        $items[] = $item;
    }

    private function rememberSubscriptionItems(Workspace $workspace, object $subscription): void
    {
        foreach (($subscription->items->data ?? []) as $item) {
            if (($item->price->id ?? null) === $workspace->stripe_vacant_price_id) {
                $workspace->stripe_vacant_item_id = $item->id;
            }
            if (($item->price->id ?? null) === $workspace->stripe_rented_price_id) {
                $workspace->stripe_rented_item_id = $item->id;
            }
        }

        $workspace->save();
    }

    private function periodEnd(object $subscription): ?int
    {
        if (isset($subscription->current_period_end)) {
            return (int) $subscription->current_period_end;
        }

        return isset($subscription->items->data[0]->current_period_end)
            ? (int) $subscription->items->data[0]->current_period_end
            : null;
    }

    private function client(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
