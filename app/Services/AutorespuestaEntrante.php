<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Respuesta automatica a los mensajes entrantes del numero de Cloud API
 * COMPARTIDO. Ese numero solo manda avisos de turnos y nadie lee lo que las
 * clientas contestan (ni la profesional lo recibe): se les avisa y se les da el
 * telefono del negocio al que pertenece su turno.
 *
 * Los numeros propios de negocios conectados no pasan por aca: ahi el entrante
 * cae a otro numero y esta decision queda para cuando se resuelva ese caso.
 */
class AutorespuestaEntrante
{
    private const HORAS_ENTRE_RESPUESTAS = 24;

    public function __construct(private readonly CloudApiService $cloudApi) {}

    public function procesar(array $change): void
    {
        if (! config('services.whatsapp_cloud.autorespuesta_habilitada', true)) {
            return;
        }

        $value = is_array($change['value'] ?? null) ? $change['value'] : [];

        $compartido = config('services.whatsapp_cloud.phone_number_id');
        if (empty($compartido) || ($value['metadata']['phone_number_id'] ?? null) !== $compartido) {
            return;
        }

        foreach ($value['messages'] ?? [] as $mensaje) {
            $this->responderA($mensaje);
        }
    }

    private function responderA(array $mensaje): void
    {
        $from = $mensaje['from'] ?? null;
        $tipo = $mensaje['type'] ?? '';

        // Una reaccion (👍) no es una consulta: contestarla es ruido.
        if (! is_string($from) || $from === '' || in_array($tipo, ['reaction', 'system'], true)) {
            return;
        }

        $user = $this->resolverNegocio($mensaje, $from);

        if ($user !== null && $this->pidioNoRecibir($user, $from)) {
            return;
        }

        // Una sola respuesta por persona cada 24 h: evita repetirse ante varios
        // mensajes seguidos, reintentos de Meta o dos bots hablandose entre si.
        $clave = 'whatsapp:autorespuesta:'.$this->sufijo($from);
        if (! Cache::add($clave, 1, now()->addHours(self::HORAS_ENTRE_RESPUESTAS))) {
            return;
        }

        try {
            $resultado = $this->cloudApi->enviarTexto($from, $this->texto($user));
        } catch (\Throwable $e) {
            Cache::forget($clave);
            Log::warning('whatsapp.autorespuesta.error_de_red', ['error' => $e->getMessage()]);

            return;
        }

        if ($resultado->messageId === null) {
            Cache::forget($clave); // que un proximo mensaje pueda reintentar
            Log::warning('whatsapp.autorespuesta.no_enviada', ['status' => $resultado->statusCode]);

            return;
        }

        Log::info('whatsapp.autorespuesta.enviada', [
            'user_id' => $user?->id,
            'message_id' => $resultado->messageId,
        ]);
    }

    /**
     * A que negocio pertenece la clienta: primero por el mensaje nuestro al que
     * esta contestando (context.id); si no, por el ultimo mensaje que le
     * mandamos a ese numero. Null si nunca le escribimos.
     */
    private function resolverNegocio(array $mensaje, string $from): ?User
    {
        $contextId = $mensaje['context']['id'] ?? null;
        if (is_string($contextId) && $contextId !== '') {
            $registro = WhatsappMensaje::where('message_id', $contextId)->first();
            if ($registro !== null) {
                return $registro->user;
            }
        }

        $sufijo = $this->sufijo($from);
        $registro = WhatsappMensaje::where('numero', 'like', '%'.$sufijo)
            ->where(fn ($q) => $q->whereNull('provider')->orWhere('provider', '!=', 'cloud_api_tenant'))
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->first(fn (WhatsappMensaje $m) => $this->sufijo($m->numero) === $sufijo);

        return $registro?->user;
    }

    private function pidioNoRecibir(User $user, string $from): bool
    {
        $sufijo = $this->sufijo($from);

        return Cliente::where('user_id', $user->id)
            ->where('whatsapp_opt_out', true)
            ->get()
            ->contains(fn (Cliente $c) => $this->sufijo((string) $c->telefono) === $sufijo);
    }

    /**
     * Ultimos 10 digitos: iguala '5493764123456' (Meta, con el 9 de los
     * celulares argentinos), '543764123456' (guardado sin el 9) y
     * '3764123456' (sin codigo de pais).
     */
    private function sufijo(string $numero): string
    {
        return substr(preg_replace('/\D/', '', $numero), -10);
    }

    private function texto(?User $user): string
    {
        $pt = $user?->locale === 'pt-BR';
        $telefono = $user !== null ? trim((string) $user->telefono) : '';
        $digitos = preg_replace('/\D/', '', $telefono);

        if ($pt) {
            $base = "Olá 👋 Este número envia apenas avisos automáticos de agendamentos e não recebe mensagens, então ninguém vai ler a sua resposta.\n\nPara dúvidas, alterações ou cancelamentos, escreva diretamente para ";
            $generico = 'a sua profissional, pelo número de sempre.';
        } else {
            $base = "Hola 👋 Este número envía solo avisos automáticos de turnos y no recibe mensajes, así que nadie va a leer tu respuesta.\n\nPara consultas, cambios o cancelaciones escribile directamente a ";
            $generico = 'tu profesional, por su número de siempre.';
        }

        if ($user === null) {
            return $base.$generico;
        }

        if ($digitos === '') {
            return $base.$user->name.'.';
        }

        return $base.$user->name.": {$telefono}\nhttps://wa.me/{$digitos}";
    }
}
