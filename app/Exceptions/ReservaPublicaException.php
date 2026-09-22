<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Error de dominio de la reserva online publica. Se renderiza siempre como
 * {"message": ..., "code": ...} (+ retry_after_seconds en 429).
 */
class ReservaPublicaException extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        public readonly int $status,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function slotTaken(): self
    {
        return new self('slot_taken', 409, 'Ese horario ya no está disponible. Elegí otro.');
    }

    public static function holdExpired(): self
    {
        return new self('hold_expired', 410, 'La reserva venció. Volvé a elegir el horario.');
    }

    public static function validacion(string $mensaje): self
    {
        return new self('validation', 422, $mensaje);
    }

    public static function datosRequired(): self
    {
        return new self('datos_required', 422, 'Faltan los datos de contacto de la reserva.');
    }

    public static function yaConfirmada(): self
    {
        return new self('already_confirmed', 409, 'La reserva ya está confirmada.');
    }

    public static function phoneCooldown(int $retryAfterSeconds): self
    {
        return new self('phone_cooldown', 429, 'Ese número tiene una reserva reciente sin pagar. Probá de nuevo más tarde.', $retryAfterSeconds);
    }

    public static function verificationRequired(): self
    {
        return new self('verification_required', 403, 'Necesitamos verificar tu WhatsApp para continuar.');
    }

    public static function challengeFailed(): self
    {
        return new self('challenge_failed', 403, 'No pudimos verificar que sos una persona. Reintentá.');
    }

    public static function notFound(): self
    {
        return new self('not_found', 404, 'No encontramos esa reserva.');
    }

    public static function creationDisabled(): self
    {
        return new self('creation_disabled', 503, 'La reserva online todavía no está disponible.');
    }

    public static function deviceTokenRequired(): self
    {
        return new self('device_token_required', 422, 'Falta el identificador del dispositivo.');
    }

    public static function mpNoConectado(): self
    {
        return new self('mp_no_conectado', 503, 'Este negocio todavía no tiene Mercado Pago conectado.');
    }

    public static function mpError(): self
    {
        return new self('mp_error', 502, 'No pudimos generar el link de pago. Probá de nuevo en un momento.');
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage(), 'code' => $this->codigo];
        if ($this->retryAfterSeconds !== null) {
            $body['retry_after_seconds'] = $this->retryAfterSeconds;
        }

        return response()->json($body, $this->status);
    }
}
