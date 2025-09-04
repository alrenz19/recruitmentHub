<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class HRStaffCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user_email;
    public $password;

    public function __construct($user_email, $password)
    {
        $this->user_email = $user_email;
        $this->password = $password;
    }

    public function build()
    {
        return $this->subject('Your Online Recruitment Account')
                    ->markdown('emails.hr_staff_created') // use markdown, not view
                    ->with([
                        'user_email' => $this->user_email,
                        'password'   => $this->password,
                        'loginUrl'   => config('app.url'), // optional for button
                    ]);
    }
}
