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
     * Genera (o regenera) el código QR para vincular WhatsApp.
     * Devuelve la imagen en base64, lista para mostrar en la app.
     */
    public function conectar(Request $request): JsonResponse
    {
        $user = $request->user();

        $qrBase64 = $this->evolutionService->generarQr($user);

        if (!$qrBase64) {
            return response()->json([
                'message' => 'No se pudo generar el código QR. Intentá de nuevo.',
            ], 422);
        }

        return response()->json([
            'qr_base64' => $qrBase64,
            'estado'    => 'conectando',
        ]);
    }

    /**
     * GET /api/whatsapp/estado
     * Consulta si el WhatsApp de la profesional ya está conectado.
     * La app puede llamar esto en loop (polling) mientras espera
     * que la profesional escanee el QR.
     */
    public function estado(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->evolution_instance_name)) {
            return response()->json(['estado' => 'desconectado']);
        }

        $estadoReal = $this->evolutionService->consultarEstado($user);

        $estado = match ($estadoReal) {
            'open'  => 'conectado',
            'close' => 'desconectado',
            default => 'conectando',
        };

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

        if (!$this->evolutionService->desconectar($user)) {
            return response()->json([
                'message' => 'No se pudo desvincular WhatsApp. Intentá de nuevo.',
            ], 422);
        }

        return response()->json(['message' => 'WhatsApp desconectado correctamente.']);
    }
}