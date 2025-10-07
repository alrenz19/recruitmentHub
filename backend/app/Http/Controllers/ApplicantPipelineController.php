<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ApplicantPipelineController extends Controller
{
    /**
     * Return pipeline + latest initial & final interview scores + latest job offer for an applicant.
     *
     * Uses raw SQL with parameter binding and single-query fetch to reduce round trips.
     */
    public function getInterviewSummary($applicantId): JsonResponse
    {
        try {
            // Strongly type/cast input
            $applicantId = (int) $applicantId;
            if ($applicantId <= 0) {
                return response()->json(['message' => 'Invalid applicant id.'], 400);
            }

            $result = DB::selectOne("
                SELECT
                    -- pipeline
                    ap.id AS pipeline_id,
                    ap.applicant_id,
                    ap.current_stage_id,
                    ap.comments AS pipeline_comments,
                    ap.note,
                    ap.schedule_date,
                    ap.updated_at AS pipeline_updated_at,

                    -- initial interview (latest per pipeline)
                    init_row.id AS initial_score_id,
                    init_row.raw_score AS initial_raw_score,
                    init_row.overall_score AS initial_overall_score,
                    init_row.score_details AS initial_score_details,
                    init_row.comments AS initial_comments,
                    init_row.decision AS initial_decision,
                    init_user.full_name AS initial_interviewer_name,
                    init_row.created_at AS initial_created_at,

                    -- final interview (latest per pipeline)
                    final_row.id AS final_score_id,
                    final_row.raw_score AS final_raw_score,
                    final_row.overall_score AS final_overall_score,
                    final_row.score_details AS final_score_details,
                    final_row.comments AS final_comments,
                    final_row.decision AS final_decision,
                    final_user.full_name AS final_interviewer_name,
                    final_row.created_at AS final_created_at,

                    -- job offer (latest for applicant)
                    jo.id AS job_offer_id,
                    JSON_UNQUOTE(JSON_EXTRACT(jo.offer_details, '$.position')) AS job_offer_position,
                    jo.offer_details AS job_offer_details,
                    JSON_UNQUOTE(JSON_EXTRACT(jo.offer_details, '$.salary')) AS job_offer_salary,
                    jo.status AS job_offer_status,
                    jo.approved_by_user_id AS job_offer_approved_by,
                    jo.mngt_approved_at,
                    jo.fm_approved_at,
                    jo.approved_at AS job_offer_approved_at,
                    jo.created_at AS job_offer_created_at,
                    jo.updated_at AS job_offer_updated_at

                FROM applicant_pipeline ap

                -- select latest initial_interview row for this pipeline (if any)
                LEFT JOIN applicant_pipeline_score AS init_row
                  ON init_row.id = (
                    SELECT id
                    FROM applicant_pipeline_score
                    WHERE applicant_pipeline_id = ap.id
                      AND removed = 0
                      AND type = 'initial_interview'
                    ORDER BY created_at DESC
                    LIMIT 1
                  )
                LEFT JOIN hr_staff AS init_user ON init_user.id = init_row.interviewer_id

                -- select latest final_interview row for this pipeline (if any)
                LEFT JOIN applicant_pipeline_score AS final_row
                  ON final_row.id = (
                    SELECT id
                    FROM applicant_pipeline_score
                    WHERE applicant_pipeline_id = ap.id
                      AND removed = 0
                      AND type = 'final_interview'
                    ORDER BY created_at DESC
                    LIMIT 1
                  )
                LEFT JOIN hr_staff AS final_user ON final_user.id = final_row.interviewer_id

                -- latest job offer for this applicant (if any)
                LEFT JOIN job_offers AS jo
                  ON jo.id = (
                    SELECT id
                    FROM job_offers
                    WHERE applicant_id = :applicant_id
                      AND removed = 0
                    ORDER BY created_at DESC
                    LIMIT 1
                  )

                WHERE ap.applicant_id = :applicant_id2
                  AND ap.removed = 0
                ORDER BY ap.updated_at DESC
                LIMIT 1
            ", [
                'applicant_id'  => $applicantId,
                'applicant_id2' => $applicantId,
            ]);

            if (! $result) {
                return response()->json(['message' => 'No applicant pipeline found.'], 404);
            }

            // Shape the structured response
            $response = [
                'pipeline' => [
                    'id'               => $result->pipeline_id,
                    'applicant_id'     => $result->applicant_id,
                    'current_stage_id' => $result->current_stage_id,
                    'comments'         => $result->pipeline_comments,
                    'note'             => $result->note,
                    'schedule_date'    => $result->schedule_date,
                    'updated_at'       => $result->pipeline_updated_at,
                ],
                'initial_score' => $result->initial_score_id ? [
                    'id'                 => $result->initial_score_id,
                    'raw_score'          => $result->initial_raw_score,
                    'overall_score'      => $result->initial_overall_score,
                    'score_details'      => $result->initial_score_details,
                    'comments'           => $result->initial_comments,
                    'decision'           => $result->initial_decision,
                    'interviewer_name'   => $result->initial_interviewer_name,
                    'created_at'         => $result->initial_created_at,
                ] : null,
                'final_score' => $result->final_score_id ? [
                    'id'                 => $result->final_score_id,
                    'raw_score'          => $result->final_raw_score,
                    'overall_score'      => $result->final_overall_score,
                    'score_details'      => $result->final_score_details,
                    'comments'           => $result->final_comments,
                    'decision'           => $result->final_decision,
                    'interviewer_name'   => $result->final_interviewer_name,
                    'created_at'         => $result->final_created_at,
                ] : null,
                'job_offer' => $result->job_offer_id ? [
                    'id'                 => $result->job_offer_id,
                    'position'           => $result->job_offer_position,
                    'offer_details'      => $result->job_offer_details,
                    'status'             => $result->job_offer_status,
                    'approved_by_user_id'=> $result->job_offer_approved_by,
                    'mngt_approved_at'   => $result->mngt_approved_at,
                    'fm_approved_at'     => $result->fm_approved_at,
                    'approved_at'        => $result->job_offer_approved_at,
                    'salary'             => $result->job_offer_salary,
                    'created_at'         => $result->job_offer_created_at,
                    'updated_at'         => $result->job_offer_updated_at,
                ] : null,
            ];

            return response()->json($response, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Server error fetching interview summary.'
            ], 500);
        }


    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'applicant_pipeline_id' => 'required|integer|exists:applicant_pipeline,id',
                'type'                  => 'required|in:initial_interview,final_interview',

                // initial interview
                'raw_score'             => 'nullable|integer|min:0',
                'overall_score'         => 'nullable|integer|min:0',
                'overallComment'        => 'nullable|string',
                'overallImpression'     => 'nullable|string',
                'pass'                => 'nullable|in:passed,failed',
                'score_details'         => 'nullable|array',

                // final interview
                'decision'              => 'nullable|in:passed,failed',
                'comments'              => 'nullable|string',
            ]);

            $pipelineId = $validated['applicant_pipeline_id'];
            $type       = $validated['type'];
            $now        = now();

            $creatorUserId = auth()->id();
            $hrStaff = DB::table('hr_staff')->where('user_id', $creatorUserId)->first();

            $interviewerId   = $hrStaff->id ?? null;
            $interviewerRole = $hrStaff->position ?? null;

            DB::beginTransaction();

            // Invalidate board cache by bumping version
            Cache::increment('candidates_cache_version');
            $cacheVersion = Cache::get('candidates_cache_version', 1);
            // Build cache key based on version and request parameters
            $cacheKey = 'board_data_v' . $cacheVersion;
            Cache::forget($cacheKey);

            // ===========================================
            // INITIAL INTERVIEW
            // ===========================================
            if ($type === 'initial_interview') {
                $note = $validated['pass'];

                DB::statement(
                    "INSERT INTO applicant_pipeline_score
                        (applicant_pipeline_id, type, interviewer_id, raw_score, overall_score, comments, decision, score_details, created_at, updated_at)
                    VALUES
                        (?, 'initial_interview', ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        raw_score      = VALUES(raw_score),
                        overall_score  = VALUES(overall_score),
                        comments       = VALUES(comments),
                        decision       = VALUES(decision),
                        score_details  = VALUES(score_details),
                        updated_at     = VALUES(updated_at)",
                    [
                        $pipelineId,
                        $interviewerId,
                        $validated['raw_score'],
                        $validated['overall_score'],
                        $validated['overallComment'] ?? null,
                        $note,
                        $validated['score_details'] ? json_encode($validated['score_details']) : null,
                        $now,
                        $now
                    ]
                );

                DB::update(
                    "UPDATE applicant_pipeline SET note = ?, updated_at = ? WHERE id = ?",
                    [$note, $now, $pipelineId]
                );

                DB::commit();

                return response()->json([
                    'message' => 'Initial interview saved successfully.',
                    'note'    => $note
                ], 200);
            }

            // ===========================================
            // FINAL INTERVIEW
            // ===========================================
            if ($type === 'final_interview') {

                if (empty($interviewerRole) || empty($interviewerId) || empty($validated['decision'])) {
                    return response()->json([
                        'message' => 'Missing required fields for final interview.'
                    ], 422);
                }

                $decision  = $validated['decision'];
                $rawScore  = ($decision === 'passed') ? 1 : 0;

                //  lock rows to avoid race conditions
                DB::select(
                    "SELECT id FROM applicant_pipeline_score
                    WHERE applicant_pipeline_id = ? AND type = 'final_interview' FOR UPDATE",
                    [$pipelineId]
                );

                // UPSERT
                DB::statement(
                    "INSERT INTO applicant_pipeline_score
                        (applicant_pipeline_id, type, interviewer_id, raw_score, decision, comments, created_at, updated_at)
                    VALUES
                        (?, 'final_interview', ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        raw_score  = VALUES(raw_score),
                        decision   = VALUES(decision),
                        comments   = VALUES(comments),
                        updated_at = VALUES(updated_at)",
                    [
                        $pipelineId,
                        $interviewerId,
                        $rawScore,
                        $decision,
                        $validated['comments'] ?? null,
                        $now,
                        $now //61 59 58
                    ]
                );

                // count submitted & approvals
                $totals = DB::selectOne(
                    "SELECT SUM(raw_score) AS approvals, COUNT(*) AS total
                    FROM applicant_pipeline_score
                    WHERE applicant_pipeline_id = ? AND type = 'final_interview' AND raw_score = 1 AND removed = 0",
                    [$pipelineId]
                );

                $approvals = $totals->approvals ?? 0;
                $submitted = $totals->total ?? 0;

                $finalNote = 'In progress';
                $pipelineUpdated = false;

                // example: all 2 managers must submit
                if ($submitted === 2) {
                    $finalNote = ($approvals >= 2) ? 'passed' : 'failed';
                    DB::update(
                        "UPDATE applicant_pipeline SET note = ?, updated_at = ? WHERE id = ?",
                        [$finalNote, $now, $pipelineId]
                    );
                    $pipelineUpdated = true;
                }

                DB::commit();

                return response()->json([
                    'message'          => 'Final interview decision saved successfully.',
                    'approvals'        => $approvals,
                    'submitted'        => $submitted,
                    'note'             => $finalNote,
                    'pipelineUpdated'  => $pipelineUpdated
                ], 200);
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to save interview data.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

}
