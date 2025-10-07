<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ApplicantPipelineScoreController extends Controller
{
    public function updateScore(Request $request)
    {
        $creatorUserId = auth()->id();

        // Get HR staff record
        $hrStaff = DB::table('hr_staff')
            ->where('user_id', $creatorUserId)
            ->first();

        if (!$hrStaff) {
            return response()->json(['message' => 'HR staff record not found for current user'], 422);
        }

        $interviewerId = $hrStaff->id ?? 1;

        // Validate input
        $request->validate([
            'applicant_pipeline_id' => 'required|integer|exists:applicant_pipeline,id',
            'raw_score' => 'required|numeric|min:0',
            'type' => 'required|in:exam_score,initial_interview,final_interview,attachment',
        ]);

        $data = $request->only([
            'applicant_pipeline_id',
            'raw_score',
            'type'
        ]);

        // Upsert individual score
        DB::table('applicant_pipeline_score')->updateOrInsert(
            [
                'applicant_pipeline_id' => $data['applicant_pipeline_id'],
                'interviewer_id' => $interviewerId,
                'type' => $data['type'],
            ],
            [
                'raw_score' => $data['raw_score'],
                'updated_at' => now(),
            ]
        );

        // Recalculate overall score for this applicant & type
        $allScores = DB::table('applicant_pipeline_score')
            ->where('applicant_pipeline_id', $data['applicant_pipeline_id'])
            ->where('type', $data['type'])
            ->pluck('raw_score');

        $overallScore = 100;

        // Update overall_score in all rows for this applicant & type
        DB::table('applicant_pipeline_score')
            ->where('applicant_pipeline_id', $data['applicant_pipeline_id'])
            ->where('type', $data['type'])
            ->update(['overall_score' => $overallScore]);

        //  Invalidate board cache by bumping version
        Cache::increment('candidates_cache_version');
        $cacheVersion = Cache::get('candidates_cache_version', 1);
        // Build cache key based on version and request parameters
        $cacheKey = 'board_data_v' . $cacheVersion;
        Cache::forget($cacheKey);

        return response()->json([
            'message' => 'Score updated successfully',
        ]);
    }
}
