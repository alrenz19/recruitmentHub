<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicantJobOfferNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $applicantName;
    public $position;
    public $department;
    public $approvalLink;

    public function __construct($applicantName, $position, $department, $approvalLink)
    {
        $this->applicantName = $applicantName;
        $this->position = $position;
        $this->department = $department;
        $this->approvalLink = $approvalLink;
    }

    public function build()
    {
        return $this->subject('Your Job Offer is Ready for Review')
            ->markdown('emails.applicant_job_offer_notification');
    }
}
