<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class JobOfferApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $applicantName;
    public $position;
    public $department;
    public $hrName;
    public $jobOfferId;
    public $approvalLink;

    public function __construct($applicantName, $position, $department, $hrName, $jobOfferId, $approvalLink)
    {
        $this->applicantName = $applicantName;
        $this->position      = $position;
        $this->department    = $department;
        $this->hrName        = $hrName;
        $this->jobOfferId    = $jobOfferId;
        $this->approvalLink  = $approvalLink;
    }

    public function build()
    {
        return $this->subject("Job Offer Approval Required - {$this->applicantName}")
                    ->markdown('emails.job_offer.approval');
    }
}
