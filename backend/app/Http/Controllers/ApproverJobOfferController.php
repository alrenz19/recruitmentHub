<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ApplicantDashboard;
use App\Models\ApproverJobOffer;

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
        // 📌 Pagination params from frontend
        $page     = max((int) $request->input('page', 1), 1);
        $perPage  = max((int) $request->input('per_page', 10), 1);
        $offset   = ($page - 1) * $perPage;

        // ✅ Query stats (aggregation, only 1 row)
        $stats = DB::selectOne("
            SELECT 
                COUNT(DISTINCT a.id) AS totalApplicants,
                SUM(CASE WHEN jo.status = 'approved' THEN 1 ELSE 0 END) AS totalAccepted,
                SUM(CASE WHEN jo.status = 'declined' THEN 1 ELSE 0 END) AS totalRejected
            FROM applicants a
            LEFT JOIN job_offers jo ON jo.applicant_id = a.id
        ");

        // ✅ Paginated job offers
        $offers = DB::select("
            SELECT 
                jo.id AS sn,
                COALESCE(a.full_name, 'Unknown Applicant') AS applicant,
                CASE 
                    WHEN jo.status IN ('pending_ceo', 'pending') THEN 'Offer Pending'
                    WHEN jo.status = 'approved' THEN 'Offer Accepted'
                    WHEN jo.status = 'declined' THEN 'Offer Declined'
                    ELSE 'Offer Pending'
                END AS status
            FROM job_offers jo
            LEFT JOIN applicants a ON jo.applicant_id = a.id
            WHERE jo.status = :status
            ORDER BY jo.created_at DESC
            LIMIT :perPage OFFSET :offset
        ", [
            'status'   => 'pending_ceo',
            'perPage'  => $perPage,
            'offset'   => $offset,
        ]);

        // ✅ Count total filtered offers (for FE pagination)
        $totalOffers = DB::selectOne("
            SELECT COUNT(*) AS total
            FROM job_offers
            WHERE status = :status
        ", ['status' => 'pending_ceo'])->total;

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


    public function getSignature()
    {
        $UserId = auth()->id();
        $signature = DB::table('hr_staff')
            ->select('signature')
            ->where('user_id', $UserId)
            ->first()
            ->signature;

        if (!$signature) {
            return response()->json(['signature' => null], 200);
        }

        return response()->json([
            'signature' => asset('storage/' . $signature),
        ]);
    }

    public function deleteSignature()
    {
        DB::table('hr_staff')
            ->where('user_id', 58)
            ->update(['signature' => null, 'updated_at' => now()]);

        return response()->json([
            'message' => 'Signature deleted successfully',
        ]);
    }


    public function storeSignature(Request $request)
    {
        $request->validate([
            'signature' => 'required|file|mimes:png,jpg,jpeg|max:2048',
        ]);

        $path = $request->file('signature')->store('signatures', 'public');

        // Save signature path for approver (user_id = 58)
        DB::table('hr_staff')
            ->where('user_id', 58)
            ->update(['signature' => $path, 'updated_at' => now()]);

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

        // 1️⃣ Fetch HR staff id for this user
        $hrStaff = DB::selectOne("SELECT id FROM hr_staff WHERE user_id = ? LIMIT 1", [$userId]);
        if (!$hrStaff) {
            return response()->json(['message' => 'HR staff not found'], 404);
        }

        $hrId = $hrStaff->id;

        // 2️⃣ Fetch job offer details
        $jobOffer = DB::selectOne("
            SELECT id, status, management_id, fm_id, approved_by_user_id
            FROM job_offers
            WHERE id = ?
            LIMIT 1
        ", [$id]);

        if (!$jobOffer) {
            return response()->json(['message' => 'Job offer not found'], 404);
        }

        // 3️⃣ Determine actor automatically
        $actor = null;
        if ($jobOffer->status === 'pending_management' && $jobOffer->management_id == $hrId) {
            $actor = 'management';
        } elseif ($jobOffer->status === 'pending_fm' && $jobOffer->fm_id == $hrId) {
            $actor = 'fm';
        } elseif ($jobOffer->status === 'pending_ceo' && $jobOffer->approved_by_user_id == $hrId) {
            $actor = 'admin';
        }

        if (!$actor) {
            return response()->json(['message' => 'Unauthorized or invalid approver'], 403);
        }

        // 4️⃣ Decide next status in the workflow
        $newStatus = $request->input('status');
        if ($newStatus === 'approved') {
            switch ($actor) {
                case 'management':
                    $newStatus = 'pending_fm';
                    break;
                case 'fm':
                    $newStatus = 'pending_ceo';
                    break;
                case 'admin':
                    $newStatus = 'pending_applicant'; // final approval
                    break;
            }
        } elseif ($newStatus === 'rejected') {
            $newStatus = 'reject'; // stop the flow
        }

        // 5️⃣ Update job offer safely
        $updated = DB::update("
            UPDATE job_offers
            SET status = ?, declined_reason = ?, updated_at = NOW()
            WHERE id = ?
        ", [
            $newStatus,
            $request->input('declined_reason') ?? null,
            $id
        ]);

        if (!$updated) {
            return response()->json(['message' => 'Failed to update job offer'], 500);
        }

        // 6️⃣ Dispatch background job
        dispatch(new ProcessJobOfferPipelineAndNotifications(
            $id,
            $request->input('status'),
            $actor,
            $userId
        ));

        return response()->json([
            'message' => "Job offer {$request->input('status')} successfully by {$actor}"
        ]);
    }




}
