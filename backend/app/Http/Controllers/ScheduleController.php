<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Mail\ApplicantScheduleMail;
use App\Mail\ParticipantScheduleMail;
use Illuminate\Support\Facades\Mail;
use App\Services\NotificationService;

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
            ->where('removed', 0)
            ->first();

        if (!$currentStage) {
            return response()->json([]); // invalid stage name
        }

        // Next stage is +1 order
        $nextStageOrder = $currentStage->stage_order + 1;

        // Get next stage details
        $nextStage = DB::table('recruitment_stages')
            ->where('stage_order', $nextStageOrder)
            ->where('removed', 0)
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
            'mode'         => 'required|in:Face-to-Face,Online',
            'link'         => 'nullable|string',
            'participants' => 'nullable|array',
            'participants.*.id'    => 'required|integer',
            'participants.*.name'  => 'required|string',
            'participants.*.email' => 'required|email',
        ]);

        $scheduleDateTime = $validated['date'] . ' ' . $validated['time'];

        $stage = DB::selectOne(
            "SELECT id FROM recruitment_stages WHERE stage_name = ? LIMIT 1",
            [$validated['stage']]
        );

        if (!$stage) {
            return response()->json(['message' => 'Invalid stage provided'], 422);
        }

        // Validate meeting link for online interviews
        if ($validated['mode'] === 'Online' && empty($validated['link'])) {
            return response()->json(['message' => 'Meeting link is required for online interviews'], 422);
        }

        $participants = [];
        $applicant = null;

        DB::transaction(function () use ($validated, $scheduleDateTime, $stage, $creator, &$participants, &$applicant) {
            // Update pipeline
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

            $pipelineId = DB::table('applicant_pipeline')
                            ->where('applicant_id', $validated['applicant_id'])
                            ->value('id');

            DB::table('applicant_pipeline_score')
                ->where('applicant_pipeline_id', $pipelineId)
                ->update(['removed' => 1]);

            DB::table('participants')->where('applicant_pipeline_id', $pipelineId)->delete();

            if (!empty($validated['participants'])) {
                foreach ($validated['participants'] as $p) {
                    DB::table('participants')->insert([
                        'applicant_pipeline_id' => $pipelineId,
                        'name'                  => $p['name'],
                        'removed'               => 0,
                    ]);
                    $participants[] = $p;
                }
            }

            DB::table('recruitment_notes')
                ->where('applicant_id', $validated['applicant_id'])
                ->update(['removed' => 1]);

            $applicant = DB::table('applicants')->where('id', $validated['applicant_id'])->first();
            $mode = $validated['mode'] === 'Face-to-Face' ? 'Face to Face' : 'Online';
            $link = $validated['link'] ?? null;
            // ✅ Queue emails only after commit
            DB::afterCommit(function () use ($applicant, $validated, $participants, $scheduleDateTime, $mode, $link) {
                if ($applicant) {
                    Mail::to($applicant->email)
                        ->queue(new ApplicantScheduleMail(
                            $applicant->full_name,
                            $validated['stage'],
                            $validated['date'],
                            $validated['time'],
                            array_map(fn($p) => $p['name'], $participants),
                            $mode?? null,
                            $link ?? null
                        ));

                    foreach ($participants as $p) {
                        Mail::to($p['email'])
                            ->queue(new ParticipantScheduleMail(
                                $p['name'],
                                $applicant->full_name,
                                $applicant->position_desired,
                                $scheduleDateTime,
                                $validated['stage'],
                                $mode ?? null,
                                $link ?? null
                            ));
                    }

                    $message = "You have a new scheduled interview for {$validated['stage']} on {$validated['date']} at {$validated['time']} with toyoflex.";

                    NotificationService::send($applicant->user_id, "New Scheduled", $message, 'assessment', '/dashboard');
                }
                
            });
        });

        Cache::increment('candidates_cache_version');
        $cacheVersion = Cache::get('candidates_cache_version', 1);
        $cacheKey = 'board_data_v' . $cacheVersion;
        Cache::forget($cacheKey);

        return response()->json([
            'message' => 'Pipeline, schedule, and participants updated successfully',
        ]);
    }



}
