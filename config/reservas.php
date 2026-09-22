<?php

return [

    // Dominio publico de reserva online (reservar.turnetto.com), NUNCA
    // services.frontend_url (ese es app.turnetto.com, el dashboard del
    // negocio) — lo usa MercadoPagoService para las back_urls de MP. Mandar
    // a la clienta al dominio del dashboard la deja del lado del guard de
    // auth (isReservaPublica evalua false ahi), mostrando el Welcome de una
    // cuenta logueada en vez de la pantalla de estado de su reserva.
    'base_url' => env('RESERVAS_BASE_URL'),

    // Minutos de anticipacion minima para reservar online (slice 1: constante;
    // mas adelante pasa a ser configurable por salon).
    'anticipacion_minutos' => (int) env('RESERVAS_ANTICIPACION_MINUTOS', 120),

    // Ventana de reserva: dias hacia adelante (desde hoy) que se ofrecen.
    // La usa el endpoint de dias con disponibilidad para recortar el rango.
    'ventana_dias' => (int) env('RESERVAS_VENTANA_DIAS', 30),

    // Kill switch de los endpoints de ESCRITURA de la reserva online (holds,
    // datos, pago, liberar, estado). Apagado => 503 {code: creation_disabled}.
    // Las lecturas (disponibilidad) no dependen de este flag.
    'creacion_habilitada' => (bool) env('RESERVAS_CREACION_HABILITADA', false),

    // ── Holds (slice 3) ──────────────────────────────────────────
    // Minutos que un hold sin pagar bloquea el horario (normal / alta ocupacion).
    'hold_minutos' => (int) env('RESERVAS_HOLD_MINUTOS', 10),
    'hold_minutos_alta' => (int) env('RESERVAS_HOLD_MINUTOS_ALTA', 5),

    // Minutos disponibles para pagar una vez iniciado el pago (normal / alta).
    'pago_minutos' => (int) env('RESERVAS_PAGO_MINUTOS', 15),
    'pago_minutos_alta' => (int) env('RESERVAS_PAGO_MINUTOS_ALTA', 10),

    // Horas antes del turno hasta las que se puede cancelar sin perder la
    // seña. Constante por ahora (mismo criterio que anticipacion_minutos);
    // mas adelante pasa a ser configurable por salon si hace falta.
    'cancelacion_horas' => (int) env('RESERVAS_CANCELACION_HORAS', 24),

    // Fraccion (0-1) de slots ocupados de la profesional ese dia a partir de la
    // cual se considera "alta ocupacion" y se acortan los tiempos.
    'ocupacion_alta_umbral' => (float) env('RESERVAS_OCUPACION_ALTA_UMBRAL', 0.7),

    // ── Anti-abuso ───────────────────────────────────────────────
    // Tras un hold vencido sin pago, el mismo telefono espera estos minutos.
    'cooldown_telefono_minutos' => (int) env('RESERVAS_COOLDOWN_TELEFONO_MINUTOS', 30),

    // Con >= umbral holds vencidos sin pago dentro de la ventana (horas) se
    // exige verificar el WhatsApp. Solo se ENFORCEA con verificacion_habilitada.
    'verificacion_habilitada' => (bool) env('RESERVAS_VERIFICACION_HABILITADA', false),
    'verificacion_umbral' => (int) env('RESERVAS_VERIFICACION_UMBRAL', 2),
    'verificacion_ventana_horas' => (int) env('RESERVAS_VERIFICACION_VENTANA_HORAS', 24),
    'verificacion_validez_horas' => (int) env('RESERVAS_VERIFICACION_VALIDEZ_HORAS', 24),

    // Reto anti-bot (Turnstile) al crear un hold. Apagado por defecto.
    'challenge' => [
        'habilitado' => (bool) env('RESERVAS_CHALLENGE_HABILITADO', false),
    ],

];
