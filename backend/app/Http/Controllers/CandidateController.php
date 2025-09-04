<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;


use App\Models\Candidate;
use App\Models\User;
use App\Models\ApplicantAssessment;
use App\Models\ApplicantPipeline;
use App\Mail\CandidateAccountMail;

//Jobs
use App\Jobs\ProcessAssessmentsJob;



class CandidateController extends Controller
{
    /**
     * List candidates with optional search, filters, and pagination
     */

    public function index(Request $request)
    {
        $cacheDuration = 300; // 5 minutes

        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'refresh' => 'nullable|boolean'
        ]);

        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $cacheVersion = Cache::get('candidates_cache_version', 1);

        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'role' => $request->input('role'),
            'appliedDate' => $request->input('appliedDate'),
            'version' => $cacheVersion
        ];

        $cacheKey = 'candidates_list_' . md5(json_encode($filters));

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, $cacheDuration, function () use ($filters) {

            $where = [];
            $bindings = [];

            if ($filters['search']) {
                $where[] = "(c.full_name LIKE ? OR c.position_desired LIKE ?)";
                $bindings[] = "%{$filters['search']}%";
                $bindings[] = "%{$filters['search']}%";
            }

            if (!is_null($filters['status'])) {
                $where[] = "COALESCE(p.note,'inactive') = ?";
                $bindings[] = $filters['status'];
            }

            if ($filters['role']) {
                $where[] = "c.position_desired = ?";
                $bindings[] = $filters['role'];
            }

            if ($filters['appliedDate']) {
                $where[] = "DATE(c.created_at) = ?";
                $bindings[] = $filters['appliedDate'];
            }

            $whereSql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $sql = "
                SELECT 
                    c.id,
                    c.full_name AS name,
                    c.position_desired AS role,
                    DATE(c.created_at) AS appliedDate,
                    COALESCE(MAX(af.file_path),'no submitted file') AS attachment,
                    COALESCE(MAX(p.note),'inactive') AS status,
                    c.email,
                    MAX(p.schedule_date) AS assessmentDate,
                    COALESCE(MAX(rs.stage_name),'N/A') AS applicationStage,
                    GROUP_CONCAT(a.id) AS assessment_ids,
                    GROUP_CONCAT(a.title) AS assessment_titles,
                    c.created_at
                FROM applicants c
                LEFT JOIN applicant_pipeline p ON p.applicant_id = c.id
                LEFT JOIN recruitment_stages rs ON rs.id = p.current_stage_id
                LEFT JOIN applicant_files af ON af.applicant_id = c.id
                LEFT JOIN applicant_assessments aa ON aa.applicant_id = c.id
                LEFT JOIN assessments a ON a.id = aa.assessment_id
                {$whereSql}
                GROUP BY c.id, c.full_name, c.position_desired, c.email, c.created_at
                ORDER BY c.created_at DESC
            ";

            $results = DB::select($sql, $bindings);

            // Transform GROUP_CONCAT results into arrays
            $candidates = array_map(function ($row) {
                $assessmentData = [];
                if (!empty($row->assessment_ids)) {
                    $ids = explode(',', $row->assessment_ids);
                    $titles = explode(',', $row->assessment_titles);
                    foreach ($ids as $index => $id) {
                        $assessmentData[] = [
                            'id' => $id,
                            'title' => $titles[$index] ?? null,
                        ];
                    }
                }

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'role' => $row->role ?? 'Open Position',
                    'appliedDate' => $row->appliedDate,
                    'attachment' => $row->attachment,
                    'status' => $row->status,
                    'email' => $row->email,
                    'assessmentDate' => $row->assessmentDate,
                    'applicationStage' => $row->applicationStage,
                    'assessmentData' => $assessmentData,
                ];
            }, $results);

            return $candidates;
        });

        // Paginate after caching
        $total = count($data);
        $paginated = new LengthAwarePaginator(
            array_slice($data, ($page - 1) * $perPage, $perPage),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage()
            ]
        ]);
    }




    /**
     * Store a new candidate
     */
    public function createCandidate(Request $request)
    {
        $creator = auth()->id(); 
        $password = $request->generatedPassword;

        // Optional: reduce bcrypt cost to speed up HTTP response
        $hashed = bcrypt($password, ['rounds' => 10]); // default 12 → ~200ms; 10 → ~50-100ms

        $loginUrl = 'http://172.16.98.68:5173/'; //change this to domain name
        
        
        $exists = DB::table('users')->where('user_email', $request->email)->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Record already exists with this email.'
            ], 409); // 409 Conflict
        }



        // -----------------------------
        // 1️⃣ Only essential DB inserts in transaction
        // -----------------------------
        $candidateId = DB::transaction(function () use ($request, $creator, $hashed) {
            // Insert user
            $userId = DB::table('users')->insertGetId([
                'role_id'       => 4,
                'user_email'    => $request->email,
                'password_hash' => $hashed,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // Insert applicant
            $candidateId = DB::table('applicants')->insertGetId([
                'user_id'    => $userId,
                'full_name'  => $request->fullName,
                'email'      => $request->email,
                'position_desired' => $request->role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Insert pipeline
            $pipelineId = DB::table('applicant_pipeline')->insertGetId([
                'applicant_id'       => $candidateId,
                'current_stage_id'   => 1,
                'updated_by_user_id' => $creator,
                'note'               => 'pending',
                'schedule_date'      => $request->assessmentDate,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            DB::insert("
                INSERT INTO applicant_pipeline_score 
                    (applicant_pipeline_id, raw_score, overall_score, type, removed, created_at, updated_at) 
                VALUES (?, 0, 0, 'exam_score', 0, NOW(), NOW())
            ", [$pipelineId]);

            return $candidateId;
        });

        // -----------------------------
        // 3️⃣ Queue assessment assignments asynchronously
        //    - Use a job with bulk insert or upsert to avoid duplicates
        // -----------------------------
        if (!empty($request->assessments)) {
            ProcessAssessmentsJob::dispatch($candidateId, $request->assessments, $creator);
        }

                // -----------------------------
        // 2️⃣ Queue email to Redis (async)
        // -----------------------------
        Mail::to($request->email)->queue(
            new CandidateAccountMail(
                $request->fullName,
                $request->email,
                $password,
                $loginUrl
            )
        );


        // -----------------------------
        // 4️⃣ Return response immediately
        // -----------------------------
        Cache::increment('candidates_cache_version');

        return response()->json([
            'success'      => true,
            'message'      => 'Candidate registered successfully'
        ]);
    }


    /**
     * Show a single candidate
     */
    public function show($id)
    {
        $candidate = Candidate::findOrFail($id);
        return response()->json($candidate);
    }

    /**
     * Update candidate
     */
    public function updateCandidate(Request $request, $id)
    {
        $creator = auth()->id();

        // Validate input
        $request->validate([
            'fullName'       => 'sometimes|required|string|max:255',
            'role'           => 'sometimes|required|string|max:255',
            'assessmentDate' => 'sometimes|required|date',
            'assessments'    => 'sometimes|array',
            'email'          => 'sometimes|required|email|max:255'
        ]);

        // Check if updating email to an existing one
        if ($request->email) {
            $exists = DB::table('users')
                ->where('user_email', $request->email)
                ->where('id', '!=', DB::table('applicants')->where('id', $id)->value('user_id'))
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Record already exists with this email.'
                ], 409);
            }
        }

        DB::transaction(function () use ($request, $id, $creator) {
            // 1️⃣ Update user email if provided
            if ($request->email) {
                DB::table('users')
                    ->where('id', DB::table('applicants')->where('id', $id)->value('user_id'))
                    ->update([
                        'user_email' => $request->email,
                        'updated_at' => now()
                    ]);
            }

            // 2️⃣ Update applicant
            $applicantData = [];
            if ($request->fullName) $applicantData['full_name'] = $request->fullName;
            if ($request->role) $applicantData['position_desired'] = $request->role;
            if ($request->email) $applicantData['email'] = $request->email;
            if (!empty($applicantData)) $applicantData['updated_at'] = now();

            if ($applicantData) {
                DB::table('applicants')->where('id', $id)->update($applicantData);
            }

            // 3️⃣ Update pipeline
            if ($request->assessmentDate) {
                DB::table('applicant_pipeline')
                    ->where('applicant_id', $id)
                    ->update([
                        'schedule_date' => $request->assessmentDate,
                        'updated_at' => now(),
                        'updated_by_user_id' => $creator
                    ]);
            }

            // 4️⃣ Update assessments
            if (!empty($request->assessments)) {
                // Delete old assessments
                DB::table('applicant_assessments')->where('applicant_id', $id)->delete();

                // Insert new ones
                $insertData = [];
                foreach ($request->assessments as $assessmentId) {
                    $insertData[] = [
                        'applicant_id' => $id,
                        'assessment_id' => $assessmentId,
                        'assigned_by' => $creator,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if ($insertData) DB::table('applicant_assessments')->insert($insertData);
            }

            // 5️⃣ Increment cache version
            Cache::increment('candidates_cache_version');
        });

        return response()->json([
            'success' => true,
            'message' => 'Candidate updated successfully'
        ]);
    }


    /**
     * Delete candidate
     */
    public function destroy($id)
    {
        $candidate = Candidate::findOrFail($id);
        $candidate->delete();

        return response()->json([
            'message' => 'Candidate deleted successfully'
        ]);
    }
}
