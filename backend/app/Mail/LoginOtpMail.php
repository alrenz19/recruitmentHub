<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginOtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $expiryMinutes = 10
    ) {}

    public function build(): self
    {
        return $this->subject('Your Login Verification Code - ' . config('app.name'))
                    ->view('emails.login-otp')
                    ->with([
                        'otp' => $this->otp,
                        'expiryMinutes' => $this->expiryMinutes,
                        'appName' => config('app.name'),
                    ]);
    }
}