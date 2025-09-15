<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApplicantDashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ApplicantDashboardController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();

        $applicant = ApplicantDashboard::with('pipeline')
            ->where('user_id', $userId)
            ->first();

        if (!$applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $pipeline = $applicant->pipeline;

        // fetch scores for exam/interviews
        $scores = DB::table('applicant_pipeline_score')
            ->where('applicant_pipeline_id', $pipeline->id)
            ->get()
            ->keyBy('type');

        // ordered stages
        $stageOrder = [
            1 => 'Assessment',
            2 => 'Initial Interview',
            3 => 'Final Interview',
            4 => 'Hired',
            5 => 'Onboard',
        ];

        // helper: check pass/fail
        $getResult = function ($type) use ($scores) {
            if (!isset($scores[$type])) {
                return null;
            }
            $score = $scores[$type];
            return $score->overall_score >= 5 ? 'passed' : 'failed';
        };

        $steps = [];
        $markNextAsCurrent = false;

        foreach ($stageOrder as $id => $name) {
            $status = 'pending';
            $desc = 'Pending';
            $date = null;

            // If the previous stage was "passed", mark this stage as "current"
            if ($markNextAsCurrent) {
                $status = 'current';
                $markNextAsCurrent = false;
            }

            switch ($name) {
                case 'Assessment':
                    if (strtolower($pipeline->note) === 'passed') {
                        $status = 'completed';
                        $result = $getResult('exam_score');
                        $desc = $result === 'passed'
                            ? 'You passed the examination'
                            : 'You failed the examination';
                        $markNextAsCurrent = true; // next step should be current
                        $date = $pipeline->schedule_date
                            ? date('F j, Y g:i A', strtotime($pipeline->schedule_date))
                            : null;
                    } elseif (strtolower($pipeline->note) === 'failed') {
                        $status = 'completed';
                        $desc = 'You failed the examination';
                    } elseif (strtolower($pipeline->note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;

                case 'Initial Interview':
                    if (strtolower($pipeline->note) === 'passed' && $status === 'completed') {
                        $result = $getResult('initial_interview');
                        $desc = $result === 'passed'
                            ? 'You passed the interview'
                            : 'You failed the interview';
                        $markNextAsCurrent = true;
                        $date = $pipeline->schedule_date
                            ? date('F j, Y g:i A', strtotime($pipeline->schedule_date))
                            : null;
                    } elseif (strtolower($pipeline->note) === 'failed' && $status === 'completed') {
                        $desc = 'You failed the interview';
                    } elseif (strtolower($pipeline->note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;

                case 'Final Interview':
                    if (strtolower($pipeline->note) === 'passed' && $status === 'completed') {
                        $result = $getResult('final_interview');
                        $desc = $result === 'passed'
                            ? 'You passed the interview'
                            : 'You failed the interview';
                        $markNextAsCurrent = true;
                        $date = $pipeline->schedule_date
                            ? date('F j, Y g:i A', strtotime($pipeline->schedule_date))
                            : null;
                    } elseif (strtolower($pipeline->note) === 'failed' && $status === 'completed') {
                        $desc = 'You failed the interview';
                    } elseif (strtolower($pipeline->note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;

                case 'Hired':
                    if (strtolower($pipeline->note) === 'declined') {
                        $status = 'completed';
                        $desc = 'Declined';
                        $date = $pipeline->schedule_date
                            ? date('F j, Y g:i A', strtotime($pipeline->schedule_date))
                            : null;
                    } elseif (strtolower($pipeline->note) === 'accepted') {
                        $status = 'completed';
                        $desc = 'Thank you for accepting the job offer';
                        $markNextAsCurrent = true;
                        $date = $pipeline->schedule_date
                            ? date('F j, Y g:i A', strtotime($pipeline->schedule_date))
                            : null;
                    } elseif (strtolower($pipeline->note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;

                case 'Onboard':
                    if (strtolower($pipeline->note) === 'completed') {
                        $status = 'completed';
                        $desc = 'Onboarding - Start Date: ' .
                            date('F j, Y', strtotime($pipeline->schedule_date));
                    } elseif (strtolower($pipeline->note) === 'cancelled') {
                        $status = 'cancelled';
                        $desc = 'Cancelled';
                    }
                    break;
            }

            $steps[] = [
                'id' => $id,
                'name' => ($name === 'Assessment') ? 'Examination' : $name,
                'status' => $status,
                'date' => $date,
                'description' => $desc,
            ];
        }

        return response()->json([
            'position' => $applicant->position_desired ?? 'N/A',
            'status' => $pipeline->note ?? 'N/A',
            'desired_salary' => $applicant->desired_salary
                ? 'PHP' . number_format($applicant->desired_salary, 0)
                : 'N/A',
            'application_date' => $applicant->created_at
                ? $applicant->created_at->format('F j, Y')
                : 'N/A',
            'steps' => $steps,
        ]);
    }
}
