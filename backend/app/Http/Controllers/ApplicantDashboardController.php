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

        // Fetch applicant + pipeline + scores in one query
        $rows = DB::select("
            SELECT 
                a.id AS applicant_id,
                a.position_desired,
                a.desired_salary,
                a.created_at AS application_date,
                p.id AS pipeline_id,
                p.note AS pipeline_note,
                p.schedule_date,
                aps.type AS score_type,
                aps.overall_score
            FROM applicants a
            LEFT JOIN applicant_pipeline p ON p.applicant_id = a.id AND p.removed = 0
            LEFT JOIN applicant_pipeline_score aps ON aps.applicant_pipeline_id = p.id AND aps.removed = 0
            WHERE a.user_id = ?
            ORDER BY aps.type ASC
        ", [$userId]);

        if (empty($rows)) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $row = $rows[0];
        $scores = [];
        foreach ($rows as $r) {
            if ($r->score_type) $scores[$r->score_type] = $r->overall_score;
        }

        // Stages
        $stageOrder = [
            1 => 'Assessment',
            2 => 'Initial Interview',
            3 => 'Final Interview',
            4 => 'Hired',
            5 => 'Onboard',
        ];

        $getResult = fn($type) => isset($scores[$type]) ? ($scores[$type] >= 5 ? 'passed' : 'failed') : null;

        $steps = [];
        $currentStageId = $pipeline->current_stage_id;
        $note = strtolower($pipeline->note);

        $skipNext = false;

        foreach ($stageOrder as $id => $name) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            $status = 'pending';
            $desc = 'Pending';
            $date = null;

            if ($id < $currentStageId) {
                $status = 'completed';

                switch ($name) {
                    case 'Assessment':
                        $desc = 'You passed the examination';
                        break;
                    case 'Initial Interview':
                        $desc = 'You passed the initial interview';
                        break;
                    case 'Final Interview':
                        $desc = 'You passed the final interview';
                        break;
                    case 'Hired':
                        $desc = 'You accepted the job offer';
                        break;
                    case 'Onboard':
                        $desc = 'Your onboarding is completed';
                        break;
                }
            } elseif ($id == $currentStageId) {
                if ($note === 'passed') {
                    $status = 'completed';

                    // Push current stage
                    $steps[] = [
                        'id' => $id,
                        'name' => ($name === 'Assessment') ? 'Examination' : $name,
                        'status' => $status,
                        'date' => $date,
                        'description' => $this->getDescription($name, 'passed', $pipeline),
                    ];

                    // Push next stage as current
                    if (isset($stageOrder[$id + 1])) {
                        $nextStageName = $stageOrder[$id + 1];
                        $steps[] = [
                            'id' => $id + 1,
                            'name' => ($nextStageName === 'Assessment') ? 'Examination' : $nextStageName,
                            'status' => 'current',
                            'date' => null,
                            'description' => 'Pending',
                        ];
                        $skipNext = true;
                    }
                    continue;
                } elseif ($note === 'failed') {
                    $status = 'completed';
                    $desc = $this->getDescription($name, 'failed', $pipeline);
                } elseif ($note === 'cancelled') {
                    $status = 'cancelled';
                    $desc = 'Cancelled';
                } else {
                    $status = 'current';
                    $desc = ucfirst($note);
                }
            }

            $steps[] = [
                'id' => $id,
                'name' => $name === 'Assessment' ? 'Examination' : $name,
                'status' => $status,
                'date' => $date,
                'description' => $desc,
            ];
        }

        // Determine overall application status
        $overallStatus = 'In Progress';

        foreach ($steps as $s) {
            if ($s['status'] === 'cancelled') {
                $overallStatus = 'Cancelled';
                break;
            }
            if (
                $s['status'] === 'completed' &&
                isset($s['description']) &&
                str_contains(strtolower($s['description']), 'failed')
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

        return response()->json([
            'position' => $applicant->position_desired ?? 'N/A',
            'status' => $overallStatus,
            'desired_salary' => $applicant->desired_salary
                ? 'PHP' . number_format($applicant->desired_salary, 0)
                : 'N/A',
            'application_date' => $applicant->created_at
                ? $applicant->created_at->format('F j, Y')
                : 'N/A',
            'steps' => $steps,
        ]);
    }

    private function getDescription(string $stage, string $result, $pipeline): string
    {
        switch ($stage) {
            case 'Assessment':
                return $result === 'passed'
                    ? 'You passed the examination'
                    : 'You failed the examination';
            case 'Initial Interview':
                return $result === 'passed'
                    ? 'You passed the initial interview'
                    : 'You failed the initial interview';
            case 'Final Interview':
                return $result === 'passed'
                    ? 'You passed the final interview'
                    : 'You failed the final interview';
            case 'Hired':
                return $result === 'accepted'
                    ? 'You accepted the job offer'
                    : 'You declined the job offer';
            case 'Onboard':
                return $result === 'passed'
                    ? 'Your onboarding schedule is on ' . date('l, F j', strtotime($pipeline->schedule_date ?? $pipeline->updated_at))
                    : 'Onboarding was cancelled';
            default:
                return 'Pending';
        }
    }
}
