<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ApplicantDashboard;
use App\Models\ApproverJobOffer;

class ApproverJobOfferController extends Controller
{
    public function show($id)
    {
        $offer = DB::table('job_offers')
            ->join('applicants', 'job_offers.applicant_id', '=', 'applicants.id')
            ->select(
                'job_offers.id',
                'job_offers.offer_details',
                'job_offers.created_at',
                'applicants.full_name'
            )
            ->where('job_offers.id', $id)
            ->first();

        if (!$offer) {
            return response()->json(['message' => 'Job offer not found'], 404);
        }

        return response()->json($offer);
    }


    public function index()
    {
        // ✅ Count all applicants from applicants table
        $totalApplicants = DB::table('applicants')->count();

        // ✅ Count approved and declined job offers
        $totalApproved = DB::table('job_offers')->where('status', 'approved')->count();
        $totalRejected = DB::table('job_offers')->where('status', 'declined')->count();

        // ✅ Get job offers with applicant names and mapped status
        $offers = DB::table('job_offers')
            ->leftJoin('applicants', 'job_offers.applicant_id', '=', 'applicants.id')
            ->select(
                'job_offers.id as sn',
                DB::raw("COALESCE(applicants.full_name, 'Unknown Applicant') as applicant"),
                DB::raw("CASE 
                    WHEN job_offers.status = 'pending_ceo' THEN 'Offer Pending'
                    WHEN job_offers.status = 'pending' THEN 'Offer Pending'
                    WHEN job_offers.status = 'approved' THEN 'Offer Accepted'
                    WHEN job_offers.status = 'declined' THEN 'Offer Declined'
                    ELSE 'Offer Pending'
                END as status")
            )
            ->orderBy('job_offers.created_at', 'desc')
            ->get();


        // ✅ Return in JSON format expected by frontend
        return response()->json([
            'stats' => [
                'totalApplicants' => $totalApplicants,
                'totalAccepted'   => $totalApproved,
                'totalRejected'   => $totalRejected,
            ],
            'offers' => $offers,
        ]);
    }

    public function storeSignature(Request $request)
    {
        $request->validate([
            'signature' => 'required|file|mimes:png,jpg,jpeg|max:2048',
        ]);

        $path = $request->file('signature')->store('signatures', 'public');

        return response()->json([
            'message' => 'Signature saved successfully',
            'path' => $path,
        ]);
    }

}
