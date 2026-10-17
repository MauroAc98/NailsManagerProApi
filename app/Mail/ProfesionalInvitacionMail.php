<?php

namespace App\Mail;

use App\Mail\Concerns\ConAsuntoDeAccesoAlPortal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * First access to the professionals' portal: a NEW account was created for the
 * invited email, so the mail carries its provisional password. This is the
 * only place that password ever travels; it is not stored in plain text, not
 * returned by the API and not logged. Sent synchronously (never queued), so the
 * password is never written to a queue payload either.
 */
class ProfesionalInvitacionMail extends Mailable
{
    use ConAsuntoDeAccesoAlPortal;
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $negocio,
        public readonly string $nombreProfesional,
        public readonly string $email,
        public readonly string $password,
        public readonly string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asuntoDeAcceso());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.profesional-invitacion');
    }
}
