<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessAssessmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $candidateId;
    public array $assessments;
    public int $creator;

    /**
     * Create a new job instance.
     */
    public function __construct(int $candidateId, array $assessments, int $creator)
    {
        $this->candidateId = $candidateId;
        $this->assessments = $assessments;
        $this->creator = $creator;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $now = now();
        $rows = [];

        foreach ($this->assessments as $assessmentId) {
            $rows[] = [
                'applicant_id'  => $this->candidateId,
                'assessment_id' => $assessmentId,
                'assigned_by'   => $this->creator,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (!empty($rows)) {
            // Use upsert to avoid duplicates if job is retried
            DB::table('applicant_assessments')->upsert(
                $rows,
                ['applicant_id', 'assessment_id'], // unique key
                ['assigned_by', 'updated_at']      // fields to update if exists
            );
        }

        // Optional: any other post-processing per assessment
    }
}
