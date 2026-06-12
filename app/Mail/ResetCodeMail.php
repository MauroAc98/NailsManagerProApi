<?php
 
namespace App\Mail;
 
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
 
class ResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;
 
    public string $code;
 
    public function __construct(string $code)
    {
        $this->code = $code;
    }
 
    public function build()
    {
        return $this->subject('Tu código para recuperar la contraseña')
            ->view('emails.reset-code')
            ->with(['code' => $this->code]);
    }
}