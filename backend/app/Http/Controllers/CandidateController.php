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

        // Cache versioning: can be incremented whenever a candidate is updated/added
        $cacheVersion = Cache::get('candidates_cache_version', 1);

        // Generate a cache key for this combination of filters + version
        $cacheKey = 'candidates_list_' . md5(
            json_encode([
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'role' => $request->input('role'),
                'appliedDate' => $request->input('appliedDate'),
                'version' => $cacheVersion
            ])
        );

        // Optional: force refresh
        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        // Cache raw collection
        $candidatesData = Cache::remember($cacheKey, $cacheDuration, function () use ($request) {
            $query = Candidate::with('applicantFiles', 'pipeline');

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('position_desired', 'like', "%{$search}%");
                });
            }

            if (!is_null($status = $request->input('status'))) {
                $query->where('in_active', $status);
            }

            if ($role = $request->input('role')) {
                $query->where('job_info_id', $role);
            }

            if ($appliedDate = $request->input('appliedDate')) {
                $query->whereDate('created_at', $appliedDate);
            }

            return $query->orderBy('created_at', 'desc')->get();
        });

        // Paginate after caching
        $paginated = new LengthAwarePaginator(
            $candidatesData->forPage($page, $perPage),
            $candidatesData->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Transform data for response
        $data = $paginated->map(function ($candidate) {
            return [
                'id' => $candidate->id,
                'name' => $candidate->full_name,
                'role' => $candidate->position_desired ?? 'Open Position',
                'appliedDate' => $candidate->created_at->format('Y-m-d'),
                'attachment' => $candidate->applicantFiles->pluck('file_path')->first() ?? 'no submitted file',
                'status' => $candidate->pipeline->pluck('note')->first() ?? 'inactive',
                'email' => $candidate->email,
                'assessmentDate' => $candidate->pipeline->pluck('schedule_date')->first(),
                'applicationStage' => $candidate->pipeline->currentStage->pluck('stage_name')->first(),
                'assessmentData' => $candidate->assessments->pluck('id')->toArray()
            ];
        });

        return response()->json([
            'data' => $data,
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
        $password = $request->generatedPassword; // Generate password

        // Optional: reduce bcrypt cost to speed up HTTP response
        $hashed = bcrypt($password, ['rounds' => 10]); // default 12 → ~200ms; 10 → ~50-100ms

        $loginUrl = 'http://172.16.98.68:5173/';
        
        
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
            DB::table('applicant_pipeline')->insert([
                'applicant_id'       => $candidateId,
                'current_stage_id'   => 1,
                'updated_by_user_id' => $creator,
                'note'              => 'pending',
                'schedule_date'      => $request->assessmentDate,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            return $candidateId;
        });

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
        // 3️⃣ Queue assessment assignments asynchronously
        //    - Use a job with bulk insert or upsert to avoid duplicates
        // -----------------------------
        if (!empty($request->assessments)) {
            ProcessAssessmentsJob::dispatch($candidateId, $request->assessments, $creator);
        }

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
    public function update(Request $request, $id)
    {
        $candidate = Candidate::findOrFail($id);

        $request->validate([
            'name'            => 'sometimes|required|string|max:255',
            'role'            => 'sometimes|required|string|max:255',
            'employment_type' => 'sometimes|required|string|max:50',
            'applied_date'    => 'sometimes|required|date',
            'attachment'      => 'nullable|file|max:2048',
            'status'          => 'sometimes|required|string|max:50',
        ]);

        $data = $request->only([
            'name', 'role', 'employment_type', 'applied_date', 'status'
        ]);

        // Handle file upload
        if ($request->hasFile('attachment')) {
            $data['attachment'] = $request->file('attachment')->store('candidate_files', 'public');
        }

        $candidate->update($data);
        Cache::increment('candidates_cache_version');
        return response()->json([
            'message'   => 'Candidate updated successfully',
            'candidate' => $candidate
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
