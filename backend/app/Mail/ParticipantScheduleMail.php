<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class ParticipantScheduleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $interviewerName;
    public $applicantName;
    public $position;
    public $dateTime;
    public $stage;
    public string|null $mode;
    public string|null $link;

    public function __construct($interviewerName, $applicantName, $position, $dateTime, $stage, $mode, $link)
    {
        $this->interviewerName = $interviewerName;
        $this->applicantName   = $applicantName;
        $this->position        = $position;
        $this->dateTime        = $dateTime;
        $this->stage           = $stage;
        $this->$mode           = $mode;
        $this->$link           = $link;
    }

    public function build()
    {
        return $this->subject("Interview Schedule - {$this->applicantName}")
                    ->markdown('emails.participant.schedule');
    }
}
