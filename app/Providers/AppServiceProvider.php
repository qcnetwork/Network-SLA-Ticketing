<?php

namespace App\Providers;

use App\Services\TelegramOutbox;
use App\Support\TransactionModeSQLiteConnector;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PHP 8.4+ applies the sqlite `transaction_mode` natively; older versions need our connector.
        if (PHP_VERSION_ID < 80400) {
            $this->app->bind('db.connector.sqlite', TransactionModeSQLiteConnector::class);
        }

        // One outbox per request, so everything the request sends to Telegram goes out together.
        $this->app->scoped(TelegramOutbox::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One rule for every place a password is set: user create/edit and the user's own change.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }
}
