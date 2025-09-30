<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\JobOfferPipelineService;
use App\Services\JobOfferNotificationService;
use App\Jobs\ProcessJobOfferPipelineAndNotifications;

class ApproverJobOfferController extends Controller
{
    public function show($id)
    {
        $offer = DB::table('job_offers')
            ->leftJoin('applicants', 'job_offers.applicant_id', '=', 'applicants.id')
            ->select('job_offers.id', 'job_offers.offer_details', 'job_offers.created_at', 'applicants.full_name')
            ->where('job_offers.id', intval($id))
            ->first();

        if (!$offer) {
            return response()->json(['message' => 'Job offer not found'], 404);
        }

        return response()->json($offer);
    }

    public function index(Request $request)
    {
        $page = max((int) $request->input('page', 1), 1);
        $perPage = max((int) $request->input('per_page', 10), 1);
        $offset = ($page - 1) * $perPage;

        $userId = auth()->id();

        // Map user_id to status
        $statusMap = [
            59 => 'pending_management',
            61 => 'pending_fm',
            58 => 'pending_ceo',
        ];

        $status = $statusMap[$userId] ?? null;

        if (!$status) {
            return response()->json([
                'message' => 'Unauthorized or no status mapping for this user.',
            ], 403);
        }

        // Stats
        $stats = DB::selectOne("
            SELECT 
                COUNT(DISTINCT a.id) AS totalApplicants,
                SUM(CASE WHEN jo.status = 'approved' THEN 1 ELSE 0 END) AS totalAccepted,
                SUM(CASE WHEN jo.status = 'reject' THEN 1 ELSE 0 END) AS totalRejected
            FROM applicants a
            LEFT JOIN job_offers jo ON jo.applicant_id = a.id
        ");

        // Offers
        $offers = DB::select("
            SELECT 
                jo.id AS sn,
                COALESCE(a.full_name, 'Unknown Applicant') AS applicant,
                CASE 
                    WHEN jo.status IN ('pending_ceo','pending_applicant','pending_management','pending_fm') THEN 'Offer Pending'
                    WHEN jo.status = 'approved' THEN 'Offer Accepted'
                    WHEN jo.status = 'reject' THEN 'Offer Declined'
                    ELSE 'Offer Pending'
                END AS status
            FROM job_offers jo
            LEFT JOIN applicants a ON jo.applicant_id = a.id
            WHERE jo.status = :status
            ORDER BY jo.created_at DESC
            LIMIT :perPage OFFSET :offset
        ", [
            'status' => $status,
            'perPage' => $perPage,
            'offset' => $offset,
        ]);

        // Total filtered count
        $totalOffers = DB::selectOne("
            SELECT COUNT(*) AS total 
            FROM job_offers 
            WHERE status = :status
        ", ['status' => $status])->total;

        return response()->json([
            'stats' => $stats,
            'offers' => $offers,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalOffers,
                'last_page' => ceil($totalOffers / $perPage),
            ],
        ]);
    }

    public function getSignature($userId)
    {
        $record = DB::table('hr_staff')
            ->select('signature')
            ->where('user_id', $userId)
            ->first();

        if (!$record || !$record->signature) {
            return response()->json(['signature' => null], 200);
        }

        return response()->json([
            'signature' => asset('storage/' . $record->signature),
        ]);
    }

    public function storeSignature(Request $request)
    {
        $request->validate([
            'signature' => 'required|file|mimes:png,jpg,jpeg|max:2048',
        ]);

        $path = $request->file('signature')->store('signatures', 'public');
        $userId = auth()->id();

        DB::table('hr_staff')
            ->where('user_id', $userId)
            ->update([
                'signature' => $path,
                'updated_at' => now(),
            ]);

        // 🔥 If CEO, auto-update latest job offer to pending_applicant
        if ($userId == 58) {
            DB::table('job_offers')
                ->where('status', 'pending_ceo')
                ->latest('id')
                ->limit(1)
                ->update([
                    'status' => 'pending_applicant',
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);
        } elseif ($userId == 59) {
            DB::table('job_offers')
                ->where('status', 'pending_management')
                ->latest('id')
                ->limit(1)
                ->update([
                    'status' => 'pending_fm',
                    'mngt_approved_at' => now(),
                    'updated_at' => now(),
                ]);
        } elseif ($userId == 61) {
            DB::table('job_offers')
                ->where('status', 'pending_fm')
                ->latest('id')
                ->limit(1)
                ->update([
                    'status' => 'pending_ceo',
                    'fm_approved_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'Signature saved successfully',
            'path' => $path,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
            'declined_reason' => 'required_if:status,rejected',
        ]);

        $userId = auth()->id();

        // Fetch job offer
        $jobOffer = DB::table('job_offers')->where('id', $id)->first();

        if (!$jobOffer) {
            return response()->json(['message' => 'Job offer not found'], 404);
        }

        $newStatus = $request->input('status');
        $declinedReason = $request->input('declined_reason') ?? null;

        $updateData = [
            'updated_at' => now(),
        ];

        if ($newStatus === 'approved') {
            if ($userId == 59) {
                // Management approval requires signature upload
                $hasSignature = DB::table('hr_staff')
                    ->where('user_id', $userId)
                    ->value('signature');

                if (!$hasSignature) {
                    return response()->json([
                        'message' => 'Must upload signature before approving.'
                    ], 422); // Unprocessable Entity
                }
                
                $updateData['status'] = 'pending_fm';
                $updateData['mngt_approved_at'] = now();

            } elseif ($userId == 61) {
                // FM approval requires signature upload
                $hasSignature = DB::table('hr_staff')
                    ->where('user_id', $userId)
                    ->value('signature');

                if (!$hasSignature) {
                    return response()->json([
                        'message' => 'Must upload signature before approving.'
                    ], 422); // Unprocessable Entity
                }

                $updateData['status'] = 'pending_ceo';
                $updateData['fm_approved_at'] = now();

            } elseif ($userId == 58) {
                // CEO approval requires signature upload
                $hasSignature = DB::table('hr_staff')
                    ->where('user_id', $userId)
                    ->value('signature');

                if (!$hasSignature) {
                    return response()->json([
                        'message' => 'CEO must upload signature before approving.'
                    ], 422); // Unprocessable Entity
                }

                $updateData['status'] = 'pending_applicant';
                $updateData['approved_at'] = now();
            }
        } elseif ($newStatus === 'rejected') {
            $updateData['status'] = 'reject';
            $updateData['declined_reason'] = $declinedReason;
            $updateData['declined_at'] = now();
        }

        DB::table('job_offers')->where('id', $id)->update($updateData);

        return response()->json([
            'message' => "Job offer {$newStatus} successfully",
        ]);
    }
}
