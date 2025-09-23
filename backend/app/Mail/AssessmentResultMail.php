<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AssessmentResultMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $name;
    public $score;
    public $total;
    public $status;

    public function __construct($name, $score, $total, $status)
    {
        $this->name       = $name;
        $this->score      = $score;
        $this->total      = $total;
        $this->status     = $status;
    }

    public function build()
    {
        $subject = $this->status === 'Passed'
            ? '🎉 Congratulations! You Passed the Assessment'
            : 'Assessment Result: Thank You for Participating';

        return $this->subject($subject)
                    ->markdown('emails.applicant.assessment_result');
    }
}
