<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServicioController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\SlotDisponibleController;
use App\Http\Controllers\Api\TurnoController;
use App\Http\Controllers\Api\ProfesionalController;
use App\Http\Controllers\Api\ReservaWebController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\CloudApiWebhookController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\GastoController;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────
// Rutas públicas — sin autenticación
// ─────────────────────────────────────────────
Route::prefix('public/{slug}')->group(function () {
    Route::get('info',           [PublicController::class, 'info']);
    Route::get('branding',       [PublicController::class, 'branding']);
    Route::get('servicios',      [PublicController::class, 'servicios']);
    Route::get('disponibilidad', [PublicController::class, 'disponibilidad']);
    Route::post('reservas',      [PublicController::class, 'store'])->middleware('throttle:10,1');
});

Route::get('support-info', [AuthController::class, 'supportInfo']);

// ─────────────────────────────────────────────
// Autenticación
// ─────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    // register() se mudó a POST admin/negocios — ver AdminController y el
    // grupo admin/* más abajo.
    Route::post('login',    [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::post('cambiar-password-obligatorio', [AuthController::class, 'cambiarPasswordObligatorio']);

    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);
        Route::get('subscription-status', [AuthController::class, 'subscriptionStatus']);
    });
});

// ─────────────────────────────────────────────
// Admin — identidad propia (guard `admin`, provider `admin_users`),
// desacoplada de la sesión tenant. Ver AdminUser / config/auth.php.
// ─────────────────────────────────────────────
Route::prefix('admin')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');

    // Limiter con nombre 'admin' (RateLimiter::for en AppServiceProvider,
    // 30/min por admin o IP) — admin/login queda afuera a propósito, ya
    // tiene su propio throttle:5,1 arriba.
    Route::middleware(['auth:admin', 'throttle:admin'])->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('me',      [AdminAuthController::class, 'me']);

        Route::post('subscriptions/{user}/renew', [AdminController::class, 'renewSubscription']);
        // Uso de Cloud API por salón (mensajes + conversaciones de 24hs estimadas) para cotejar costo real de Meta.
        Route::get('whatsapp/uso-por-salon', [AdminController::class, 'usoWhatsappPorSalon']);

        // Creación de negocio (movida de auth/register) y búsqueda puntual
        // por email/slug para el flujo de renovación — ver AdminController.
        Route::post('negocios',       [AdminController::class, 'crearNegocio']);
        Route::get('negocios/buscar', [AdminController::class, 'buscarNegocio']);
    });
});

// ─────────────────────────────────────────────
// Rutas privadas — requieren Bearer Token
// ─────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'subscription.check'])->group(function () {

    // Perfil
    Route::put('perfil',       [AuthController::class, 'updatePerfil']);
    Route::post('perfil/logo', [AuthController::class, 'subirLogo']);

    // Servicios
    Route::patch('servicios/reordenar', [ServicioController::class, 'reordenar']);
    Route::apiResource('servicios', ServicioController::class);

    // Gastos
    Route::apiResource('gastos', GastoController::class);

    // Clientes
    Route::apiResource('clientes', ClienteController::class);

    // Slots disponibles
    Route::apiResource('slots', SlotDisponibleController::class);

    // Profesionales (multi-agenda)
    // throttle en 'update' — desde el autosave debounced (700ms) de la nota
    // adicional de historia de precios (ver ProfesionalController::update,
    // guardarNotaHistoriaPrecios en el frontend), este endpoint se golpea
    // mucho más seguido que antes. 60/min da margen generoso a uso legítimo
    // (varios campos, tipeo rápido) mientras acota abuso scripteado. Solo en
    // 'update', no en todo el resource — index/store/destroy no tienen ese
    // patrón de uso.
    Route::apiResource('profesionales', ProfesionalController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('update', 'throttle:60,1');
    Route::post('profesionales/{id}/fondo-historia', [ProfesionalController::class, 'subirFondoHistoria']);
    Route::delete('profesionales/{id}/fondo-historia', [ProfesionalController::class, 'borrarFondoHistoria']);
    Route::post('profesionales/{id}/historia-precios-fotos', [ProfesionalController::class, 'subirHistoriaPreciosFoto']);
    Route::delete('profesionales/{id}/historia-precios-fotos/{fotoId}', [ProfesionalController::class, 'borrarHistoriaPreciosFoto']);
    Route::patch('profesionales/{id}/historia-precios-fotos/reordenar', [ProfesionalController::class, 'reordenarHistoriaPreciosFotos']);

    // Estadísticas
    Route::get('stats/dashboard', [StatsController::class, 'dashboard']);
    Route::get('stats/ganancias-por-periodo', [StatsController::class, 'gananciasPorPeriodo']);
    Route::get('stats/ocupacion', [StatsController::class, 'ocupacion']);

    // Turnos
    Route::prefix('turnos')->group(function () {
        Route::get('marcas',               [TurnoController::class, 'marcas']);
        Route::get('disponibilidad',       [TurnoController::class, 'disponibilidad']);
        Route::get('pendientes-de-cobro',  [TurnoController::class, 'pendientesDeCobro']);
        Route::get('recordatorios-pendientes', [TurnoController::class, 'recordatoriosPendientes']);
        Route::get('manana',               [TurnoController::class, 'turnosManana']);
        Route::get('notificaciones',       [TurnoController::class, 'notificaciones']);
        Route::post('notificaciones/marcar-vistas', [TurnoController::class, 'marcarNotificacionesVistas']);
        Route::get('/',                    [TurnoController::class, 'index']);
        Route::get('/{id}',                [TurnoController::class, 'show']);
        Route::post('/',                   [TurnoController::class, 'store']);
        Route::post('{id}/recordatorio-manual', [TurnoController::class, 'marcarRecordatorioManual']);
        Route::put('/{id}',                [TurnoController::class, 'update']);
        Route::patch('{id}/completar',     [TurnoController::class, 'completar']);
        Route::patch('{id}/precios',       [TurnoController::class, 'actualizarPrecios']);
        Route::delete('/{id}',             [TurnoController::class, 'destroy']);
    });

    // Reservas web — panel de aceptación
    Route::prefix('reservas')->group(function () {
        Route::get('/',              [ReservaWebController::class, 'index']);
        Route::post('{id}/aceptar',  [ReservaWebController::class, 'aceptar']);
        Route::post('{id}/rechazar', [ReservaWebController::class, 'rechazar']);
    });

});

// ─────────────────────────────────────────────
// Webhook Mercado Pago — sin auth, con firma HMAC
// ─────────────────────────────────────────────
Route::post('webhooks/mercadopago', [ReservaWebController::class, 'webhookMercadoPago']);

// ─────────────────────────────────────────────
// Webhook WhatsApp Cloud API — sin secreto en la URL: Meta firma cada
// payload (header X-Hub-Signature-256, HMAC-SHA256 con el App Secret), así
// que el control de acceso es la firma, no la URL. El GET es el handshake
// de verificación que exige Meta al configurar la URL en su dashboard.
// ─────────────────────────────────────────────
Route::get('webhooks/whatsapp-cloud', [CloudApiWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp-cloud', [CloudApiWebhookController::class, 'handle']);
