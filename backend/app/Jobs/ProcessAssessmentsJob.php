<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAssessmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $candidateId;
    protected array $assessments;
    protected int $creator;

    /**
     * Create a new job instance.
     */
    public function __construct(int $candidateId, array $assessments, int $creator)
    {
        $this->candidateId = $candidateId;
        $this->assessments = $assessments;
        $this->creator     = $creator;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (empty($this->assessments)) {
            return;
        }

        $pdo = DB::getPdo();
        $now = now();

        // Build bulk values string with placeholders (?, ?, ?, ?, ?)
        $placeholders = [];
        $bindings     = [];

        foreach ($this->assessments as $assessmentId) {
            $placeholders[] = "(?, ?, ?, ?, ?)";
            $bindings[] = $this->candidateId;   // applicant_id
            $bindings[] = $assessmentId;        // assessment_id
            $bindings[] = $this->creator;       // assigned_by
            $bindings[] = $now;                 // created_at
            $bindings[] = $now;                 // updated_at
        }

        $sql = "
            INSERT INTO applicant_assessments 
                (applicant_id, assessment_id, assigned_by, created_at, updated_at)
            VALUES " . implode(", ", $placeholders) . "
            ON DUPLICATE KEY UPDATE 
                assigned_by = VALUES(assigned_by), 
                updated_at = VALUES(updated_at)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
    }
}
