<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Bloqueo temporal de login por cuenta, ademas del throttle por IP de la ruta
 * (que un atacante evita rotando IPs). Cuenta solo intentos FALLIDOS, con dos
 * limites: por (cuenta, IP) y por cuenta a secas (mayor, para no dejar afuera
 * a la duena por unos pocos intentos ajenos y aun asi frenar una botnet).
 * Un login correcto limpia ambos contadores.
 */
class BloqueoLogin
{
    private const VENTANA_SEGUNDOS = 15 * 60;

    public function __construct(
        private readonly string $ambito,
        private readonly int $maxPorCuentaEIp,
        private readonly int $maxPorCuenta,
    ) {}

    public static function negocio(): self
    {
        return new self('negocio', 5, 15);
    }

    public static function admin(): self
    {
        return new self('admin', 3, 6);
    }

    /** Segundos hasta poder reintentar, o null si no esta bloqueado. */
    public function segundosDeBloqueo(string $email, ?string $ip): ?int
    {
        foreach ([[$this->clavePar($email, $ip), $this->maxPorCuentaEIp], [$this->claveCuenta($email), $this->maxPorCuenta]] as [$clave, $max]) {
            if (RateLimiter::tooManyAttempts($clave, $max)) {
                return RateLimiter::availableIn($clave);
            }
        }

        return null;
    }

    public function registrarFallo(string $email, ?string $ip): void
    {
        RateLimiter::hit($this->clavePar($email, $ip), self::VENTANA_SEGUNDOS);
        RateLimiter::hit($this->claveCuenta($email), self::VENTANA_SEGUNDOS);
    }

    public function limpiar(string $email, ?string $ip): void
    {
        RateLimiter::clear($this->clavePar($email, $ip));
        RateLimiter::clear($this->claveCuenta($email));
    }

    private function claveCuenta(string $email): string
    {
        return "login-fallos:{$this->ambito}:" . sha1(strtolower($email));
    }

    private function clavePar(string $email, ?string $ip): string
    {
        return $this->claveCuenta($email) . ':' . sha1((string) $ip);
    }
}
