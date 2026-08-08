<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RecordatoriosPendientesMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;
    public int $cantidadTurnos;
    public string $url;

    public function __construct(string $name, int $cantidadTurnos, string $url)
    {
        $this->name = $name;
        $this->cantidadTurnos = $cantidadTurnos;
        $this->url = $url;
    }

    public function build()
    {
        return $this->subject('Tenés turnos mañana para recordar')
            ->view('emails.recordatorios-pendientes')
            ->with([
                'name'           => $this->name,
                'cantidadTurnos' => $this->cantidadTurnos,
                'url'            => $this->url,
            ]);
    }
}
