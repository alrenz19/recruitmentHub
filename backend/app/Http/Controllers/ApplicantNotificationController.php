<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApplicantNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ApplicantNotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();

        $pipeline = DB::table('applicant_pipeline')
            ->join('applicants', 'applicant_pipeline.applicant_id', '=', 'applicants.id')
            ->where('applicants.user_id', $userId)
            ->select('applicant_pipeline.*')
            ->first();

        if (!$pipeline) {
            return response()->json([]);
        }

        $notifications = [];

        // Fetch stage name
        $stage = DB::table('recruitment_stages')
            ->where('id', $pipeline->current_stage_id)
            ->value('stage_name');

        // -------------------------------
        // Examination (Assessment)
        // -------------------------------
        if ($stage === 'Assessment') {
            if ($pipeline->note === 'Passed') {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Congrats! You passed the examination.',
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            } elseif ($pipeline->note === 'Failed') {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Unfortunately, you failed the examination.',
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            } 
            if ($pipeline->schedule_date) {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Your examination is scheduled on ' . date('F j, Y g:i A', strtotime($pipeline->schedule_date)),
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            }
        }

        // -------------------------------
        // Initial Interview
        // -------------------------------
        if ($stage === 'Initial Interview') {
            if ($pipeline->note === 'Passed') {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Congrats! You passed the initial interview.',
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            } elseif ($pipeline->note === 'Failed') {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Unfortunately, you failed the initial interview.',
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            } elseif ($pipeline->schedule_date) {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Your initial interview is scheduled on ' . date('F j, Y g:i A', strtotime($pipeline->schedule_date)),
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            }
        }

        // -------------------------------
        // Final Interview
        // -------------------------------
        if ($stage === 'Final Interview') {
            if ($pipeline->note === 'Passed') {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Congrats! You passed the final interview.',
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            } elseif ($pipeline->note === 'Failed') {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Unfortunately, you failed the final interview.',
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            } elseif ($pipeline->schedule_date) {
                $notifications[] = [
                    'id' => uniqid(),
                    'message' => 'Your final interview is scheduled on ' . date('F j, Y g:i A', strtotime($pipeline->schedule_date)),
                    'createdAt' => now()->toISOString(),
                    'isUnread' => true,
                ];
            }
        }

        // -------------------------------
        // Hired
        // -------------------------------
        if ($stage === 'Hired' && $pipeline->note === 'accepted') {
            $notifications[] = [
                'id' => uniqid(),
                'message' => 'Congratulations! You have been hired 🎉',
                'createdAt' => now()->toISOString(),
                'isUnread' => true,
            ];
        }

        // -------------------------------
        // Onboarding
        // -------------------------------
        if ($stage === 'Onboard' && $pipeline->schedule_date) {
            $notifications[] = [
                'id' => uniqid(),
                'message' => 'Onboarding starts on ' . date('F j, Y', strtotime($pipeline->schedule_date)),
                'createdAt' => now()->toISOString(),
                'isUnread' => true,
            ];
        }

        return response()->json($notifications);
    }
}
