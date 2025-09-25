<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class JobOfferRejectionMail extends Mailable
{
    use Queueable, SerializesModels;

    public $applicantName;
    public $position;
    public $approvalLink;

    public function __construct($applicantName, $position = '', $approvalLink)
    {
        $this->applicantName = $applicantName;
        $this->position      = $position;
        $this->approvalLink  = $approvalLink;
    }

    public function build()
    {
        return $this->subject("Job Offer Rejection Notification")
            ->markdown('emails.applicant.job_offer_rejection');
    }
}
