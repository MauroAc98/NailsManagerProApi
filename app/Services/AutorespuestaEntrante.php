<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\User;
use App\Models\WhatsappMensaje;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
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
    private const HORAS_VENTANA = 24;
    private const MAX_RESPUESTAS_POR_VENTANA = 3;
    private const MINUTOS_ENTRE_RESPUESTAS = 10;

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

        // Meta reenvia webhooks: el mismo mensaje entrante no se contesta dos veces.
        $idEntrante = $mensaje['id'] ?? null;
        $claveMensaje = is_string($idEntrante) && $idEntrante !== '' ? 'whatsapp:autorespuesta:msg:'.$idEntrante : null;
        if ($claveMensaje !== null && ! Cache::add($claveMensaje, 1, now()->addHours(48))) {
            return;
        }

        $clave = 'whatsapp:autorespuesta:estado:'.$this->sufijo($from);

        try {
            $decision = Cache::lock($clave.':lock', 10)->block(3, fn () => $this->decidir($clave));
        } catch (LockTimeoutException) {
            $this->liberar($claveMensaje);

            return;
        }

        if ($decision === null) {
            return; // ya se le respondio hace poco, o llego al maximo del dia
        }

        try {
            $resultado = $this->cloudApi->enviarTexto($from, $this->texto($user, $decision['completo']));
        } catch (\Throwable $e) {
            $this->deshacer($clave, $decision['previo'], $claveMensaje);
            Log::warning('whatsapp.autorespuesta.error_de_red', ['error' => $e->getMessage()]);

            return;
        }

        if ($resultado->messageId === null) {
            $this->deshacer($clave, $decision['previo'], $claveMensaje); // que un proximo mensaje pueda reintentar
            Log::warning('whatsapp.autorespuesta.no_enviada', ['status' => $resultado->statusCode]);

            return;
        }

        Log::info('whatsapp.autorespuesta.enviada', [
            'user_id' => $user?->id,
            'message_id' => $resultado->messageId,
            'completo' => $decision['completo'],
        ]);
    }

    /**
     * Decide si toca responder y con que. Primer mensaje de la ventana de 24 h:
     * aviso completo. Si insiste, un recordatorio corto, pero solo pasados
     * MINUTOS_ENTRE_RESPUESTAS de la ultima respuesta y hasta un maximo por
     * ventana (evita repetir el mismo texto largo ante varios mensajes
     * seguidos, que invita a bloquear o reportar el numero compartido, y corta
     * los bucles con otro bot). Reserva el turno ANTES de enviar, dentro del
     * lock, para que dos webhooks simultaneos no manden el mismo aviso.
     *
     * @return array{completo: bool, previo: ?array}|null null = no responder
     */
    private function decidir(string $clave): ?array
    {
        $ahora = now()->timestamp;
        $previo = Cache::get($clave);

        if (! is_array($previo)) {
            $estado = ['n' => 1, 'ultima' => $ahora, 'expira' => $ahora + self::HORAS_VENTANA * 3600];
            Cache::put($clave, $estado, Carbon::createFromTimestamp($estado['expira']));

            return ['completo' => true, 'previo' => null];
        }

        if ($previo['n'] >= self::MAX_RESPUESTAS_POR_VENTANA
            || $ahora - $previo['ultima'] < self::MINUTOS_ENTRE_RESPUESTAS * 60) {
            return null;
        }

        $estado = ['n' => $previo['n'] + 1, 'ultima' => $ahora, 'expira' => $previo['expira']];
        Cache::put($clave, $estado, Carbon::createFromTimestamp($estado['expira']));

        return ['completo' => false, 'previo' => $previo];
    }

    /** Si el envio fallo, la clienta vuelve al estado previo y Meta/ella pueden reintentar. */
    private function deshacer(string $clave, ?array $previo, ?string $claveMensaje): void
    {
        if ($previo === null) {
            Cache::forget($clave);
        } else {
            Cache::put($clave, $previo, Carbon::createFromTimestamp($previo['expira']));
        }
        $this->liberar($claveMensaje);
    }

    private function liberar(?string $claveMensaje): void
    {
        if ($claveMensaje !== null) {
            Cache::forget($claveMensaje);
        }
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

    private function texto(?User $user, bool $completo = true): string
    {
        $pt = $user?->locale === 'pt-BR';
        $telefono = $user !== null ? trim((string) $user->telefono) : '';
        $digitos = preg_replace('/\D/', '', $telefono);

        // Firmado como Turnetto (el sistema que manda el aviso), no como si
        // hablara la profesional en primera persona — mas claro y profesional
        // que un mensaje anonimo, y evita que se confunda con un intento de
        // suplantar a la profesional.
        $firma = $pt ? 'Somos a *Turnetto*' : 'Somos *Turnetto*';

        // Recordatorio corto para quien insiste: mismo mensaje en menos palabras,
        // para no repetir el texto largo entero.
        if (! $completo) {
            $recordatorio = $pt
                ? "{$firma}: este número não recebe mensagens, sua resposta não será lida."
                : "{$firma}: este número no recibe mensajes, tu respuesta no va a ser leída.";

            if ($user === null) {
                return $recordatorio."\n".($pt ? 'Escreva diretamente para a sua profissional.' : 'Escribile directamente a tu profesional.');
            }
            if ($digitos === '') {
                return $recordatorio."\n".($pt ? 'Escreva diretamente para ' : 'Escribile directamente a ').$user->name.'.';
            }

            return $recordatorio."\n".sprintf(
                $pt ? 'Para falar com *%s*, toque neste link:' : 'Para hablar con *%s* tocá este link:',
                $user->name,
            )."\n👉 https://wa.me/{$digitos}";
        }

        if ($pt) {
            $aviso = $firma.", o sistema de agendamentos de *:negocio:*.\n\nEste número envia apenas avisos automáticos e não recebe mensagens, então sua resposta não será lida.\n\n";
            $avisoSinNegocio = $firma.", o sistema de agendamentos da sua profissional.\n\nEste número envia apenas avisos automáticos e não recebe mensagens.\n\n";
            $consulta = 'Para dúvidas, alterações ou cancelamentos, ';
            $generico = 'escreva diretamente para a sua profissional, pelo número de sempre.';
            $conLink = 'toque neste link para abrir o chat direto com *%s*:';
            $ounumero = 'Ou salve o número e escreva: %s';
        } else {
            $aviso = $firma.", el sistema de turnos de *:negocio:*.\n\nEste número solo envía avisos automáticos y no recibe mensajes, así que tu respuesta no va a ser leída.\n\n";
            $avisoSinNegocio = $firma.", el sistema de turnos de tu profesional.\n\nEste número solo envía avisos automáticos y no recibe mensajes.\n\n";
            $consulta = 'Para consultas, cambios o cancelaciones, ';
            $generico = 'escribile directamente a tu profesional, por su número de siempre.';
            $conLink = 'tocá este link para abrir el chat directo con *%s*:';
            $ounumero = 'O guardá su número y escribile: %s';
        }

        if ($user === null) {
            return $avisoSinNegocio.$consulta.$generico;
        }

        $aviso = str_replace(':negocio:', $user->name, $aviso);

        // Sin telefono cargado no hay link ni numero que dar: solo el nombre.
        if ($digitos === '') {
            return $aviso.$consulta.($pt ? 'escreva diretamente para ' : 'escribile directamente a ').$user->name.'.';
        }

        // El link se presenta como una accion ("toca este link y se abre el chat")
        // en vez de pegarse suelto: mucha gente no sabe que wa.me abre el chat.
        return $aviso.$consulta.sprintf($conLink, $user->name)."\n👉 https://wa.me/{$digitos}\n\n".sprintf($ounumero, $telefono);
    }
}
