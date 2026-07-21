<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServicioController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\SlotDisponibleController;
use App\Http\Controllers\Api\TurnoController;
use App\Http\Controllers\Api\ProfesionalController;
use App\Http\Controllers\Api\ReservaWebController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\WhatsappController;
use App\Http\Controllers\Api\WhatsappTemplateController;
use App\Http\Controllers\Api\EvolutionWebhookController;
use App\Http\Controllers\Api\AdminController;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────
// Rutas públicas — sin autenticación
// ─────────────────────────────────────────────
Route::prefix('public/{slug}')->group(function () {
    Route::get('info',           [PublicController::class, 'info']);
    Route::get('servicios',      [PublicController::class, 'servicios']);
    Route::get('disponibilidad', [PublicController::class, 'disponibilidad']);
    Route::post('reservas',      [PublicController::class, 'store'])->middleware('throttle:10,1');
});

Route::get('support-info', [AuthController::class, 'supportInfo']);

// ─────────────────────────────────────────────
// Autenticación
// ─────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',    [AuthController::class, 'login']);

    Route::post('cambiar-password-obligatorio', [AuthController::class, 'cambiarPasswordObligatorio']);

    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);
        Route::get('subscription-status', [AuthController::class, 'subscriptionStatus']);
    });
});

// ─────────────────────────────────────────────
// Admin
// ─────────────────────────────────────────────
Route::post('admin/subscriptions/{user}/renew', [AdminController::class, 'renewSubscription']);
Route::post('admin/debug-secret', [AdminController::class, 'debugAdminSecret']); // TEMPORAL — sacar tras diagnosticar
Route::get('admin/whatsapp/instancias', [AdminController::class, 'whatsappInstancias']);
Route::get('admin/whatsapp/instancias/{user}/historial', [AdminController::class, 'whatsappHistorial']);

// ─────────────────────────────────────────────
// Rutas privadas — requieren Bearer Token
// ─────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'subscription.check'])->group(function () {

    // Perfil
    Route::put('perfil', [AuthController::class, 'updatePerfil']);

    // Servicios
    Route::apiResource('servicios', ServicioController::class);

    // Clientes
    Route::apiResource('clientes', ClienteController::class);

    // Slots disponibles
    Route::apiResource('slots', SlotDisponibleController::class);

    // Profesionales (multi-agenda)
    Route::apiResource('profesionales', ProfesionalController::class)->only([
        'index', 'store', 'update', 'destroy',
    ]);
    Route::post('profesionales/{id}/fondo-historia', [ProfesionalController::class, 'subirFondoHistoria']);
    Route::delete('profesionales/{id}/fondo-historia', [ProfesionalController::class, 'borrarFondoHistoria']);

    // Turnos
    Route::prefix('turnos')->group(function () {
        Route::get('marcas',           [TurnoController::class, 'marcas']);
        Route::get('disponibilidad',   [TurnoController::class, 'disponibilidad']);
        Route::get('/',                [TurnoController::class, 'index']);
        Route::get('/{id}',            [TurnoController::class, 'show']);
        Route::post('/',               [TurnoController::class, 'store']);
        Route::put('/{id}',            [TurnoController::class, 'update']);
        Route::patch('{id}/completar', [TurnoController::class, 'completar']);
        Route::delete('/{id}',         [TurnoController::class, 'destroy']);
    });

    // Reservas web — panel de aceptación
    Route::prefix('reservas')->group(function () {
        Route::get('/',              [ReservaWebController::class, 'index']);
        Route::post('{id}/aceptar',  [ReservaWebController::class, 'aceptar']);
        Route::post('{id}/rechazar', [ReservaWebController::class, 'rechazar']);
    });

    // Plantillas de mensajes WhatsApp
    Route::prefix('whatsapp-templates')->group(function () {
        Route::get('/',                 [WhatsappTemplateController::class, 'index']);
        Route::put('/{tipo}',           [WhatsappTemplateController::class, 'update']);
        Route::post('/{tipo}/resetear', [WhatsappTemplateController::class, 'resetear']);
    });

    // Límites por endpoint según su patrón de uso real: "estado" se
    // pollea cada 3s durante la espera de escaneo (~20/min legítimos),
    // "conectar" se refresca cada 25s (~3/min legítimos) más el click
    // inicial, "desconectar" es una acción manual esporádica. Los topes
    // dan margen holgado sobre el uso normal pero cortan cualquier loop
    // fuera de control antes de que le pegue sin límite a Evolution.
    Route::prefix('whatsapp')->group(function () {
        Route::post('conectar',      [WhatsappController::class, 'conectar'])->middleware('throttle:12,1');
        Route::get('estado',         [WhatsappController::class, 'estado'])->middleware('throttle:40,1');
        Route::delete('desconectar', [WhatsappController::class, 'desconectar'])->middleware('throttle:6,1');
    });
});

// ─────────────────────────────────────────────
// Webhook Mercado Pago — sin auth, con firma HMAC
// ─────────────────────────────────────────────
Route::post('webhooks/mercadopago', [ReservaWebController::class, 'webhookMercadoPago']);

// ─────────────────────────────────────────────
// Webhook Evolution API — la URL incluye un secreto como path param.
// Evolution no firma sus payloads, así que el control de acceso es la URL
// misma: solo quien conoce el secreto (nosotros, vía WEBHOOK_GLOBAL_URL en
// el docker-compose de Evolution) puede pegarle a esta ruta.
// ─────────────────────────────────────────────
Route::post('webhooks/evolution/{secret}', [EvolutionWebhookController::class, 'handle']);
