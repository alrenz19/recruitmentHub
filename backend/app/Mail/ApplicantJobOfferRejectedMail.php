<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicantJobOfferRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $applicantName;
    public $position;
    public $department;
    public $approvalLink;

    public function __construct(
        $applicantName, 
        $position, 
        $department, 
        $approvalLink,
    )
    {
        $this->applicantName = $applicantName;
        $this->position = $position;
        $this->department = $department;
         $this->approvalLink  = $approvalLink;

    }

    public function build()
    {
        return $this->subject('Job Offer Rejected by Applicant')
            ->markdown('emails.applicant_job_offer_rejected');
    }
}
