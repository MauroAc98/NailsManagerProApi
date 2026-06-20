<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EvolutionService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.evolution.url');
        $this->apiKey  = config('services.evolution.key');
    }

    private function headers(): array
    {
        return [
            'apikey'       => $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Crea una instancia nueva en Evolution API para un usuario.
     * Se llama una sola vez, la primera vez que la profesional
     * quiere conectar su WhatsApp. No inicia el QR automáticamente
     * (qrcode: false) — el connect se hace en un segundo paso,
     * que es el flujo validado para pairing code.
     */
    public function crearInstancia(User $user): ?string
    {
        $instanceName = "user_{$user->id}";

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/instance/create", [
                'instanceName' => $instanceName,
                'qrcode'       => false,
                'integration'  => 'WHATSAPP-BAILEYS',
            ]);

        if (!$response->successful()) {
            Log::error('EvolutionService::crearInstancia falló', [
                'user_id' => $user->id,
                'body'    => $response->body(),
            ]);
            return null;
        }

        $user->update([
            'evolution_instance_name' => $instanceName,
            'whatsapp_estado'         => 'conectando',
        ]);

        return $instanceName;
    }

    /**
     * Solicita un pairing code para vincular el WhatsApp de la profesional
     * a su instancia. Si la instancia no existe todavía, la crea primero.
     *
     * Flujo validado: create (sin qrcode) → connect?number=...
     */
    public function generarPairingCode(User $user, string $numero): ?string
    {
        if (empty($user->evolution_instance_name)) {
            $this->crearInstancia($user);
            $user->refresh();
        }

        if (empty($user->evolution_instance_name)) {
            return null; // crearInstancia falló
        }

        $instanceName = $user->evolution_instance_name;

        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/instance/connect/{$instanceName}", [
                'number' => $numero,
            ]);

        if (!$response->successful()) {
            Log::error('EvolutionService::generarPairingCode falló', [
                'user_id' => $user->id,
                'body'    => $response->body(),
            ]);
            return null;
        }

        $pairingCode = $response->json('pairingCode');

        if (empty($pairingCode)) {
            Log::warning('EvolutionService::generarPairingCode sin pairingCode en respuesta', [
                'user_id' => $user->id,
                'body'    => $response->body(),
            ]);
        }

        return $pairingCode;
    }

    /**
     * Consulta el estado de conexión de la instancia.
     * Devuelve: 'open' (conectado) | 'close' (desconectado) | 'connecting'
     */
    public function consultarEstado(User $user): ?string
    {
        if (empty($user->evolution_instance_name)) {
            return null;
        }

        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/instance/connectionState/{$user->evolution_instance_name}");

        if (!$response->successful()) {
            return null;
        }

        return $response->json('instance.state');
    }

    /**
     * Envía un mensaje de texto a un número de WhatsApp
     * usando la instancia de la profesional.
     */
    public function enviarMensaje(User $user, string $numero, string $mensaje): bool
    {
        if (!$user->tieneWhatsappConectado()) {
            Log::warning('EvolutionService::enviarMensaje — usuario sin WhatsApp conectado', [
                'user_id' => $user->id,
            ]);
            return false;
        }

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/message/sendText/{$user->evolution_instance_name}", [
                'number' => $numero,
                'text'   => $mensaje,
            ]);

        if (!$response->successful()) {
            Log::error('EvolutionService::enviarMensaje falló', [
                'user_id' => $user->id,
                'numero'  => $numero,
                'body'    => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Desconecta y elimina la instancia (logout completo).
     */
    public function desconectar(User $user): bool
    {
        if (empty($user->evolution_instance_name)) {
            return true;
        }

        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/instance/logout/{$user->evolution_instance_name}");

        $user->update(['whatsapp_estado' => 'desconectado']);

        return $response->successful();
    }
}