<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use App\Mail\AssessmentResultMail;
use App\Mail\AssessmentRetryMail;

class FinalizePipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $applicantId;
    protected $totalScore;
    protected $totalQuestions;
    protected $attempts;

    public function __construct($applicantId, $totalScore, $totalQuestions, $attempts)
    {
        $this->applicantId     = $applicantId;
        $this->totalScore      = $totalScore;
        $this->totalQuestions  = $totalQuestions;
        $this->attempts        = $attempts;
    }

    public function handle()
    {
        $applicant = DB::selectOne("
            SELECT a.full_name, a.email as contact_email, p.id as pipeline_id
            FROM applicants a
            JOIN applicant_pipeline p ON p.applicant_id = a.id
            WHERE a.id = :aid
            LIMIT 1
        ", ['aid' => $this->applicantId]);

        if (!$applicant) return;

        $passingScore = ceil($this->totalQuestions * 0.6);

        if ($this->totalScore >= $passingScore) {
            // ✅ Passed
            DB::update("
                UPDATE applicant_pipeline
                SET note = 'Passed', updated_at = NOW()
                WHERE id = :id
            ", ['id' => $applicant->pipeline_id]);

            Mail::to($applicant->contact_email)->queue(new AssessmentResultMail(
                $applicant->full_name,
                $this->totalScore,
                $this->totalQuestions,
                'Passed'
            ));

        } else {
            // ❌ Failed & no attempts left
            DB::update("
                UPDATE applicant_pipeline
                SET note = 'Failed', updated_at = NOW()
                WHERE id = :id
            ", ['id' => $applicant->pipeline_id]);

            Mail::to($applicant->contact_email)->queue(new AssessmentResultMail(
                $applicant->full_name,
                $this->totalScore,
                $this->totalQuestions,
                'Failed'
            ));
        }
    }
}
