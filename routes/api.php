<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Outside the web group: no session, cookies or CSRF token; the webhook secret authenticates Telegram.
Route::post('/telegram/webhook', TelegramWebhookController::class)->middleware('throttle:120,1')->name('telegram.webhook');
