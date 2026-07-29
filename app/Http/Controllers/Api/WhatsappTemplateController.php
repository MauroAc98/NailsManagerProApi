<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WhatsappTemplateController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/whatsapp-templates
    // Lista todas las plantillas del usuario
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $templates = WhatsappTemplate::delUsuario($user)->get();

        // Si no existen, crear con valores por defecto
        if ($templates->isEmpty()) {
            foreach (['recordatorio', 'confirmacion'] as $tipo) {
                WhatsappTemplate::create([
                    'user_id' => $user->id,
                    'tipo' => $tipo,
                    'contenido' => WhatsappTemplate::plantillaDefault($tipo, $user->locale),
                ]);
            }
            $templates = WhatsappTemplate::delUsuario($user)->get();
        }

        return response()->json($templates);
    }

    // ─────────────────────────────────────────────
    // PUT /api/whatsapp-templates/{tipo}
    // Actualiza la plantilla de un tipo específico
    // ─────────────────────────────────────────────
    public function update(Request $request, string $tipo): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'contenido' => 'required|string|min:10|max:1000',
        ]);

        $template = WhatsappTemplate::delUsuario($user)
            ->delTipo($tipo)
            ->firstOrCreate(
                ['user_id' => $user->id, 'tipo' => $tipo],
                ['contenido' => WhatsappTemplate::plantillaDefault($tipo, $user->locale)]
            );

        $template->update($data);

        return response()->json($template);
    }

    // ─────────────────────────────────────────────
    // POST /api/whatsapp-templates/{tipo}/resetear
    // Resetea una plantilla a su valor por defecto
    // ─────────────────────────────────────────────
    public function resetear(Request $request, string $tipo): JsonResponse
    {
        $user = $request->user();

        $template = WhatsappTemplate::delUsuario($user)
            ->delTipo($tipo)
            ->firstOrFail();

        $template->update([
            'contenido' => WhatsappTemplate::plantillaDefault($tipo, $user->locale),
        ]);

        return response()->json($template);
    }
}