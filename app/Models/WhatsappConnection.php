<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

final class WhatsappConnection extends Model
{
    /**
     * Ventana de aviso previo al vencimiento del token, en segundos (7 días).
     * Dentro de esta ventana el estado pasa a `por_vencer` para que el admin
     * vea el prompt de reconexión antes de que el token efectivamente expire.
     */
    public const VENTANA_POR_VENCER = 7 * 24 * 60 * 60;

    protected $fillable = [
        'user_id',
        'waba_id',
        'phone_number_id',
        'display_phone_number',
        'verified_name',
        'access_token',
        'token_expires_at',
    ];

    /**
     * $hidden NO es opcional acá: con el cast 'encrypted', cualquier
     * ->toJson()/->toArray() accidental de la relación (es alcanzable desde un
     * User serializado) emitiría el token de tenant DESCIFRADO en una respuesta
     * HTTP. UserMpCredential lo omite y confía en no serializarse nunca; acá se
     * hace mejor.
     */
    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            // Epoch Unix crudo, NO 'datetime' — ver comentario en la migración
            // create_whatsapp_connections_table: evita el bug de timezone del
            // cast datetime de Eloquent en el round-trip escritura/lectura.
            'token_expires_at' => 'integer',
        ];
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Estado derivado ──────────────────────────────────────────
    // No hay columna `status`: el estado es función pura de
    // (existencia de fila + token_expires_at). El caso "no hay fila"
    // (`sin_conexion`) se computa a nivel User/serializer, no acá.
    public function getEstadoAttribute(): string
    {
        $vencimiento = $this->token_expires_at;

        if ($vencimiento === null) {
            return 'conectada';
        }

        $ahora = now()->timestamp;

        if ($vencimiento <= $ahora) {
            return 'expirada';
        }

        if ($vencimiento <= $ahora + self::VENTANA_POR_VENCER) {
            return 'por_vencer';
        }

        return 'conectada';
    }

    public function estaVigente(): bool
    {
        return $this->token_expires_at === null
            || $this->token_expires_at > now()->timestamp;
    }

    /**
     * Resuelve la conexión dueña de un webhook `phone_number_quality_update`
     * (§5 del diseño). Escalera, en orden:
     *
     *   1. `value.metadata.phone_number_id` → columna única, match exacto seguro.
     *   2. `value.display_phone_number` normalizado a dígitos → columna indexada;
     *      es el campo discriminante que sí trae este evento.
     *   3. `entry.id` como `waba_id` → ÚLTIMO RECURSO. `waba_id` NO es única: si
     *      matchea más de una fila se descarta (se loguea y se trata como no
     *      resuelto), porque escribiría el veredicto en el cache del tenant
     *      equivocado.
     *
     * Devuelve `null` si el `value` está ausente o no es un array, o si ningún
     * escalón resuelve.
     */
    public static function resolverDesdeWebhook(array $entry, array $change): ?self
    {
        $value = $change['value'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        if (is_string($phoneNumberId) && $phoneNumberId !== '') {
            $match = self::query()->where('phone_number_id', $phoneNumberId)->first();
            if ($match !== null) {
                return $match;
            }
        }

        $display = $value['display_phone_number'] ?? null;
        if ($display !== null && $display !== '') {
            $digitos = preg_replace('/\D/', '', (string) $display);
            if ($digitos !== '') {
                $match = self::query()->where('display_phone_number', $digitos)->first();
                if ($match !== null) {
                    return $match;
                }
            }
        }

        $wabaId = $entry['id'] ?? null;
        if (is_string($wabaId) && $wabaId !== '') {
            $matches = self::query()->where('waba_id', $wabaId)->limit(2)->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }

            if ($matches->count() > 1) {
                Log::warning('whatsapp.calidad.waba_ambiguo', ['entry_id' => $wabaId]);
            }
        }

        return null;
    }
}
