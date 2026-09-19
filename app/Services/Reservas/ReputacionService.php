<?php

namespace App\Services\Reservas;

use App\Models\ReservaReputacion;

/**
 * Estado anti-abuso (siempre se registra; el enforcement de verificacion es
 * config-gated en HoldService). Claves = hmac_sha256(valor, app.key): el
 * telefono se normaliza a sus ultimos 10 digitos (mismo criterio que
 * Cliente::todosPorTelefono). Un hold liberado voluntariamente NO se registra.
 */
class ReputacionService
{
    public function hashDevice(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function hashTelefono(string $telefono): string
    {
        $digitos = substr(preg_replace('/\D/', '', $telefono), -10);

        return hash_hmac('sha256', 'tel:' . $digitos, (string) config('app.key'));
    }

    /** Un hold vencido sin pago: suma en cada clave y arma el cooldown del telefono. */
    public function registrarVencido(int $userId, ?string $deviceHash, ?string $phoneHash, int $ahora): void
    {
        $ventana = (int) config('reservas.verificacion_ventana_horas') * 3600;

        foreach (['device' => $deviceHash, 'phone' => $phoneHash] as $kind => $hash) {
            if ($hash === null) {
                continue;
            }

            $fila = ReservaReputacion::firstOrNew(['user_id' => $userId, 'kind' => $kind, 'key_hash' => $hash]);
            $dentroDeVentana = $fila->ultimo_expirado_en !== null && ($ahora - $fila->ultimo_expirado_en) <= $ventana;
            $fila->expirados_sin_pago = $dentroDeVentana ? $fila->expirados_sin_pago + 1 : 1;
            $fila->ultimo_expirado_en = $ahora;
            if ($kind === 'phone') {
                $fila->bloqueado_hasta = $ahora + (int) config('reservas.cooldown_telefono_minutos') * 60;
            }
            $fila->save();
        }
    }

    /** Segundos que faltan de cooldown para ese telefono (0 = libre). */
    public function cooldownRestante(int $userId, string $phoneHash, int $ahora): int
    {
        $hasta = ReservaReputacion::where(['user_id' => $userId, 'kind' => 'phone', 'key_hash' => $phoneHash])
            ->value('bloqueado_hasta');

        return $hasta !== null && $hasta > $ahora ? (int) $hasta - $ahora : 0;
    }

    /**
     * Estado puro: ¿alguna de las claves acumula >= umbral vencidos sin pago
     * dentro de la ventana y no tiene una verificacion vigente?
     */
    public function necesitaVerificacion(int $userId, ?string $deviceHash, ?string $phoneHash, int $ahora): bool
    {
        $umbral = (int) config('reservas.verificacion_umbral');
        $ventana = (int) config('reservas.verificacion_ventana_horas') * 3600;

        foreach (['device' => $deviceHash, 'phone' => $phoneHash] as $kind => $hash) {
            if ($hash === null) {
                continue;
            }
            $fila = ReservaReputacion::where(['user_id' => $userId, 'kind' => $kind, 'key_hash' => $hash])->first();
            if (! $fila || $fila->ultimo_expirado_en === null) {
                continue;
            }
            if (($ahora - $fila->ultimo_expirado_en) > $ventana || $fila->expirados_sin_pago < $umbral) {
                continue;
            }
            if ($fila->verificado_hasta !== null && $fila->verificado_hasta > $ahora) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function marcarVerificado(int $userId, string $kind, string $keyHash, int $hastaEpoch): void
    {
        $fila = ReservaReputacion::firstOrNew(['user_id' => $userId, 'kind' => $kind, 'key_hash' => $keyHash]);
        $fila->verificado_hasta = $hastaEpoch;
        $fila->save();
    }
}
