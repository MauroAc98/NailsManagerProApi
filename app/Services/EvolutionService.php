<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EvolutionService
{
    private string $baseUrl = '';

    private string $apiKey = '';

    public function __construct()
    {
        $this->baseUrl = config('services.evolution.url');
        $this->apiKey = config('services.evolution.key');
    }

    private function headers(): array
    {
        return [
            'apikey' => $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    public function crearInstancia(User $user): ?string
    {
        $instanceName = "user_{$user->id}";

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/instance/create", [
                'instanceName' => $instanceName,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ]);

        if (! $response->successful()) {
            Log::error('EvolutionService::crearInstancia falló', [
                'user_id' => $user->id,
                'body' => $response->body(),
            ]);

            return null;
        }

        $user->update([
            'evolution_instance_name' => $instanceName,
            'whatsapp_estado' => 'conectando',
        ]);

        return $instanceName;
    }

    public function generarQr(User $user): ?string
    {
        $instanciaNueva = false;

        if (empty($user->evolution_instance_name)) {
            $this->crearInstancia($user);
            $user->refresh();
            $instanciaNueva = true;
        }

        if (empty($user->evolution_instance_name)) {
            return null;
        }

        $instanceName = $user->evolution_instance_name;

        // El frontend refresca el QR cada 25s mientras espera el escaneo. El
        // logout de limpieza de abajo debe correr una sola vez por intento de
        // conexión, no en cada refresh: antes corría siempre que el estado
        // fuera desconectado/conectando (es decir, en TODOS los refreshes),
        // generando ciclos de logout+relink del dispositivo cada 25s — un
        // patrón que WhatsApp puede leer como automatización y arriesga el
        // número. La cache key marca que ya se hizo la limpieza para este
        // intento, con una ventana corta que se desliza en cada llamada: si
        // los refreshes siguen llegando cada 25s, la key nunca llega a vencer
        // entre ellos y es el mismo intento; si hay un salto real (el
        // usuario dejó pasar el intento y volvió, cerró la pestaña, etc.) la
        // key ya venció sola y el próximo pedido se trata como intento nuevo,
        // limpiando la sesión sucia como corresponde. Un TTL largo acá sería
        // un error: un reintento manual rápido después de "expiró" seguiría
        // cayendo dentro de la ventana y se saltearía la limpieza que ese
        // caso justamente necesita.
        $cacheKeyIntento = "evolution_qr_intento_{$user->id}";
        $esNuevoIntento = ! Cache::has($cacheKeyIntento);

        if (! $instanciaNueva && $esNuevoIntento && in_array($user->whatsapp_estado, ['desconectado', 'conectando'])) {
            Http::withHeaders($this->headers())
                ->delete("{$this->baseUrl}/instance/logout/{$instanceName}");
        }

        Cache::put($cacheKeyIntento, true, now()->addSeconds(45));

        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/instance/connect/{$instanceName}");

        if (! $response->successful()) {
            Log::error('EvolutionService::generarQr falló', [
                'user_id' => $user->id,
                'body' => $response->body(),
            ]);

            return null;
        }

        $base64 = $response->json('base64');

        if (empty($base64)) {
            Log::warning('EvolutionService::generarQr sin base64 en respuesta', [
                'user_id' => $user->id,
                'body' => $response->body(),
            ]);
        }

        return $base64;
    }

    public function consultarEstado(User $user): ?string
    {
        if (empty($user->evolution_instance_name)) {
            return null;
        }

        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/instance/connectionState/{$user->evolution_instance_name}");

        if (! $response->successful()) {
            return null;
        }

        return $response->json('instance.state');
    }

    /**
     * Envía un mensaje de texto.
     * Devuelve el message_id si fue exitoso, null si falló.
     */
    public function enviarMensaje(User $user, string $numero, string $mensaje): ?string
    {
        if (! $user->tieneWhatsappConectado()) {
            Log::warning('EvolutionService::enviarMensaje — usuario sin WhatsApp conectado', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/message/sendText/{$user->evolution_instance_name}", [
                'number' => $numero,
                'text' => $mensaje,
            ]);

        if (! $response->successful()) {
            $body = $response->body();

            if (str_contains($body, 'SessionError') || str_contains($body, 'No sessions')) {
                $user->update(['whatsapp_estado' => 'desconectado']);
                Log::warning('EvolutionService::enviarMensaje — sesión rota, instancia marcada desconectada', [
                    'user_id' => $user->id,
                ]);
            } else {
                Log::error('EvolutionService::enviarMensaje falló', [
                    'user_id' => $user->id,
                    'numero' => $numero,
                    'body' => $body,
                ]);
            }

            return null;
        }

        // Devolver el message_id para tracking
        return $response->json('key.id');
    }

    public function desconectar(User $user): bool
    {
        if (empty($user->evolution_instance_name)) {
            return true;
        }

        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/instance/logout/{$user->evolution_instance_name}");

        if (! $response->successful()) {
            Log::error('EvolutionService::desconectar falló', [
                'user_id' => $user->id,
                'body' => $response->body(),
            ]);

            return false;
        }

        Cache::forget("evolution_qr_intento_{$user->id}");
        $user->update(['whatsapp_estado' => 'desconectado']);

        return true;
    }
}
