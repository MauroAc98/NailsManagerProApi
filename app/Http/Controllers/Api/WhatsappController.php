<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EvolutionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WhatsappController extends Controller
{
    public function __construct(
        private EvolutionService $evolutionService,
    ) {}

    /**
     * POST /api/whatsapp/conectar
     * Recibe el número de la profesional y devuelve el pairing code
     * para que lo ingrese en WhatsApp → Dispositivos vinculados.
     */
    public function conectar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'numero' => 'required|string|min:10|max:15',
        ]);

        $user = $request->user();

        $pairingCode = $this->evolutionService->generarPairingCode($user, $data['numero']);

        if (!$pairingCode) {
            return response()->json([
                'message' => 'No se pudo generar el código de vinculación. Intentá de nuevo.',
            ], 422);
        }

        return response()->json([
            'pairing_code' => $pairingCode,
            'estado'       => 'conectando',
        ]);
    }

    /**
     * GET /api/whatsapp/estado
     * Consulta si el WhatsApp de la profesional ya está conectado.
     * La app puede llamar esto en loop (polling) mientras espera
     * que la profesional ingrese el pairing code.
     */
    public function estado(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->evolution_instance_name)) {
            return response()->json(['estado' => 'desconectado']);
        }

        $estadoReal = $this->evolutionService->consultarEstado($user);

        // Mapeamos el estado de Evolution API a nuestro propio enum
        $estado = match ($estadoReal) {
            'open'  => 'conectado',
            'close' => 'desconectado',
            default => 'conectando',
        };

        // Sincronizamos el estado en la DB si cambió
        if ($user->whatsapp_estado !== $estado) {
            $user->update(['whatsapp_estado' => $estado]);
        }

        return response()->json(['estado' => $estado]);
    }

    /**
     * DELETE /api/whatsapp/desconectar
     * Desconecta el WhatsApp de la profesional (logout completo).
     */
    public function desconectar(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->evolutionService->desconectar($user);

        return response()->json(['message' => 'WhatsApp desconectado correctamente.']);
    }
}