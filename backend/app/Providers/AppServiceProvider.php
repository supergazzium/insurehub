<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Rbac\Rbac;
use App\Services\Mail\ZohoMailClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Built lazily so missing ZOHO_* env vars don't break unrelated boot paths;
        // ::isConfigured() lets callers skip resolution when the integration is off.
        $this->app->singleton(ZohoMailClient::class, fn () => ZohoMailClient::fromConfig());
        $this->app->singleton(Rbac::class, fn () => new Rbac());
    }

    public function boot(): void
    {
        // Password-reset links go to the Vue frontend, not the Laravel app.
        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            $base = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173/insurehub')), '/');
            return $base.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        // Single source of truth for every $user->can(...) / Gate::authorize(...)
        // / middleware('can:...') / @can(...) check in the app. Rbac handles
        // wildcard bypass, cached role→permission map, and per-user overrides.
        Gate::before(function (User $user, string $ability): ?bool {
            $rbac = app(Rbac::class);
            return $rbac->userHasPermission($user, $ability) ? true : null;
        });
    }
}
