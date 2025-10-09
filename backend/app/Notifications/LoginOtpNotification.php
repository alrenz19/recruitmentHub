<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LoginOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $otp,
        public int $expiryMinutes = 10
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Your Login Verification Code - ' . config('app.name'))
            ->greeting('Hello!')
            ->line('Your one-time password (OTP) for login is:')
            ->line('**' . $this->otp . '**')
            ->line('This OTP will expire in ' . $this->expiryMinutes . ' minutes.')
            ->line('If you did not request this login, please ignore this email and contact support immediately.')
            ->salutation('Regards, ' . config('app.name'));
    }
}