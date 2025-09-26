<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApplicantDashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ApplicantDashboardController extends Controller
{
    public function index()
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // --------------------------
        // Fetch applicant in ONE query
        // --------------------------
        $rows = DB::select(
            "
            SELECT 
                a.id AS applicant_id,
                a.position_desired,
                a.desired_salary,
                a.created_at AS application_date,
                p.id AS pipeline_id,
                p.current_stage_id AS stage_id,
                p.note AS pipeline_note,
                p.schedule_date,
                aps.type AS score_type,
                aps.overall_score
            FROM applicants a
            LEFT JOIN applicant_pipeline p 
                ON p.applicant_id = a.id AND p.removed = 0
            LEFT JOIN applicant_pipeline_score aps 
                ON aps.applicant_pipeline_id = p.id AND aps.removed = 0
            WHERE a.user_id = ?
            ORDER BY aps.type ASC
            LIMIT 1
            ",
            [$userId]
        );

        if (empty($rows)) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $applicant = $rows[0];

        // --------------------------
        // Map scores by type
        // --------------------------
        $scores = [];
        foreach ($rows as $r) {
            if (!empty($r->score_type)) {
                $scores[$r->score_type] = (int) $r->overall_score;
            }
        }

        // --------------------------
        // Pipeline setup
        // --------------------------
        $pipeline = (object) [
            'current_stage_id' => (int) ($applicant->stage_id ?? 1),
            'note'             => strtolower($applicant->pipeline_note ?? 'pending'),
            'schedule_date'    => $applicant->schedule_date,
        ];

        // --------------------------
        // Stage order (fixed to 5)
        // --------------------------
        $stageOrder = [
            1 => 'Assessment',
            2 => 'Initial Interview',
            3 => 'Final Interview',
            4 => 'Hired',
            5 => 'Onboard',
        ];

        // --------------------------
        // Build steps array without looping logic confusion
        // --------------------------
        $steps = array_map(function ($id, $name) {
            return [
                'id'          => $id,
                'name'        => $name === 'Assessment' ? 'Examination' : $name,
                'status'      => 'pending',
                'description' => 'Pending',
            ];
        }, array_keys($stageOrder), $stageOrder);

        $curr = $pipeline->current_stage_id ?? 1;
        $note = $pipeline->note;

        // Mark completed stages before current stage
        for ($i = 1; $i < $curr; $i++) {
            $steps[$i - 1]['status'] = 'completed';
            $steps[$i - 1]['description'] = match ($stageOrder[$i]) {
                'Assessment'        => 'You passed the examination',
                'Initial Interview' => 'You passed the initial interview',
                'Final Interview'   => 'You passed the final interview',
                'Hired'             => 'You accepted the job offer',
                'Onboard'           => 'Your onboarding is completed',
                default             => 'Completed',
            };
        }

        // Handle current stage
        $currIndex = $curr - 1;
        if ($note === 'passed') {
            $curr = max(1, min(5, $curr));
            // mark current as completed
            $steps[$currIndex]['status'] = 'completed';
            $steps[$currIndex]['description'] =
                $this->getDescription($stageOrder[$curr] ?? 'Unknown', 'passed', $pipeline);

            if ($curr < 5) {
                $steps[$curr]['status'] = 'current';
                $steps[$curr]['description'] = 'Pending';
            }
        } elseif ($note === 'failed') {
            $steps[$currIndex]['status'] = 'completed';
            $steps[$currIndex]['description'] = $this->getDescription($stageOrder[$curr], 'failed', $pipeline);
        } elseif ($note === 'cancelled') {
            $steps[$currIndex]['status'] = 'cancelled';
            $steps[$currIndex]['description'] = 'Cancelled';
        } else {
            // still in progress
            $steps[$currIndex]['status'] = 'current';
            if ($note === 'pending' && !empty($pipeline->schedule_date)) {
                $steps[$currIndex]['description'] =
                    'Scheduled on ' . date('l, F j, Y \a\t g:i A', strtotime($pipeline->schedule_date));
            } else {
                $steps[$currIndex]['description'] = ucfirst($note);
            }
        }

        // --------------------------
        // Determine overall status
        // --------------------------
        $overallStatus = 'In Progress';

        foreach ($steps as $s) {
            if ($s['status'] === 'cancelled') {
                $overallStatus = 'Cancelled';
                break;
            }
            if (
                $s['status'] === 'completed'
                && str_contains(strtolower($s['description']), 'failed')
            ) {
                $overallStatus = 'Failed';
                break;
            }
        }

        if ($overallStatus === 'In Progress') {
            $allCompleted = collect($steps)->every(fn($s) => $s['status'] === 'completed');
            if ($allCompleted) {
                $overallStatus = 'Passed';
            }
        }

        // --------------------------
        // Final response
        // --------------------------
        return response()->json([
            'position'          => $applicant->position_desired ?? 'N/A',
            'status'            => $overallStatus,
            'desired_salary'    => $applicant->desired_salary
                                    ? 'PHP' . number_format($applicant->desired_salary, 0)
                                    : 'N/A',
            'application_date'  => $applicant->application_date
                                    ? date('F j, Y', strtotime($applicant->application_date))
                                    : 'N/A',
            'steps'             => $steps,
        ]);
    }


    private function getDescription(string $stage, string $result, $pipeline): string
    {
        return match ($stage) {
            'Assessment'        => $result === 'passed'
                                    ? 'You passed the examination'
                                    : 'You failed the examination',
            'Initial Interview' => $result === 'passed'
                                    ? 'You passed the initial interview'
                                    : 'You failed the initial interview',
            'Final Interview'   => $result === 'passed'
                                    ? 'You passed the final interview'
                                    : 'You failed the final interview',
            'Hired'             => $result === 'accepted'
                                    ? 'You accepted the job offer'
                                    : 'You declined the job offer',
            'Onboard'           => $result === 'passed'
                                    ? 'Your onboarding schedule is on ' .
                                        date('l, F j', strtotime($pipeline->schedule_date ?? now()))
                                    : 'Onboarding was cancelled',
            default             => 'Pending',
        };
    }

}
