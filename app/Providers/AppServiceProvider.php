<?php

namespace App\Providers;

use App\Services\Reservas\ChallengeVerifier;
use App\Services\Reservas\NullChallengeVerifier;
use App\Services\Reservas\NullVerificadorWhatsapp;
use App\Services\Reservas\ReputacionService;
use App\Services\Reservas\TurnstileVerifier;
use App\Services\Reservas\VerificadorWhatsapp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        $this->registrarLimitadoresReservaOnline();
    }

    /**
     * Throttles de la reserva online. Se keyean por dispositivo (hash del
     * X-Device-Token), por token de reserva o por hash de telefono: NUNCA por
     * IP (un salon con wifi compartido no debe bloquearse entre clientas).
     * El 429 lleva {message, code: rate_limited, retry_after_seconds}.
     */
    private function registrarLimitadoresReservaOnline(): void
    {
        $limite = function (Limit $limit, string $motivo): Limit {
            return $limit->response(function (Request $request, array $headers) use ($motivo) {
                Log::info('reserva.abuse.rate_limited', [
                    'device_prefix' => substr((string) $request->attributes->get('device_hash', ''), 0, 8) ?: null,
                    'motivo' => $motivo,
                ]);

                return response()->json([
                    'message' => 'Demasiados intentos. Probá de nuevo en un rato.',
                    'code' => 'rate_limited',
                    'retry_after_seconds' => (int) ($headers['Retry-After'] ?? 60),
                ], 429, $headers);
            });
        };
        $dispositivo = fn (Request $r) => sha1((string) $r->header('X-Device-Token'));
        $token = fn (Request $r) => (string) $r->route('token');

        RateLimiter::for('reservas-hold', fn (Request $r) => [
            $limite(Limit::perMinute(3)->by('rh:m:' . $dispositivo($r)), 'hold_por_minuto'),
            $limite(Limit::perHour(10)->by('rh:h:' . $dispositivo($r)), 'hold_por_hora'),
        ]);

        RateLimiter::for('reservas-datos', function (Request $r) use ($limite, $token) {
            $limites = [$limite(Limit::perMinute(5)->by('rd:t:' . $token($r)), 'datos_por_minuto')];
            $telefono = (string) $r->input('whatsapp', '');
            if (strlen(preg_replace('/\D/', '', $telefono)) >= 8) {
                $limites[] = $limite(Limit::perHour(5)->by('rd:p:' . app(ReputacionService::class)->hashTelefono($telefono)), 'datos_por_telefono');
            }

            return $limites;
        });

        RateLimiter::for('reservas-pago', fn (Request $r) => $limite(Limit::perMinute(5)->by('rp:t:' . $token($r)), 'pago_por_minuto'));
        RateLimiter::for('reservas-estado', fn (Request $r) => $limite(Limit::perMinute(60)->by('re:t:' . $token($r)), 'estado_por_minuto'));
    }
}
