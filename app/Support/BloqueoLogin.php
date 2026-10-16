<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Bloqueo temporal de login por cuenta, ademas del throttle por IP de la ruta
 * (que un atacante evita rotando IPs). Cuenta solo intentos FALLIDOS, con hasta
 * tres limites:
 *  - por (cuenta, IP), siempre;
 *  - por cuenta a secas (mayor, para no dejar afuera a la duena por unos pocos
 *    intentos ajenos y aun asi frenar una botnet) — opcional;
 *  - por IP contra cualquier cuenta (frena el spray de emails desde una IP) — opcional.
 * Un login correcto limpia los contadores del par y de la cuenta.
 *
 * El admin NO usa el limite por cuenta a secas: seria un DoS trivial (cualquiera
 * tira fallos desde cualquier IP y deja afuera al unico admin). El tope por IP no
 * se limpia con un login correcto: no es un contador de la cuenta.
 */
class BloqueoLogin
{
    private const VENTANA_SEGUNDOS = 15 * 60;

    public function __construct(
        private readonly string $ambito,
        private readonly int $maxPorCuentaEIp,
        private readonly ?int $maxPorCuenta = null,
        private readonly ?int $maxPorIp = null,
    ) {}

    public static function negocio(): self
    {
        return new self('negocio', 5, 15);
    }

    public static function admin(): self
    {
        return new self('admin', 3, maxPorIp: 10);
    }

    // Portal de profesionales: sin bloqueo por cuenta a secas (mismo motivo que
    // el admin) y con ambito propio, para que fallar en /auth/login o
    // /admin/login nunca bloquee /pro/login con el mismo email.
    public static function profesional(): self
    {
        return new self('profesional', 5, maxPorIp: 30);
    }

    /** Segundos hasta poder reintentar, o null si no esta bloqueado. */
    public function segundosDeBloqueo(string $email, ?string $ip): ?int
    {
        $limites = [[$this->clavePar($email, $ip), $this->maxPorCuentaEIp]];

        if ($this->maxPorCuenta !== null) {
            $limites[] = [$this->claveCuenta($email), $this->maxPorCuenta];
        }
        if ($this->maxPorIp !== null) {
            $limites[] = [$this->claveIp($ip), $this->maxPorIp];
        }

        foreach ($limites as [$clave, $max]) {
            if (RateLimiter::tooManyAttempts($clave, $max)) {
                return RateLimiter::availableIn($clave);
            }
        }

        return null;
    }

    public function registrarFallo(string $email, ?string $ip): void
    {
        RateLimiter::hit($this->clavePar($email, $ip), self::VENTANA_SEGUNDOS);

        if ($this->maxPorCuenta !== null) {
            RateLimiter::hit($this->claveCuenta($email), self::VENTANA_SEGUNDOS);
        }
        if ($this->maxPorIp !== null) {
            RateLimiter::hit($this->claveIp($ip), self::VENTANA_SEGUNDOS);
        }
    }

    public function limpiar(string $email, ?string $ip): void
    {
        RateLimiter::clear($this->clavePar($email, $ip));

        if ($this->maxPorCuenta !== null) {
            RateLimiter::clear($this->claveCuenta($email));
        }
    }

    private function claveCuenta(string $email): string
    {
        return "login-fallos:{$this->ambito}:" . sha1(strtolower($email));
    }

    private function clavePar(string $email, ?string $ip): string
    {
        return $this->claveCuenta($email) . ':' . sha1((string) $ip);
    }

    private function claveIp(?string $ip): string
    {
        return "login-fallos:{$this->ambito}:ip:" . sha1((string) $ip);
    }
}
