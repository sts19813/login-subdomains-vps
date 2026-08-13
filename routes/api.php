<?php

use App\Http\Controllers\Api\BillingEntitlementController;
use App\Http\Controllers\Api\SsoExchangeController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\WorkspaceUsageController;
use Illuminate\Support\Facades\Route;

Route::post('/sso/exchange', SsoExchangeController::class)
    ->middleware('throttle:sso-exchange')
    ->name('api.sso.exchange');

Route::post('/billing/usage', WorkspaceUsageController::class)
    ->middleware('throttle:billing-usage')
    ->name('api.billing.usage');

Route::post('/billing/entitlement', BillingEntitlementController::class)
    ->middleware('throttle:billing-entitlement')
    ->name('api.billing.entitlement');

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->name('api.stripe.webhook');
