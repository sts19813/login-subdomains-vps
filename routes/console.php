<?php

use App\Models\SsoCode;
use App\Models\StripeWebhookEvent;
use App\Models\Workspace;
use App\Models\WorkspaceUsageSnapshot;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    SsoCode::query()
        ->where('expires_at', '<', now()->subDay())
        ->delete();
})->daily()->name('prune-expired-sso-codes')->withoutOverlapping();

Schedule::call(function (): void {
    $stripe = app(StripeBillingService::class);
    if (! $stripe->configured()) {
        return;
    }

    Workspace::query()
        ->where('stripe_sync_pending', true)
        ->whereNotNull('stripe_subscription_id')
        ->chunkById(50, function ($workspaces) use ($stripe): void {
            foreach ($workspaces as $workspace) {
                try {
                    $stripe->syncUsage($workspace);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
})->hourly()->name('sync-stripe-subscription-usage')->withoutOverlapping();

Schedule::call(function (): void {
    WorkspaceUsageSnapshot::query()->where('measured_at', '<', now()->subMonths(18))->delete();
    StripeWebhookEvent::query()->where('processed_at', '<', now()->subMonths(6))->delete();
})->daily()->name('prune-billing-audit-data')->withoutOverlapping();
