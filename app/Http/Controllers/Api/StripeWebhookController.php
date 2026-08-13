<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use App\Models\Workspace;
use App\Services\StripeBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeBillingService $stripe): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            return response()->json(['error' => 'Webhook no configurado.'], 503);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return response()->json(['error' => 'Firma no válida.'], 400);
        }

        if (StripeWebhookEvent::query()->where('stripe_event_id', $event->id)->exists()) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $object = $event->data->object;
        $data = $object->toArray();
        $workspace = $this->resolveWorkspace($data);

        try {
            if ($workspace) {
                $this->process($event->type, $workspace, $object, $data, $stripe);
            }

            StripeWebhookEvent::query()->create([
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'workspace_id' => $workspace?->getKey(),
                'processed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'No fue posible procesar el evento.'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function process(
        string $type,
        Workspace $workspace,
        object $object,
        array $data,
        StripeBillingService $stripe,
    ): void {
        if ($type === 'checkout.session.completed' && ! empty($data['subscription'])) {
            $workspace->forceFill([
                'stripe_customer_id' => (string) $data['customer'],
                'stripe_subscription_id' => (string) $data['subscription'],
            ])->save();
            $stripe->refreshSubscription($workspace, (string) $data['subscription']);

            return;
        }

        if (in_array($type, ['customer.subscription.created', 'customer.subscription.updated'], true)) {
            $stripe->applySubscription($workspace, $object);

            return;
        }

        if ($type === 'customer.subscription.deleted') {
            $workspace->forceFill([
                'subscription_status' => 'canceled',
                'billing_grace_ends_at' => null,
                'stripe_sync_pending' => false,
            ])->save();

            return;
        }

        if ($type === 'invoice.paid') {
            $workspace->forceFill([
                'subscription_status' => 'active',
                'billing_grace_ends_at' => null,
                'last_invoice_paid_at' => now(),
                'last_stripe_invoice_id' => (string) $data['id'],
            ])->save();

            return;
        }

        if ($type === 'invoice.payment_failed') {
            $graceEndsAt = $workspace->billing_grace_ends_at;
            if (! $graceEndsAt) {
                $graceEndsAt = now()->addDays($workspace->billing_grace_days);
            }

            $workspace->forceFill([
                'subscription_status' => 'past_due',
                'billing_grace_ends_at' => $graceEndsAt,
                'last_stripe_invoice_id' => (string) $data['id'],
            ])->save();
        }
    }

    private function resolveWorkspace(array $data): ?Workspace
    {
        $workspaceId = $data['metadata']['workspace_id']
            ?? $data['subscription_details']['metadata']['workspace_id']
            ?? $data['parent']['subscription_details']['metadata']['workspace_id']
            ?? null;

        if ($workspaceId) {
            return Workspace::query()->find($workspaceId);
        }

        $subscriptionId = $data['subscription']
            ?? $data['parent']['subscription_details']['subscription']
            ?? ($data['object'] === 'subscription' ? ($data['id'] ?? null) : null);

        return Workspace::query()
            ->when($subscriptionId, fn ($query) => $query->where('stripe_subscription_id', $subscriptionId))
            ->when(! $subscriptionId && ! empty($data['customer']), fn ($query) => $query->where('stripe_customer_id', $data['customer']))
            ->first();
    }
}
