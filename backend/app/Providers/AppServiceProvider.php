<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Mail\ZohoMailClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Built lazily so missing ZOHO_* env vars don't break unrelated boot paths;
        // ::isConfigured() lets callers skip resolution when the integration is off.
        $this->app->singleton(ZohoMailClient::class, fn () => ZohoMailClient::fromConfig());
    }

    public function boot(): void
    {
        //
    }
}
