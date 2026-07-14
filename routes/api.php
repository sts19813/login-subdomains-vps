<?php

use App\Http\Controllers\Api\SsoExchangeController;
use Illuminate\Support\Facades\Route;

Route::post('/sso/exchange', SsoExchangeController::class)
    ->middleware('throttle:sso-exchange')
    ->name('api.sso.exchange');
