<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\TelegramCommands;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class TelegramWebhookController extends Controller
{
    /**
     * Telegram sends the secret set with setWebhook in a header; anything else is refused.
     */
    public function __invoke(Request $request, TelegramCommands $commands): Response
    {
        $secret = (string) Setting::read('webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        try {
            $commands->handle($request->json()->all());
        } catch (Throwable $e) {
            // Telegram redelivers an update until it gets a 2xx, so a failing command must not fail the request.
            report($e);
        }

        return response()->noContent();
    }
}
