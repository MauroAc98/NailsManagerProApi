<?php

return [

    // Minutos de anticipacion minima para reservar online (slice 1: constante;
    // mas adelante pasa a ser configurable por salon).
    'anticipacion_minutos' => (int) env('RESERVAS_ANTICIPACION_MINUTOS', 120),

    // Ventana (minutos) durante la cual una reserva web pending_payment bloquea
    // el horario. Todavia no hay job de expiracion: las reservas mas viejas que
    // esta ventana se ignoran al calcular disponibilidad.
    'ventana_pago_minutos' => (int) env('RESERVAS_VENTANA_PAGO_MINUTOS', 15),

    // TODO(reserva-online slice 3): eliminar este flag y el guard en
    // PublicController::store cuando la creacion de reservas este completa
    // (MP, lock, profesional). Mientras tanto POST /api/public/{slug}/reservas
    // responde 503 si esta apagado.
    'creacion_habilitada' => (bool) env('RESERVAS_CREACION_HABILITADA', false),

];
