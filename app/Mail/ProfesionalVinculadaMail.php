<?php

namespace App\Mail;

use App\Mail\Concerns\ConAsuntoDeAccesoAlPortal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invited email already had a portal account, so it was linked directly
 * to the new negocio. The mail only announces the new access: it has no
 * password field on purpose, and the existing account's password, flag and
 * sessions are never touched.
 */
class ProfesionalVinculadaMail extends Mailable
{
    use ConAsuntoDeAccesoAlPortal;
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $negocio,
        public readonly string $nombreProfesional,
        public readonly string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asuntoDeAcceso());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.profesional-vinculada');
    }
}
