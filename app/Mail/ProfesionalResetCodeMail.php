<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The recovery code of a professionals' portal account. It names no negocio
 * on purpose (an account can belong to several) and tells where to type the
 * code: the tenant reset mail does not say, and the two apps share a brand.
 */
class ProfesionalResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $codigo,
        public readonly string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código para recuperar la contraseña del portal de profesionales');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.profesional-reset-code');
    }
}
