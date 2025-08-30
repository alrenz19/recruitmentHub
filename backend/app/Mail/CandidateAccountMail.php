<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CandidateAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public $fullName;
    public $emailAddress;
    public $password;
    public $loginUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(string $fullName, string $emailAddress, string $password, string $loginUrl)
    {
        $this->fullName = $fullName;
        $this->emailAddress = $emailAddress;
        $this->password = $password;
        $this->loginUrl = $loginUrl;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('You’ve Passed Our Initial Screening – Next Steps')
                    ->markdown('emails.candidate_account');
    }
}
