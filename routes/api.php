<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServicioController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\SlotDisponibleController;
use App\Http\Controllers\Api\TurnoController;
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

    // Turnos
    Route::prefix('turnos')->group(function () {
        Route::get('marcas',           [TurnoController::class, 'marcas']);
        Route::get('disponibilidad',   [TurnoController::class, 'disponibilidad']);
        Route::get('/',                [TurnoController::class, 'index']);
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

    Route::prefix('whatsapp')->group(function () {
        Route::post('conectar',      [WhatsappController::class, 'conectar']);
        Route::get('estado',         [WhatsappController::class, 'estado']);
        Route::delete('desconectar', [WhatsappController::class, 'desconectar']);
    });
});

// ─────────────────────────────────────────────
// Webhook Mercado Pago — sin auth, con firma HMAC
// ─────────────────────────────────────────────
Route::post('webhooks/mercadopago', [ReservaWebController::class, 'webhookMercadoPago']);

// ─────────────────────────────────────────────
// Webhook Evolution API — sin auth, lo llama el propio servicio
// ─────────────────────────────────────────────
Route::post('webhooks/evolution', [EvolutionWebhookController::class, 'handle']);
