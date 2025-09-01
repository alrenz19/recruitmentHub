<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    protected $cacheDuration = 300; // 5 minutes

    public function getStats(Request $request)
    {
        $filter = $request->query('filter', 'all');
        $queryRange = $this->getQueryRange($filter);

        $results = DB::select("
            SELECT 'applicants' as type, NULL as category, COUNT(*) as count
            FROM applicants 
            WHERE removed = 0 {$this->getDateCondition('applicants', $queryRange)}
            
            UNION ALL
            
            SELECT 'pipeline' as type, current_stage_id as category, COUNT(*) as count
            FROM applicant_pipeline 
            {$this->getDateCondition('applicant_pipeline', $queryRange, 'WHERE')}
            GROUP BY current_stage_id
            
            UNION ALL
            
            SELECT 'job_offers' as type, status as category, COUNT(*) as count
            FROM job_offers 
            WHERE status IN ('pending', 'pending_ceo', 'approved', 'declined')
            {$this->getDateCondition('job_offers', $queryRange, 'AND')}
            GROUP BY status
        ");

        return response()->json($this->processResults($results));
}

    private function getQueryRange($filter)
    {
        return match($filter) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => null
        };
    }

    private function getDateCondition($table, $queryRange, $prefix = 'AND')
    {
        if (!$queryRange) return '';
        return "{$prefix} {$table}.created_at >= '" . $queryRange->format('Y-m-d H:i:s') . "'";
    }

    private function processResults($results)
    {
        $data = [
            'pipeline' => [
                'applicants' => 0,
                'assessment' => 0,
                'initial_interview' => 0,
                'final_interview' => 0,
                'job_offer' => 0,
                'intake' => 0,
            ],
            'jobOfferStatuses' => [
                'pending' => 0,
                'approved' => 0,
                'declined' => 0,
            ]
        ];

        foreach ($results as $result) {
            if ($result->type === 'applicants') {
                $data['pipeline']['applicants'] = (int)$result->count;
            } 
            elseif ($result->type === 'pipeline') {
                $stageId = (int)$result->category;
                $count = (int)$result->count;
                
                $stageMap = [
                    1 => 'assessment',
                    2 => 'initial_interview', 
                    3 => 'final_interview',
                    4 => 'job_offer',
                    5 => 'intake'
                ];
                
                if (isset($stageMap[$stageId])) {
                    $data['pipeline'][$stageMap[$stageId]] = $count;
                }
            }
            elseif ($result->type === 'job_offers') {
                $status = $result->category;
                $count = (int)$result->count;
                
                if ($status === 'pending' || $status === 'pending_ceo') {
                    $data['jobOfferStatuses']['pending'] += $count;
                } else {
                    $data['jobOfferStatuses'][$status] = $count;
                }
            }
        }

        return $data;
    }

    /**
     * Manually clear dashboard cache (increment version)
     */
    public function clearCache($filter = 'all')
    {
        Cache::increment('dashboard_stats_cache_version');

        return response()->json([
            'message' => "Dashboard cache cleared for filter '{$filter}'"
        ]);
    }
}
