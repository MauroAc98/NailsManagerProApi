<?php

namespace App\Services\Reservas;

use RuntimeException;

/** Interna: el horario dejo de estar libre bajo lock; revierte la transaccion del intento. */
class SlotNoDisponible extends RuntimeException
{
}
