<?php

use App\Models\SsoCode;
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
