<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function getStats(Request $request)
    {
        $filter = $request->query('filter', 'all'); // week, month, all
        $queryRange = null;

        if ($filter === 'week') {
            $queryRange = now()->startOfWeek();
        } elseif ($filter === 'month') {
            $queryRange = now()->startOfMonth();
        }

        // =========================
        // Applicants (total count)
        // =========================
        $applicantsQuery = DB::table('applicants')->where('in_active', 0);
        if ($queryRange) {
            $applicantsQuery->where('created_at', '>=', $queryRange);
        }
        $totalApplicants = $applicantsQuery->count();

        // =========================
        // Pipeline counts by stage
        // =========================
        $pipelineQuery = DB::table('applicant_pipeline');
        if ($queryRange) {
            $pipelineQuery->where('created_at', '>=', $queryRange);
        }

        $pipelineCounts = $pipelineQuery
            ->select('current_stage_id', DB::raw('COUNT(*) as count'))
            ->groupBy('current_stage_id')
            ->pluck('count', 'current_stage_id');

        // =========================
        // Job offer counts by status
        // =========================
        $jobOffersQuery = DB::table('job_offers');
        if ($queryRange) {
            $jobOffersQuery->where('created_at', '>=', $queryRange);
        }

        $jobOfferCounts = $jobOffersQuery
            ->select('status', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['pending', 'pending_ceo', 'approved', 'declined'])
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'pipeline' => [
                'applicants'        => $totalApplicants,
                'assessment'        => $pipelineCounts[1] ?? 0,
                'initial_interview' => $pipelineCounts[2] ?? 0,
                'final_interview'   => $pipelineCounts[3] ?? 0,
                'job_offer'         => $pipelineCounts[4] ?? 0,
                'intake'            => $pipelineCounts[5] ?? 0,
            ],
            'jobOfferStatuses' => [
                'pending'  => ($jobOfferCounts['pending'] ?? 0) + ($jobOfferCounts['pending_ceo'] ?? 0),
                'approved' => $jobOfferCounts['approved'] ?? 0,
                'declined' => $jobOfferCounts['declined'] ?? 0,
            ],
        ]);
    }
}
