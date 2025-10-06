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
            4 => 'Job Offer',
            5 => 'Onboard',
        ];


        // Check if applicant has pending job offer
        $pendingOffer = DB::table('job_offers')
            ->where('applicant_id', $applicant->applicant_id)
            ->where('status', 'pending_applicant')
            ->orderByDesc('id')
            ->first();



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
                'Job Offer'         => 'You accepted the job offer',
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

            if ($stageOrder[$curr] === 'Job Offer' && $pendingOffer) {
                $steps[$currIndex]['description'] = "Congratulations! We want you on our team, please see the attached job offer";
                $steps[$currIndex]['jobOfferId'] = $pendingOffer->id; // 🔥 expose job_offer.id
            } elseif ($note === 'pending' && !empty($pipeline->schedule_date)) {
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
            'jobOfferId'      => $pendingOffer->id ?? null, // optional: expose at root too
        ]);

    }
    

    public function showOffer($id)
    {
        $userId = Auth::id();

        $offer = DB::table('job_offers')
            ->join('applicants', 'job_offers.applicant_id', '=', 'applicants.id')
            ->where('job_offers.id', $id)
            ->where('applicants.user_id', $userId) // 🔒 ensure only owner can see
            ->select('job_offers.*', 'applicants.full_name')
            ->first();

        if (!$offer) {
            return response()->json(['error' => 'Offer not found'], 404);
        }

        return response()->json($offer);
    }

    public function storeSignature(Request $request)
    {
        $request->validate([
            'signature' => 'required|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        $userId = Auth::id();
        $applicant = DB::table('applicants')->where('user_id', $userId)->first();

        if (!$applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $path = $request->file('signature')->store('signatures', 'public');

        // Save signature in applicants table
        DB::table('applicants')
            ->where('user_id', $userId)
            ->update([
                'signature' => $path,
                'updated_at' => now(),
            ]);

        // 🔥 Also update the latest pending_applicant offer → move it forward
        DB::table('job_offers')
            ->where('applicant_id', $applicant->id)
            ->where('status', 'pending_applicant')
            ->latest('id')
            ->limit(1)
            ->update([
                'status'      => 'approved_applicant',
                'accepted_at' => now(),
                'updated_at'  => now(),
            ]);

        return response()->json([
            'message'   => 'Signature saved successfully',
            'signature' => asset('storage/' . $path),
        ]);
    }

    public function updateOfferStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:accepted,declined',
            'declined_reason' => 'required_if:status,declined',
        ]);

        $userId = Auth::id();

        $offer = DB::table('job_offers')
            ->join('applicants', 'job_offers.applicant_id', '=', 'applicants.id')
            ->where('job_offers.id', $id)
            ->where('applicants.user_id', $userId)
            ->select('job_offers.id')
            ->first();

        if (!$offer) {
            return response()->json(['error' => 'Unauthorized or Offer not found'], 403);
        }

        $updateData = [
            'updated_at' => now(),
        ];

        if ($request->status === 'accepted') {
            $updateData['status'] = 'approved_applicant'; // ✅ match your rule
            $updateData['accepted_at'] = now();
        } else {
            $updateData['status'] = 'declined_applicant'; // ✅ match your rule
            $updateData['declined_reason'] = $request->declined_reason;
            $updateData['declined_at'] = now();
        }

        DB::table('job_offers')->where('id', $id)->update($updateData);

        return response()->json([
            'message' => "Job offer {$request->status} successfully",
        ]);
    }

    public function getSignatures($jobOfferId)
    {
        $offer = DB::table('job_offers')
            ->join('applicants', 'job_offers.applicant_id', '=', 'applicants.id')
            ->where('job_offers.id', $jobOfferId)
            ->select('applicants.user_id as applicant_user_id')
            ->first();

        if (!$offer) {
            return response()->json(['error' => 'Offer not found'], 404);
        }

        // Get applicant signature
        $applicantSig = DB::table('applicants')
            ->where('user_id', $offer->applicant_user_id)
            ->value('signature');

        // Get admin (CEO) signature
        $adminSig = DB::table('hr_staff')
            ->where('user_id', 58) // CEO user
            ->value('signature');

        return response()->json([
            'applicant_signature' => $applicantSig ? asset('storage/' . $applicantSig) : null,
            'admin_signature'     => $adminSig ? asset('storage/' . $adminSig) : null,
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
            'Job Offer'             => $result === 'accepted'
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
