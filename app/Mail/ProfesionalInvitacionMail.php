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
 * returned by the API and not logged. The mailable is never queued: callers
 * send it right after the response from an in-process task (the synchronous
 * queue, whose payload lives only in memory), so the password is never written
 * to a queue table or a job store.
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
