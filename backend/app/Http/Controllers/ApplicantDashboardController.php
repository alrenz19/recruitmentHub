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
        $markNextAsCurrent = false;
        foreach ($stageOrder as $id => $name) {
            $status = 'pending';
            $desc = 'Pending';
            $date = null;

            if ($markNextAsCurrent) {
                $status = 'current';
                $markNextAsCurrent = false;
            }

            switch ($name) {
                case 'Assessment':
                    if (strtolower($row->pipeline_note) === 'passed') {
                        $status = 'completed';
                        $result = $getResult('exam_score');
                        $desc = $result === 'passed' ? 'You passed the examination' : 'You failed the examination';
                        $markNextAsCurrent = true;
                        $date = $row->schedule_date ? date('F j, Y g:i A', strtotime($row->schedule_date)) : null;
                    } elseif (strtolower($row->pipeline_note) === 'failed') {
                        $status = 'completed';
                        $desc = 'You failed the examination';
                    } elseif (strtolower($row->pipeline_note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;

                case 'Initial Interview':
                    if (strtolower($row->pipeline_note) === 'passed' && $status === 'completed') {
                        $result = $getResult('initial_interview');
                        $desc = $result === 'passed' ? 'You passed the interview' : 'You failed the interview';
                        $markNextAsCurrent = true;
                        $date = $row->schedule_date ? date('F j, Y g:i A', strtotime($row->schedule_date)) : null;
                    } elseif (strtolower($row->pipeline_note) === 'failed' && $status === 'completed') {
                        $desc = 'You failed the interview';
                    } elseif (strtolower($row->pipeline_note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;

                case 'Final Interview':
                    if (strtolower($row->pipeline_note) === 'passed' && $status === 'completed') {
                        $result = $getResult('final_interview');
                        $desc = $result === 'passed' ? 'You passed the interview' : 'You failed the interview';
                        $markNextAsCurrent = true;
                        $date = $row->schedule_date ? date('F j, Y g:i A', strtotime($row->schedule_date)) : null;
                    } elseif (strtolower($row->pipeline_note) === 'failed' && $status === 'completed') {
                        $desc = 'You failed the interview';
                    } elseif (strtolower($row->pipeline_note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;

                case 'Hired':
                    if (strtolower($row->pipeline_note) === 'declined') {
                        $status = 'completed';
                        $desc = 'Declined';
                        $date = $row->schedule_date ? date('F j, Y g:i A', strtotime($row->schedule_date)) : null;
                    } elseif (strtolower($row->pipeline_note) === 'accepted') {
                        $status = 'completed';
                        $desc = 'Thank you for accepting the job offer';
                        $markNextAsCurrent = true;
                        $date = $row->schedule_date ? date('F j, Y g:i A', strtotime($row->schedule_date)) : null;
                    } elseif (strtolower($row->pipeline_note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;

                case 'Onboard':
                    if (strtolower($row->pipeline_note) === 'completed') {
                        $status = 'completed';
                        $desc = 'Onboarding - Start Date: ' . date('F j, Y', strtotime($row->schedule_date));
                    } elseif (strtolower($row->pipeline_note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;
            }

            $steps[] = [
                'id' => $id,
                'name' => $name === 'Assessment' ? 'Examination' : $name,
                'status' => $status,
                'date' => $date,
                'description' => $desc,
            ];
        }

        return response()->json([
            'position' => $row->position_desired ?? 'N/A',
            'status' => $row->pipeline_note ?? 'N/A',
            'desired_salary' => $row->desired_salary ? 'PHP' . number_format($row->desired_salary, 0) : 'N/A',
            'application_date' => $row->application_date ? date('F j, Y', strtotime($row->application_date)) : 'N/A',
            'steps' => $steps,
        ]);
    }


}
