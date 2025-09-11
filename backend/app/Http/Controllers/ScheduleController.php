<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ScheduleController extends Controller
{
    // Fetch all schedules
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // default 10
        $page = $request->input('page', 1);
        $stageParam = trim($request->input('stage', '')); // e.g. "Final Interview"

        $offset = ($page - 1) * $perPage;

        // Get the current stage_order from DB
        $currentStage = DB::table('recruitment_stages')
            ->where('stage_name', $stageParam)
            ->where('is_removed', 0)
            ->first();

        if (!$currentStage) {
            return response()->json([]); // invalid stage name
        }

        // Next stage is +1 order
        $nextStageOrder = $currentStage->stage_order + 1;

        // Get next stage details
        $nextStage = DB::table('recruitment_stages')
            ->where('stage_order', $nextStageOrder)
            ->where('is_removed', 0)
            ->first();

        if (!$nextStage) {
            return response()->json([]); // no next stage (e.g. already at last stage)
        }

        $schedules = DB::select("
            SELECT 
                a.full_name,
                ap.schedule_date,
                rs.stage_name
            FROM applicant_pipeline ap
            JOIN applicants a 
                ON a.id = ap.applicant_id
            JOIN recruitment_stages rs 
                ON rs.id = ap.current_stage_id
            WHERE ap.removed = ? 
            AND ap.note = ?
            AND rs.stage_order = ?
            ORDER BY ap.schedule_date ASC
            LIMIT ? OFFSET ?
        ", [0, "Pending", $nextStage->stage_order, $perPage, $offset]);

        return response()->json($schedules);
    }


    public function updateSchedule(Request $request)
    {
        $creator = auth()->id();

        $validated = $request->validate([
            'applicant_id' => 'required|integer|exists:applicants,id',
            'stage'        => 'required|string',
            'date'         => 'required|date',
            'time'         => 'required',
            'participants' => 'nullable|string', // comma-separated
        ]);

        // Combine date + time
        $scheduleDateTime = $validated['date'] . ' ' . $validated['time'];

        // Get stage ID
        $stage = DB::selectOne(
            "SELECT id FROM recruitment_stages WHERE stage_name = ? LIMIT 1",
            [$validated['stage']]
        );

        if (!$stage) {
            return response()->json(['message' => 'Invalid stage provided'], 422);
        }

        DB::transaction(function () use ($validated, $scheduleDateTime, $stage, $creator) {
            // Update applicant_pipeline
            $updated = DB::update(
                "UPDATE applicant_pipeline
                SET schedule_date = ?, current_stage_id = ?, note = 'Pending', updated_by_user_id = ?, updated_at = NOW()
                WHERE applicant_id = ?",
                [
                    $scheduleDateTime,
                    $stage->id,
                    $creator,
                    $validated['applicant_id'],
                ]
            );

            if ($updated === 0) {
                throw new \Exception('No pipeline found for this applicant');
            }

            // Get pipeline ID
            $pipelineId = DB::table('applicant_pipeline')
                            ->where('applicant_id', $validated['applicant_id'])
                            ->value('id');


            // **Mark old scores as removed**
            DB::table('applicant_pipeline_score')
                ->where('applicant_pipeline_id', $pipelineId)
                ->update(['removed' => 1]);

            // Delete old participants
            DB::table('participants')->where('applicant_pipeline_id', $pipelineId)->delete();

            // Insert new participants
            $participantNames = array_filter(array_map('trim', explode(',', $validated['participants'] ?? '')));
            foreach ($participantNames as $name) {
                DB::table('participants')->insert([
                    'applicant_pipeline_id' => $pipelineId,
                    'name'                  => $name,
                    'removed'               => 0,
                ]);
            }
        });

        Cache::increment('candidates_cache_version');
        $cacheVersion = Cache::get('candidates_cache_version', 1);
        // Build cache key based on version and request parameters
        $cacheKey = 'board_data_v' . $cacheVersion;
        Cache::forget($cacheKey);

        return response()->json([
            'message' => 'Pipeline, schedule, and participants updated successfully',
        ]);
    }


}
