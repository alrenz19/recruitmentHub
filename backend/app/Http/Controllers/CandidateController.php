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
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'refresh' => 'nullable|string'
        ]);

        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        // Generate cache key with pagination
        $cacheKey = 'candidates_page_' . $page . '_' . $perPage . '_' . md5(serialize($request->all()));
        $cacheDuration = 300;

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        // Cache the paginated results, not the entire dataset
        $result = Cache::remember($cacheKey, $cacheDuration, function () use ($request, $perPage) {
            
            $query = DB::table('applicants as c')
                ->select(
                    'c.id',
                    'c.full_name as name',
                    'c.position_desired as role',
                    DB::raw('DATE(c.created_at) as appliedDate'),
                    DB::raw("COALESCE(af.file_path, 'no submitted file') as attachment"),
                    DB::raw("COALESCE(p.note, 'inactive') as status"),
                    'c.email',
                    'p.schedule_date as assessmentDate',
                    DB::raw("COALESCE(rs.stage_name, 'N/A') as applicationStage")
                )
                ->leftJoin('applicant_pipeline as p', 'p.applicant_id', '=', 'c.id')
                ->leftJoin('recruitment_stages as rs', 'rs.id', '=', 'p.current_stage_id')
                ->leftJoin('applicant_files as af', function($join) {
                    $join->on('af.applicant_id', '=', 'c.id')
                        ->whereRaw('af.id = (SELECT MAX(id) FROM applicant_files WHERE applicant_id = c.id)');
                });

            // Apply filters
            if ($request->filled('search')) {
                $query->where(function($q) use ($request) {
                    $q->where('c.full_name', 'LIKE', "%{$request->search}%")
                    ->orWhere('c.position_desired', 'LIKE', "%{$request->search}%");
                });
            }

            if ($request->filled('status')) {
                $query->where('p.note', $request->status);
            }

            if ($request->filled('role')) {
                $query->where('c.position_desired', $request->role);
            }

            if ($request->filled('appliedDate')) {
                $query->whereDate('c.created_at', $request->appliedDate);
            }

            // Get paginated results
            $paginator = $query->orderBy('c.created_at', 'desc')
                            ->paginate($perPage);

            // Get assessment data separately (more efficient)
            $candidateIds = $paginator->getCollection()->pluck('id')->toArray();
            
            if (!empty($candidateIds)) {
                $assessments = DB::table('applicant_assessments as aa')
                    ->select('aa.applicant_id', 'a.id as assessment_id', 'a.title as assessment_title')
                    ->join('assessments as a', 'a.id', '=', 'aa.assessment_id')
                    ->whereIn('aa.applicant_id', $candidateIds)
                    ->get()
                    ->groupBy('applicant_id');

                // Add assessments to candidates
                $paginator->getCollection()->transform(function ($candidate) use ($assessments) {
                    $candidate->assessmentData = $assessments->get($candidate->id, []);
                    return $candidate;
                });
            }

            return [
                'data' => $paginator->items(),
                'meta' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage()
                ]
            ];
        });

        return response()->json($result);
    }



    public function createCandidate(Request $request)
    {
        $creator  = auth()->id();
        $password = $request->generatedPassword;

        // Faster bcrypt (rounds = 10 is secure enough and faster than default 12)
        $hashed = bcrypt($password, ['rounds' => 10]);

        $host     = request()->getHost();
        $scheme   = request()->getScheme();
        $loginUrl = $scheme . '://' . $host . ':5173';

        // -----------------------------
        // 1️⃣ Check duplicate email (safe raw SQL)
        // -----------------------------
        $exists = DB::selectOne(
            "SELECT 1 FROM users WHERE user_email = ? LIMIT 1",
            [$request->email]
        );
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Record already exists with this email.'
            ], 409);
        }

        // -----------------------------
        // 2️⃣ Transaction with raw SQL only
        // -----------------------------
        $candidateId = DB::transaction(function () use ($request, $creator, $hashed) {
            $pdo = DB::getPdo();

            // Insert user
            $stmt = $pdo->prepare("
                INSERT INTO users (role_id, user_email, password_hash, created_at, updated_at)
                VALUES (4, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$request->email, $hashed]);
            $userId = $pdo->lastInsertId();

            // Insert applicant
            $stmt = $pdo->prepare("
                INSERT INTO applicants (user_id, full_name, email, position_desired, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$userId, $request->fullName, $request->email, $request->role]);
            $candidateId = $pdo->lastInsertId();

            // Insert pipeline
            $stmt = $pdo->prepare("
                INSERT INTO applicant_pipeline (applicant_id, current_stage_id, updated_by_user_id, note, schedule_date, created_at, updated_at)
                VALUES (?, 1, ?, 'pending', ?, NOW(), NOW())
            ");
            $stmt->execute([$candidateId, $creator, $request->assessmentDate]);
            $pipelineId = $pdo->lastInsertId();

            // Insert initial score
            $stmt = $pdo->prepare("
                INSERT INTO applicant_pipeline_score (applicant_pipeline_id, raw_score, overall_score, type, removed, created_at, updated_at)
                VALUES (?, 0, 0, 'exam_score', 0, NOW(), NOW())
            ");
            $stmt->execute([$pipelineId]);

            return $candidateId;
        });

        // -----------------------------
        // 3️⃣ Queue assessments (async, handled with raw SQL in job)
        // -----------------------------
        if (!empty($request->assessments)) {
            ProcessAssessmentsJob::dispatch($candidateId, $request->assessments, $creator);
        }

        // -----------------------------
        // 4️⃣ Queue email (async, queued Mailable)
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
        // 5️⃣ Invalidate cache
        // -----------------------------
        Cache::increment('candidates_cache_version');
        $cacheVersion = Cache::get('candidates_cache_version', 2);
        $cacheKey = 'candidates_list_v' . $cacheVersion;
        Cache::forget($cacheKey);

        return response()->json([
            'success' => true,
            'message' => 'Candidate registered successfully'
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
            $cacheVersion = Cache::get('candidates_cache_version', 2);
            // Build cache key based on version and request parameters
            $cacheKey = 'candidates_list_v' . $cacheVersion;
            Cache::forget($cacheKey);
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
