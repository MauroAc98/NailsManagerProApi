<?php

namespace App\Providers;

use App\Services\Reservas\ChallengeVerifier;
use App\Services\Reservas\NullChallengeVerifier;
use App\Services\Reservas\NullVerificadorWhatsapp;
use App\Services\Reservas\TurnstileVerifier;
use App\Services\Reservas\VerificadorWhatsapp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Verificacion de WhatsApp: stub por defecto (todavia no hay proveedor).
        $this->app->bind(VerificadorWhatsapp::class, NullVerificadorWhatsapp::class);

        // Reto anti-bot: Null salvo que reservas.challenge.habilitado este encendido.
        $this->app->bind(ChallengeVerifier::class, fn () => config('reservas.challenge.habilitado')
            ? new TurnstileVerifier()
            : new NullChallengeVerifier());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // admin/login ya tiene su propio throttle:5,1 inline (routes/api.php)
        // — este limiter cubre el resto de admin/* (auth:admin). Keyed por
        // admin id cuando hay sesión, IP como fallback (nunca debería pasar
        // detrás de auth:admin, pero evita romper si algún día se usa suelto).
        RateLimiter::for('admin', function (Request $request) {
            $key = $request->user('admin')?->id ?? $request->ip();

            return Limit::perMinute(30)->by("admin:{$key}");
        });
    }
}
