<?php

namespace App\Services;

use App\Models\User;
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

        // Limpiar sesión de Baileys antes de pedir QR cuando no está conectado.
        // Evita el estado sucio post-desconexión o reconexión fallida.
        // Si la instancia se acaba de crear en esta misma llamada no hay
        // sesión que limpiar: desloguearla acá la mata antes de que el
        // usuario pueda escanear el QR (era el bug de auto-logout en alta).
        if (! $instanciaNueva && in_array($user->whatsapp_estado, ['desconectado', 'conectando'])) {
            Http::withHeaders($this->headers())
                ->delete("{$this->baseUrl}/instance/logout/{$instanceName}");
        }

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

        $user->update(['whatsapp_estado' => 'desconectado']);

        return $response->successful();
    }
}
