<?php

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

// Outside the web group: no session, cookies or CSRF token; the webhook secret authenticates Telegram.
Route::post('/telegram/webhook', TelegramWebhookController::class)->middleware('throttle:120,1')->name('telegram.webhook');

// Customer directory for other applications, behind tokens from CUSTOMER_API_TOKENS (read) and
// CUSTOMER_API_WRITE_TOKENS (read, and set each customer's OLT/ODC/ODP).
Route::prefix('v1')->name('api.')->middleware('throttle:120,1')->group(function () {
    Route::middleware(AuthenticateApiToken::class)->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{code}', [CustomerController::class, 'show'])->name('customers.show');
    });
    Route::middleware(AuthenticateApiToken::class.':write')->group(function () {
        Route::patch('/customers/network', [CustomerController::class, 'updateNetworkBatch'])->name('customers.network.batch');
        Route::patch('/customers/{code}/network', [CustomerController::class, 'updateNetwork'])->name('customers.network');
    });
});
