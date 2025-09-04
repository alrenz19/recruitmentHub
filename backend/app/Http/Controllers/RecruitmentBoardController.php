<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Candidate;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class RecruitmentBoardController extends Controller
{
    protected $stageIdMapping = [
        'Assessment' => 1,
        'Initial Interview' => 2,
        'Final Interview' => 3,
        'Hired' => 4,
        'Onboard' => 5,
    ];

    protected $scoreTypes = [
        'Assessment' => 'exam_score',
        'Initial Interview' => 'initial_interview',
        'Final Interview' => 'final_interview',
        'Hired' => 'hired',
        'Onboard' => 'onboard'
    ];

    protected $statusVariants = [
        'done'      => 'green',
        'pending'   => 'yellow',
        'failed'    => 'red',
        'passed'    => 'green',
        'cancelled' => 'red',
        'declined'  => 'red',
        'confirm'   => 'yellow',
        'onboard'   => 'green',
        'hired'     => 'green'
    ];

    protected $stageStatusOrder = [
        'Assessment' => ['done', 'passed', 'failed', 'pending', 'cancelled'],
        'Initial Interview' => ['passed', 'confirm', 'pending', 'failed', 'cancelled'],
        'Final Interview' => ['passed', 'confirm', 'pending', 'failed', 'cancelled'],
        'Hired' => ['hired', 'pending', 'confirm', 'declined'],
        'Onboard' => ['onboard', 'pending', 'declined'],
    ];

    protected function getStageOrderSQL(string $stage, string $tableAlias = 'p'): string
    {
        $statusOrder = $this->stageStatusOrder[$stage] ?? null;

        if ($statusOrder) {
            $orderCases = [];
            foreach ($statusOrder as $index => $status) {
                $statusLower = strtolower($status);
                $orderCases[] = "WHEN LOWER({$tableAlias}.note) = '{$statusLower}' THEN " . ($index + 1);
            }
            return "CASE " . implode(' ', $orderCases) . " ELSE " . (count($statusOrder) + 1) . " END ASC";
        }

        // Default fallback
        return "{$tableAlias}.id ASC";
    }

    public function getStageApplicants(Request $request, $stage)
    {
        $page = (int) $request->get('page', 1);
        $perPage = 5;
        $offset = ($page - 1) * $perPage;
        $orderBySQL = $this->getStageOrderSQL($stage, 'p');

        if (!isset($this->stageIdMapping[$stage])) {
            return response()->json(['error' => 'Invalid stage'], 400);
        }

        $stageId = $this->stageIdMapping[$stage];
        $scoreType = $this->scoreTypes[$stage];

        // Ensure GROUP_CONCAT can handle long lists
        DB::statement("SET SESSION group_concat_max_len = 1000000;");

        // Total count of applicants in this stage
        $totalResult = DB::selectOne("
            SELECT COUNT(DISTINCT a.id) AS total
            FROM applicants a
            JOIN applicant_pipeline p 
                ON p.applicant_id = a.id
                AND p.current_stage_id = ?
                AND p.removed = ?
            WHERE a.in_active = ?
            AND a.removed = ?
        ", [$stageId, 0, 1, 0]);

        $total = $totalResult->total ?? 0;

        // Fetch paginated applicants with pipeline, notes, and scores
        $rows = DB::select("
            SELECT
                a.id AS applicant_id,
                a.full_name,
                a.position_desired AS position,
                a.profile_picture AS avatar,
                p.id AS pipeline_id,
                p.note AS pipeline_note,
                p.schedule_date,

                -- Notes as JSON array
                IFNULL(
                    CONCAT('[', GROUP_CONCAT(JSON_QUOTE(n.note) ORDER BY n.id SEPARATOR ','), ']'),
                    '[]'
                ) AS notes,

                -- Scores as JSON array
                IFNULL(
                    CONCAT('[', GROUP_CONCAT(
                        JSON_OBJECT(
                            'text', CONCAT(UPPER(LEFT(s.type,1)), SUBSTRING(s.type,2), ':'),
                            'value', IFNULL(s.raw_score,0)
                        )
                    SEPARATOR ','), ']'),
                    '[]'
                ) AS progress,

                -- Pipeline note as tag
                IF(p.note IS NOT NULL, CONCAT('[', JSON_OBJECT('text', p.note, 'variant', 'yellow'), ']'), '[]') AS tags

            FROM applicants a
            JOIN applicant_pipeline p
                ON p.applicant_id = a.id
                AND p.current_stage_id = ?
                AND p.removed = ?
            LEFT JOIN recruitment_notes n
                ON n.applicant_id = a.id
                AND n.removed = ?
            LEFT JOIN applicant_pipeline_score s
                ON s.applicant_pipeline_id = p.id
                AND s.type = ?
                AND s.removed = ?
            WHERE a.in_active = ?
            AND a.removed = ?
            GROUP BY a.id, a.full_name, a.position_desired, a.profile_picture, p.id, p.note, p.schedule_date
            ORDER BY {$orderBySQL}
            LIMIT ? OFFSET ?
        ", [$stageId, 0, 0, $scoreType, 0, 1, 0, $perPage, $offset]);

        // Convert JSON strings into PHP arrays
        $cards = array_map(function ($row) {
            return [
                'id' => $row->applicant_id,
                'name' => $row->full_name,
                'position' => $row->position,
                'avatar' => $row->avatar,
                'notes' => json_decode($row->notes, true),
                'progress' => json_decode($row->progress, true),
                'tags' => json_decode($row->tags, true),
                'date' => $row->schedule_date,
            ];
        }, $rows);

        $hasMore = ($page * $perPage) < $total;

        return response()->json([
            'totalCard' => $total,
            'cards' => $cards,
            'hasMore' => $hasMore,
            'page' => $page,
        ]);
    }


    public function getBoard(Request $request)
    {
        $perPage = 5; // cards per column
        $cacheDuration = 300; // 5 minutes

        $request->validate([
            'refresh' => 'nullable|boolean'
        ]);

        $cacheVersion = Cache::get('candidates_cache_version', 1);

        // Build cache key based on version and request parameters
        $cacheKey = 'board_data_v' . $cacheVersion;

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        $board = Cache::remember($cacheKey, $cacheDuration, function () use ($perPage) {
            $boardResult = [];

            foreach ($this->stageIdMapping as $stageName => $stageId) {
                $orderBySQL = $this->getStageOrderSQL($stageName, 'ap');
                // Count total applicants for this stage
                $totalResult = DB::selectOne("
                    SELECT COUNT(*) as total
                    FROM applicants c
                    INNER JOIN applicant_pipeline ap 
                        ON c.id = ap.applicant_id
                    WHERE c.in_active = ?
                    AND c.removed = ?
                    AND ap.current_stage_id = ?
                    AND ap.removed = ?
                ", [1, 0, $stageId, 0]);

                $total = $totalResult ? (int)$totalResult->total : 0;

                // Fetch applicants for this stage
                $rows = DB::select("
                    SELECT 
                        c.id,
                        c.full_name,
                        c.position_desired as position,
                        c.profile_picture as avatar,
                        ap.id as pipeline_id,
                        ap.note as pipeline_note,
                        ap.schedule_date
                    FROM applicants c
                    INNER JOIN applicant_pipeline ap 
                        ON c.id = ap.applicant_id
                    WHERE c.in_active = ? 
                    AND c.removed = ?
                    AND ap.current_stage_id = ? 
                    AND ap.removed = ?
                    ORDER BY {$orderBySQL}
                    LIMIT ?
                ", [1, 0, $stageId, 0, $perPage]);

                if (empty($rows)) {
                    $boardResult[$stageName] = [
                        'totalCard' => $total,
                        'cards' => []
                    ];
                    continue;
                }

                $applicantIds = [];
                $pipelineIds = [];
                foreach ($rows as $row) {
                    $applicantIds[] = $row->id;
                    $pipelineIds[] = $row->pipeline_id;
                }

                // Fetch scores
                $scoresByPipeline = [];

                if (!empty($pipelineIds)) {
                    $placeholders = implode(',', array_fill(0, count($pipelineIds), '?'));
                    $bindings = array_merge($pipelineIds, [0, $this->scoreTypes[$stageName]]);
                    
                    $scoreRows = DB::select("
                        SELECT 
                            applicant_pipeline_id,
                            type,
                            raw_score,
                            overall_score,
                            (raw_score / overall_score) * 100 AS percentage
                        FROM applicant_pipeline_score
                        WHERE applicant_pipeline_id IN ($placeholders)
                        AND removed = ?
                        AND type = ?
                    ", $bindings);

                    // ✅ Loop through rows directly
                    foreach ($scoreRows as $s) {
                        // Clamp percentage between 0 and 100
                        $percentage = max(0, min(100, $s->percentage ?? 0));

                        $scoresByPipeline[$s->applicant_pipeline_id][] = [
                            'text'  => ucfirst(str_replace('_', ' ', $this->scoreTypes[$stageName])) . ':',
                            'value' => $percentage
                        ];
                    }
                }
                // Fetch notes
                $notesByApplicant = [];
                if (!empty($applicantIds)) {
                    $placeholders = implode(',', array_fill(0, count($applicantIds), '?'));
                    $bindingsArray = array_merge($applicantIds, [0]);
                    $noteRows = DB::select("
                        SELECT applicant_id, note
                        FROM recruitment_notes
                        WHERE applicant_id IN ($placeholders)
                        AND removed = ?
                    ", $bindingsArray);

                    foreach ($noteRows as $n) {
                        $notesByApplicant[$n->applicant_id][] = $n->note;
                    }
                }

                // Build cards
                $cards = [];

                foreach ($rows as $row) {
                    $tags = [];
                    if ($row->pipeline_note) {
                        $status = strtolower($row->pipeline_note);
                        $variant = $this->statusVariants[$status] ?? 'gray';
                        $tags[] = [
                            'text' => $status,
                            'variant' => $variant,
                        ];
                    }

                    $cards[] = [
                        'id' => $row->id,
                        'name' => $row->full_name ?? '',
                        'position' => $row->position ?? '',
                        'avatar' => $row->avatar ?? '',
                        'notes' => $notesByApplicant[$row->id] ?? [],
                        'progress' => $scoresByPipeline[$row->pipeline_id] ?? [],
                        'tags' => $tags,
                        'date' => $row->schedule_date ?? '',
                    ];
                }

                $boardResult[$stageName] = [
                    'totalCard' => $total,
                    'cards' => $cards
                ];
            }

            return $boardResult;
        });

        return response()->json($board);
    }

    public function getApplicantDetails($applicantId)
    {
        $row = DB::selectOne("
            SELECT 
                -- Files
                IFNULL(
                    CONCAT('[', GROUP_CONCAT(
                        DISTINCT JSON_OBJECT(
                            'file_name', IFNULL(af.file_name, ''),
                            'file_path', IFNULL(af.file_path, '')
                        )
                    SEPARATOR ','), ']'),
                    '[]'
                ) AS files,

                -- Assessments + results
                IFNULL(
                    CONCAT('[', GROUP_CONCAT(
                        DISTINCT JSON_OBJECT(
                            'title', IFNULL(ass.title, ''),
                            'score', IFNULL(ar.score, 0),
                            'over_all_score', IFNULL(aq_counts.total_questions, 0)
                        )
                    SEPARATOR ','), ']'),
                    '[]'
                ) AS assessments,

                -- Schedules + participants + stage
                IFNULL(
                    CONCAT('[', GROUP_CONCAT(
                        DISTINCT JSON_OBJECT(
                            'stage', IFNULL(rs.stage_name, ''),
                            'date', ap.schedule_date,
                            'platforms', IFNULL(ap.platforms, ''),
                            'participants', IFNULL(pt.participants_list, '[]')
                        )
                    SEPARATOR ','), ']'),
                    '[]'
                ) AS schedules

            FROM applicants a
            LEFT JOIN applicant_files af
                ON af.applicant_id = a.id AND af.removed = 0
            LEFT JOIN assessment_results ar
                ON ar.applicant_id = a.id AND ar.removed = 0
            LEFT JOIN assessments ass
                ON ass.id = ar.assessment_id AND ass.removed = 0
            LEFT JOIN (
                SELECT assessment_id, COUNT(*) AS total_questions
                FROM assessment_questions
                WHERE removed = 0
                GROUP BY assessment_id
            ) aq_counts ON aq_counts.assessment_id = ass.id
            LEFT JOIN applicant_pipeline ap
                ON ap.applicant_id = a.id AND ap.removed = 0
            LEFT JOIN recruitment_stages rs
                ON rs.id = ap.current_stage_id
            LEFT JOIN (
                SELECT applicant_pipeline_id, CONCAT('[', GROUP_CONCAT(JSON_QUOTE(name)), ']') AS participants_list
                FROM participants
                WHERE removed = 0
                GROUP BY applicant_pipeline_id
            ) pt ON pt.applicant_pipeline_id = ap.id
            WHERE a.id = ?
            GROUP BY a.id
        ", [$applicantId]);

        return response()->json([
            'files' => json_decode($row->files, true),
            'assessments' => json_decode($row->assessments, true),
            'schedules' => json_decode($row->schedules, true),
        ]);
    }
}
