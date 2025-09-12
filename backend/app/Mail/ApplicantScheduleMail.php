<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class ApplicantScheduleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $fullName;
    public $stage;
    public $date;
    public $time;
    public $participants;
    public string|null $mode;
    public string|null $link;

    public function __construct($fullName, $stage, $date, $time, $participants, $mode, $link)
    {
        $this->fullName     = $fullName;
        $this->stage        = $stage;
        $this->date         = $date;
        $this->time         = $time;
        $this->participants = $participants;
        $this->mode         = $mode;
        $this->link         = $link;
    }

    public function build()
    {
        return $this->subject("Interview Invitation - {$this->stage}")
                    ->markdown('emails.applicant.schedule');
    }
}
