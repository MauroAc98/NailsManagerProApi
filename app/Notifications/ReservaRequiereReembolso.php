<?php

namespace App\Notifications;

/**
 * Web Push "client paid the deposit but got no turno": same flat payload and
 * channel as NuevaReservaOnline, kept as its own class so it can be told apart
 * (and faked in tests).
 */
class ReservaRequiereReembolso extends NuevaReservaOnline
{
}
