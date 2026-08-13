<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkspaceUsageSnapshot;
use App\Services\StripeBillingService;
use App\Services\WorkspaceClientAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkspaceUsageController extends Controller
{
    public function __invoke(
        Request $request,
        WorkspaceClientAuthenticator $authenticator,
        StripeBillingService $stripe,
    ): JsonResponse {
        $workspace = $authenticator->fromRequest($request);
        if (! $workspace) {
            return response()->json([
                'error' => 'invalid_client',
                'message' => 'Las credenciales del cliente no son válidas.',
            ], 401);
        }

        $validated = $request->validate([
            'property_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'rented_property_count' => ['required', 'integer', 'min:0', 'max:1000000', 'lte:property_count'],
            'measured_at' => ['required', 'date'],
        ]);

        $measuredAt = CarbonImmutable::parse($validated['measured_at'])->utc();
        if ($measuredAt->isAfter(now()->addMinutes(5)) || $measuredAt->isBefore(now()->subDays(7))) {
            return response()->json([
                'error' => 'invalid_measurement_time',
                'message' => 'La medición debe pertenecer a los últimos 7 días y no estar en el futuro.',
            ], 422);
        }

        $workspace = DB::transaction(function () use ($workspace, $validated, $measuredAt) {
            $workspace = $workspace->newQuery()->lockForUpdate()->findOrFail($workspace->getKey());
            $propertyCount = (int) $validated['property_count'];
            $rentedCount = (int) $validated['rented_property_count'];
            $vacantCount = $propertyCount - $rentedCount;

            WorkspaceUsageSnapshot::query()->firstOrCreate([
                'workspace_id' => $workspace->getKey(),
                'measured_at' => $measuredAt,
            ], [
                'property_count' => $propertyCount,
                'rented_property_count' => $rentedCount,
                'vacant_property_count' => $vacantCount,
                'vacant_property_unit_amount' => $workspace->vacant_property_unit_amount,
                'rented_property_unit_amount' => $workspace->rented_property_unit_amount,
                'calculated_amount' => ($vacantCount * $workspace->vacant_property_unit_amount)
                    + ($rentedCount * $workspace->rented_property_unit_amount),
            ]);

            if (! $workspace->metrics_reported_at || $measuredAt->isAfter($workspace->metrics_reported_at)) {
                $workspace->forceFill([
                    'property_count' => $propertyCount,
                    'rented_property_count' => $rentedCount,
                    'metrics_reported_at' => $measuredAt,
                    'stripe_sync_pending' => (bool) $workspace->stripe_subscription_id,
                ])->save();
            }

            return $workspace->fresh();
        });

        $synced = true;
        if ($workspace->stripe_sync_pending) {
            try {
                $stripe->syncUsage($workspace);
                $workspace->refresh();
            } catch (Throwable $exception) {
                report($exception);
                $synced = false;
            }
        }

        return response()->json([
            'workspace' => $workspace->slug,
            'usage' => [
                'properties' => $workspace->property_count,
                'rented_properties' => $workspace->rented_property_count,
                'vacant_properties' => $workspace->vacantPropertyCount(),
                'measured_at' => $workspace->metrics_reported_at?->toIso8601String(),
            ],
            'billing' => [
                'currency' => $workspace->billing_currency,
                'calculated_amount' => $workspace->calculatedMonthlyAmount(),
                'subscription_status' => $workspace->subscription_status,
                'access_allowed' => $workspace->subscriptionProvidesAccess(),
                'grace_ends_at' => $workspace->billing_grace_ends_at?->toIso8601String(),
                'stripe_synced' => $synced,
            ],
        ], $synced ? 200 : 202);
    }
}
