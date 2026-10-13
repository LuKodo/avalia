<?php

namespace App\Providers;

use App\Models\Orden;
use App\Models\Paquete;
use App\Policies\OrdenPolicy;
use App\Policies\PaquetePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class LtpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Orden::class, OrdenPolicy::class);
        Gate::policy(Paquete::class, PaquetePolicy::class);

        // Límite de tasa por token y no solo por IP: un hospital entero detrás
        // de un mismo NAT no puede quedar bloqueado por el tráfico de otro.
        RateLimiter::for('api', function ($request) {
            $actor = $request->user();

            return $actor
                ? \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by('actor:'.$actor->getKey())
                : \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($request->ip());
        });
    }
}
