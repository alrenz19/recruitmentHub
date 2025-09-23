<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class AssessmentRetryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $name;
    public $score;
    public $total;
    public $remainingAttempts;

    /**
     * Create a new message instance.
     */
    public function __construct($name, $score, $total, $remainingAttempts)
    {
        $this->name              = $name;
        $this->score             = $score;
        $this->total             = $total;
        $this->remainingAttempts = $remainingAttempts;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Assessment Result - Retry Available')
            ->markdown('emails.assessments.retry')
            ->with([
                'name'              => $this->name,
                'score'             => $this->score,
                'total'             => $this->total,
                'remainingAttempts' => $this->remainingAttempts,
            ]);
    }
}
