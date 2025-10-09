<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Candidate;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\NotificationService;

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
                'avatar' => url('storage/' . $row->avatar),
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
            'refresh'          => 'nullable|boolean',
            'position'         => 'nullable|string',
            'date_assessment'  => 'nullable|date',
            'status'           => 'nullable|string',
            'search'           => 'nullable|string',
        ]);

        $cacheVersion = Cache::get('candidates_cache_version', 1);

        // Build cache key based on version + filters
        $cacheKey = 'board_data_v' . $cacheVersion . '_' . md5(json_encode($request->all()));

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        $board = Cache::remember($cacheKey, $cacheDuration, function () use ($perPage, $request) {

            $boardResult = [];

            foreach ($this->stageIdMapping as $stageName => $stageId) {
                // --- Build dynamic WHERE filters ---
                $bindings = [1, 0, $stageId, 0]; // common conditions
                $filterSql = '';

                if ($request->filled('position')) {
                    $filterSql .= " AND c.position_desired LIKE ? ";
                    $bindings[] = '%' . $request->position . '%';
                }

                if ($request->filled('date_assessment')) {
                    $filterSql .= " AND DATE(ap.schedule_date) = ? ";
                    $bindings[] = $request->date_assessment;
                }

                if ($request->filled('status')) {
                    $filterSql .= " AND LOWER(ap.note) = ? ";
                    $bindings[] = strtolower($request->status);
                }

                if ($request->filled('search')) {
                    $filterSql .= " AND (c.full_name LIKE ? OR c.present_address LIKE ?) ";
                    $bindings[] = '%' . $request->search . '%';
                    $bindings[] = '%' . $request->search . '%';
                }

                // --- Total count for the stage ---
                $totalResult = DB::selectOne("
                    SELECT COUNT(*) as total
                    FROM applicants c
                    INNER JOIN applicant_pipeline ap 
                        ON c.id = ap.applicant_id
                    WHERE c.in_active = ? 
                    AND c.removed = ? 
                    AND ap.current_stage_id = ? 
                    AND ap.removed = ?
                    $filterSql
                ", $bindings);

                $total = $totalResult ? (int) $totalResult->total : 0;

                // --- Main query for applicants ---
                // Order rules:
                // 1) passed first
                // 2) then pending
                // 3) then rest, all alphabetical by position
                $orderBySQL = "
                    CASE 
                        WHEN LOWER(ap.note) = 'passed' THEN 1
                        WHEN LOWER(ap.note) = 'pending' THEN 2
                        ELSE 3
                    END ASC,
                    c.position_desired ASC
                ";

                $rows = DB::select("
                    SELECT 
                        c.id,
                        c.full_name,
                        c.position_desired AS position,
                        c.profile_picture AS avatar,
                        c.present_address AS address,
                        c.desired_salary AS salary,
                        ap.id AS pipeline_id,
                        ap.note AS pipeline_note,
                        ap.schedule_date
                    FROM applicants c
                    INNER JOIN applicant_pipeline ap 
                        ON c.id = ap.applicant_id
                    WHERE c.in_active = ?
                    AND c.removed = ?
                    AND ap.current_stage_id = ?
                    AND ap.removed = ?
                    $filterSql
                    ORDER BY $orderBySQL
                    LIMIT ?
                ", array_merge($bindings, [$perPage]));

                if (empty($rows)) {
                    $boardResult[$stageName] = [
                        'totalCard' => $total,
                        'cards' => []
                    ];
                    continue;
                }

                // --- Collect IDs for related queries ---
                $applicantIds = array_column($rows, 'id');
                $pipelineIds  = array_column($rows, 'pipeline_id');

                // ===============================
                // Fetch Scores
                // ===============================
                $finalScores = [];
                if (!empty($pipelineIds)) {
                    $placeholders = implode(',', array_fill(0, count($pipelineIds), '?'));
                    $bindings = array_merge($pipelineIds, [0, $this->scoreTypes[$stageName]]);

                    $scoreRows = DB::select("
                        SELECT 
                            applicant_pipeline_id,
                            raw_score,
                            overall_score,
                            CASE WHEN overall_score > 0 
                                THEN (raw_score / overall_score) * 100 
                                ELSE 0 
                            END AS percentage
                        FROM applicant_pipeline_score
                        WHERE applicant_pipeline_id IN ($placeholders)
                        AND removed = ?
                        AND type = ?
                    ", $bindings);

                    $scoresByPipeline = [];
                    $overallScoresByPipeline = [];

                    foreach ($scoreRows as $s) {
                        $scoresByPipeline[$s->applicant_pipeline_id][] = $s->raw_score;
                        // track overall_score (they’re usually same for each interviewer, but we’ll take max)
                        $overallScoresByPipeline[$s->applicant_pipeline_id] = $s->overall_score;
                    }

                    foreach ($scoresByPipeline as $pipelineId => $rawScores) {
                        $numInterviewers = count($rawScores);
                        $finalScore = 0;

                        if ($numInterviewers > 0) {
                            $weight = 100 / $numInterviewers;
                            foreach ($rawScores as $score) {
                                $finalScore += ($score * $weight / 100);
                            }
                        }

                        $finalScores[$pipelineId][] = [
                            'text'           => ucfirst(str_replace('_', ' ', $this->scoreTypes[$stageName])) . ':',
                            'value'          => round($finalScore, 2),                               // weighted final score
                            'overall_score'  => round($overallScoresByPipeline[$pipelineId], 2) ?? 0,         // add overall_score
                        ];
                    }
                }

                // ===============================
                // Fetch Notes
                // ===============================
                $notesByApplicant = [];
                if (!empty($applicantIds)) {
                    $placeholders = implode(',', array_fill(0, count($applicantIds), '?'));
                    $bindings = array_merge($applicantIds, [0]);

                    $noteRows = DB::select("
                        SELECT applicant_id, note
                        FROM recruitment_notes
                        WHERE applicant_id IN ($placeholders)
                        AND removed = ?
                    ", $bindings);

                    foreach ($noteRows as $n) {
                        $notesByApplicant[$n->applicant_id][] = $n->note;
                    }
                }

                // ===============================
                // Build Cards
                // ===============================
                $cards = [];
                foreach ($rows as $row) {
                    $tags = [];
                    if ($row->pipeline_note) {
                        $status = strtolower($row->pipeline_note);
                        $variant = $this->statusVariants[$status] ?? 'gray';
                        $tags[] = [
                            'text'    => $status,
                            'variant' => $variant,
                        ];
                    }

                    $cards[] = [
                        'id'          => $row->id,
                        'pipeline_id' => $row->pipeline_id,
                        'name'        => $row->full_name ?? '',
                        'position'    => $row->position ?? '',
                        'avatar'      => (!empty($row->avatar) && $row->avatar !== '/') ? url('storage/' . $row->avatar) : '',
                        'address'     => $row->address ?? '',
                        'salary'      => $row->salary ?? '',
                        'notes'       => $notesByApplicant[$row->id] ?? [],
                        'progress'    => $finalScores[$row->pipeline_id] ?? [],
                        'tags'        => $tags,
                        'date'        => $row->schedule_date ?? '',
                    ];
                }

                $boardResult[$stageName] = [
                    'totalCard' => $total,
                    'cards'     => $cards
                ];
            }

            return $boardResult;
        });

        return response()->json($board);
    }


       public function getApplicantDetails($applicantId)
    {
        $creatorUserId = auth()->id();

        $hrStaff = DB::table('hr_staff')
            ->where('user_id', $creatorUserId)
            ->first();

        $hrStaffId = $hrStaff->id ?? 1;

        $row = DB::selectOne("
            SELECT 
                IFNULL(
                    CONCAT('[', GROUP_CONCAT(
                        DISTINCT JSON_OBJECT(
                            'file_name', IFNULL(af.file_name, ''),
                            'file_path', IFNULL(af.file_path, '')
                        )
                    SEPARATOR ','), ']'),
                    '[]'
                ) AS files,
                IFNULL(
                    CONCAT('[', GROUP_CONCAT(
                        DISTINCT JSON_OBJECT(
                            'title', IFNULL(ass.title, ''),
                            'score', IFNULL(ar.score, 0),
                            'over_all_score', IFNULL(aq_counts.correct_answers, 0)
                        )
                    SEPARATOR ','), ']'),
                    '[]'
                ) AS assessments,
                IFNULL(
                    CONCAT('[', GROUP_CONCAT(
                        DISTINCT JSON_OBJECT(
                            'stage', IFNULL(rs.stage_name, ''),
                            'date', IFNULL(ap.schedule_date, ''),
                            'platforms', IFNULL(ap.platforms, ''),
                            'participants', IFNULL(pt.participants_list, '[]')
                        )
                    SEPARATOR ','), ']'),
                    '[]'
                ) AS schedules,
                IFNULL(
                    (SELECT 1 
                    FROM job_offers jo 
                    WHERE jo.applicant_id = a.id 
                    AND jo.removed = 0 
                    LIMIT 1),
                    0
                ) AS has_job_offer,
                IFNULL(
                    (
                        SELECT JSON_OBJECT(
                            'status', jo.status,
                            'accepted_at', jo.accepted_at,
                            'declined_at', jo.declined_at,
                            'declined_reason', jo.declined_reason
                        )
                        FROM job_offers jo
                        WHERE jo.applicant_id = a.id
                        AND jo.removed = 0
                        AND (
                            jo.status = 'approved_applicant'
                            OR jo.status = 'declined_applicant'
                        )
                        ORDER BY jo.id DESC
                        LIMIT 1
                    ),
                    JSON_OBJECT(
                        'status', '',
                        'accepted_at', NULL,
                        'declined_at', NULL,
                        'declined_reason', NULL
                    )
                ) AS job_offer_status

            FROM applicants a
            LEFT JOIN applicant_files af
                ON af.applicant_id = a.id AND af.removed = 0
            LEFT JOIN assessment_results ar
                ON ar.applicant_id = a.id AND ar.removed = 0
            LEFT JOIN assessments ass
                ON ass.id = ar.assessment_id AND ass.removed = 0
            LEFT JOIN (
                SELECT aq.assessment_id, COUNT(*) AS correct_answers
                FROM assessment_questions aq
                INNER JOIN assessment_options ao ON aq.id = ao.question_id
                WHERE aq.removed = 0 
                AND ao.removed = 0
                AND ao.is_correct = 1
                GROUP BY aq.assessment_id
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
            GROUP BY a.id, ap.id, rs.stage_name
        ", [$applicantId]);

        // Decode files JSON
        $files = json_decode($row->files, true);

        // Prepend full storage URL
        $files = array_map(function($f) {
            $f['file_path'] = (!empty($f['file_path']) && $f['file_path'] !== '/')
                ? url('/storage/' . ltrim($f['file_path'], '/'))
                : '';
            return $f;
        }, $files);

        return response()->json([
            'files' => $files,
            'assessments' => json_decode($row->assessments, true),
            'schedules' => json_decode($row->schedules, true),
            'has_job_offer' => (bool)$row->has_job_offer,
            'job_offer_status' => $row->job_offer_status,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $creator = auth()->id(); 

        $request->validate([
            'note' => 'nullable|string',
        ]);

        // 🔹 Run raw SQL update
        DB::update("
            UPDATE applicant_pipeline 
            SET updated_by_user_id = ?, note = ?
            WHERE applicant_id = ? AND removed = 0
        ", [$creator, $request->note, $id]);

        NotificationService::send($id, "Application Status Updated", 'congratulations! 🎉 You’re qualify to the next step of the application process.', 'general', '/dashboard');

        // 🚀 Invalidate board cache by bumping version
        Cache::increment('candidates_cache_version');
        $cacheVersion = Cache::get('candidates_cache_version', 1);
        // Build cache key based on version and request parameters
        $cacheKey = 'board_data_v' . $cacheVersion;
        Cache::forget($cacheKey);

        return response()->json([
            'message' => 'Pipeline updated successfully',
        ]);
    }

    public function getSignatures($id)
    {
        // Try to find job offer by id
        $offer = DB::table('job_offers')
            ->join('applicants', 'job_offers.applicant_id', '=', 'applicants.id')
            ->where('job_offers.id', $id)
            ->select('applicants.user_id as applicant_user_id')
            ->first();

        // If not found, assume the given ID is actually applicant_id
        if (!$offer) {
            $offer = DB::table('applicants')
                ->where('id', $id)
                ->select('user_id as applicant_user_id')
                ->first();
        }

        if (!$offer) {
            return response()->json(['error' => 'Applicant or job offer not found'], 404);
        }

        // Get applicant signature
        $applicantSig = DB::table('applicants')
            ->where('user_id', $offer->applicant_user_id)
            ->value('signature');

        // Get admin (CEO) signature
        $adminSig = DB::table('hr_staff')
            ->where('user_id', 58) // CEO user
            ->value('signature');

        return response()->json([
            'applicant_signature' => $applicantSig ? asset('storage/' . $applicantSig) : null,
            'admin_signature'     => $adminSig ? asset('storage/' . $adminSig) : null,
        ]);
    }


    public function getStatuses(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);
        $offset = ($page - 1) * $perPage;

        // Get total count
        $totalResult = DB::selectOne("
            SELECT COUNT(DISTINCT note) as total
            FROM applicant_pipeline
            WHERE removed = 0
        ");
        $total = $totalResult->total ?? 0;

        // Get paginated distinct statuses
        $statusRows = DB::select("
            SELECT DISTINCT note
            FROM applicant_pipeline 
            WHERE removed = 0
            ORDER BY note
            LIMIT ? OFFSET ?
        ", [$perPage, $offset]);

        // Extract just the note values
        $statuses = array_map(function($row) {
            return $row->note;
        }, $statusRows);

        return response()->json([
            'data' => $statuses,
            'hasMore' => ($page * $perPage) < $total,
        ]);
    }


    public function getRoles(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);
        $offset = ($page - 1) * $perPage;

        // Get total count
        $totalResult = DB::selectOne("SELECT COUNT(*) as total FROM positions");
        $total = $totalResult->total ?? 0;

        // Get paginated roles
        $roles = DB::select("
            SELECT id, title
            FROM positions
            ORDER BY title
            LIMIT ? OFFSET ?
        ", [$perPage, $offset]);

        return response()->json([
            'data' => $roles,
            'hasMore' => ($page * $perPage) < $total,
        ]);
    }


}
