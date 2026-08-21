<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProvisionalPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;
    public string $email;
    public string $password;

    public function __construct(string $name, string $email, string $password)
    {
        $this->name = $name;
        $this->email = $email;
        $this->password = $password;
    }

    public function build()
    {
        return $this->subject('Tu cuenta en Turnetto')
            ->view('emails.provisional-password')
            ->with([
                'name'     => $this->name,
                'email'    => $this->email,
                'password' => $this->password,
            ]);
    }
}