<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CandidateUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public $candidateName;
    public $updaterName;
    public $updatedFields;
    public $updateTime;

    /**
     * Create a new message instance.
     */
    public function __construct($candidateName, $updaterName, $updatedFields, $updateTime)
    {
        $this->candidateName = $candidateName;
        $this->updaterName = $updaterName;
        $this->updatedFields = $updatedFields;
        $this->updateTime = $updateTime;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject("Candidate Updated - {$this->candidateName}")
                    ->view('emails.candidate-updated')
                    ->with([
                        'candidateName' => $this->candidateName,
                        'updaterName' => $this->updaterName,
                        'updatedFields' => $this->updatedFields,
                        'updateTime' => $this->updateTime,
                    ]);
    }
}