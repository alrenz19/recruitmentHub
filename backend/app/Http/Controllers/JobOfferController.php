<?php

// app/Http/Controllers/JobOfferController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use App\Mail\JobOfferApprovalMail;

class JobOfferController extends Controller
{
    public function index(Request $request)
    {
        // Frontend payload mapping
        $status = $request->input('status');          // "all" | "pending_ceo" | etc.
        $role = $request->input('role', '');         // empty string = no filter
        $search = $request->input('search', '');
        $perPage = (int) $request->input('perPage', 10);
        $page = (int) $request->input('page', 1);
        $offset = ($page - 1) * $perPage;

        // Sort
        $sortOrder = strtolower($request->input('sort', 'Descending')) === 'ascending' ? 'ASC' : 'DESC';

        // Base SQL
        $sql = "
            SELECT 
                jo.id,
                a.full_name AS name,
                a.position_desired AS role,
                a.created_at AS applicationDate,
                jo.created_at AS offerDate,
                jo.status
            FROM job_offers jo
            JOIN applicants a ON a.id = jo.applicant_id
            WHERE jo.removed = 0
        ";

        $params = [];

        // Apply filters
        if ($status && $status !== 'all') {
            $sql .= " AND jo.status = ? ";
            $params[] = $status;
        }

        if ($role !== '') {
            $sql .= " AND a.position_desired = ? ";
            $params[] = $role;
        }

        if ($search) {
            $sql .= " AND (a.full_name LIKE ? OR jo.position LIKE ?) ";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        // Count total rows
        $countSql = "SELECT COUNT(*) as total FROM ($sql) AS sub";
        $total = DB::selectOne($countSql, $params)->total;

        // Pagination and sorting
        $sql .= " ORDER BY jo.created_at $sortOrder LIMIT ? OFFSET ? ";
        $params[] = $perPage;
        $params[] = $offset;

        $data = DB::select($sql, $params);

        return response()->json([
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => ceil($total / $perPage),
        ]);
    }

    public function show($applicantId)
    {
        $validator = Validator::make(['applicantId' => $applicantId], [
            'applicantId' => 'required|integer|exists:applicants,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid applicant ID',
                'errors' => $validator->errors()
            ], 422);
        }
        $sql = "
            SELECT 
                jo.id,
                jo.hr_id,
                jo.applicant_id,
                jo.position,
                jo.offer_details,
                jo.status,
                jo.approved_by_user_id,
                jo.approved_at,
                jo.declined_reason,
                jo.created_at
            FROM job_offers jo
            WHERE jo.removed = 0
              AND jo.applicant_id = ?
            LIMIT 1
        ";

        $offer = DB::selectOne($sql, [$applicantId]);

        if (!$offer) {
            return response()->json(['message' => 'Job offer not found'], 404);
        }

        return response()->json($offer);
    }

    public function chartData()
    {
        // Cache for 5 minutes
        $chartData = Cache::remember('job_offers_chart_monthly', 300, function () {
            $sql = "
                SELECT 
                    MONTH(jo.created_at) as month,
                    COUNT(*) as sent,
                    SUM(CASE WHEN jo.status = 'approved' THEN 1 ELSE 0 END) as accepted,
                    SUM(CASE WHEN jo.status = 'declined' THEN 1 ELSE 0 END) as declined
                FROM job_offers jo
                WHERE jo.removed = 0
                GROUP BY MONTH(jo.created_at)
                ORDER BY MONTH(jo.created_at)
            ";

            $rows = DB::select($sql);

            // Start with all 12 months = 0
            $months = [
                1=>"Jan",2=>"Feb",3=>"Mar",4=>"Apr",5=>"May",6=>"Jun",
                7=>"Jul",8=>"Aug",9=>"Sep",10=>"Oct",11=>"Nov",12=>"Dec"
            ];

            $data = [];
            foreach ($months as $num => $name) {
                $found = collect($rows)->firstWhere('month', $num);
                $data[] = [
                    'month'    => $name,
                    'sent'     => $found->sent     ?? 0,
                    'accepted' => $found->accepted ?? 0,
                    'declined' => $found->declined ?? 0,
                ];
            }

            return $data;
        });

        return response()->json($chartData);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'applicant_id' => 'required|integer',
            'draft' => 'required|array',
            'status' => 'required|in:pending_ceo,pending_applicant,approved_applicant,declined_applicant,pending_management,pending_fm,reject,approved',
            'signature' => 'nullable|string',
        ]);

        $host = request()->getHost();      // e.g. 172.16.98.32
        $scheme = request()->getScheme();  // http
        $loginUrl = $scheme . '://' . $host . ':5173';
        
        

        $creator = auth()->id();
        $now = now();

        // Check if applicant already has a job offer using parameter binding
        // $existingOffer = DB::table('job_offers')
        //     ->where('applicant_id', $validated['applicant_id'])
        //     ->first();

        // if ($existingOffer) {
        //     return response()->json([
        //         'message' => 'This applicant already has a job offer.',
        //         'offer_id' => $existingOffer->id,
        //     ], 409);
        // }

        // Get HR staff id with parameter binding
        $hrStaff = DB::table('hr_staff')->where('user_id', $creator)->first();
        $hrStaffId = $hrStaff->id ?? 1;

        // Get start date safely
        $startDate = $validated['draft']['startDate'] ?? $now->format('Y-m-d');

        // Convert draft to JSON safely
        $offerDetailsJson = json_encode($validated['draft']);

        // Handle signature with validation
        $signaturePath = null;
        if ($request->filled('signature')) {
            $signatureData = $validated['signature'];
            if (preg_match('/^data:image\/png;base64,/', $signatureData)) {
                $signatureData = substr($signatureData, strpos($signatureData, ',') + 1);
            }
            
            // Validate base64 data
            if (base64_decode($signatureData, true) === false) {
                return response()->json(['message' => 'Invalid signature data'], 422);
            }
            
            $signatureData = base64_decode($signatureData);
            $fileName = 'signatures/' . time() . '_' . $validated['applicant_id'] . '.png';
            Storage::disk('public')->put($fileName, $signatureData);
            $signaturePath = $fileName;
        }

        // Wrap in transaction
        DB::transaction(function () use ($hrStaffId, $validated, $offerDetailsJson, $now, $creator, $startDate, $signaturePath, $loginUrl) {
            
            // Insert job offer with parameter binding
            $jobOfferId = DB::table('job_offers')->insertGetId([
                'hr_id' => $hrStaffId,
                'applicant_id' => $validated['applicant_id'],
                'position' => $validated['draft']['position'] ?? '',
                'offer_details' => $offerDetailsJson,
                'status' => $validated['status'],
                'signature_path' => $signaturePath,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Get pipeline ID first to avoid N+1 query
            $pipeline = DB::table('applicant_pipeline')
                ->where('applicant_id', $validated['applicant_id'])
                ->first(['id']);

            if ($pipeline) {
                $pipelineId = $pipeline->id;

                // Update applicant pipeline with parameter binding
                DB::table('applicant_pipeline')
                    ->where('id', $pipelineId)
                    ->update([
                        'schedule_date' => $startDate,
                        'current_stage_id' => 4,
                        'note' => 'Management Review',
                        'updated_by_user_id' => $creator,
                        'updated_at' => $now,
                    ]);

                // Mark old scores as removed in single query
                DB::table('applicant_pipeline_score')
                    ->where('applicant_pipeline_id', $pipelineId)
                    ->update(['removed' => 1]);
            } else {
                // Handle case where pipeline doesn't exist (optional)
                return response()->json(['message' => 'No pipeline found for applicant'], 409);
            }

            $approvalLink = $loginUrl . "/job-offer-status/{$jobOfferId}";

            DB::afterCommit(function () use ($jobOfferId, $validated, $hrStaffId, $approvalLink) {
                 // Get applicant and HR info
                $applicant = DB::table('applicants')->where('id', $validated['applicant_id'])->first();
                $hrStaff = DB::table('hr_staff')->where('id', $hrStaffId)->first();

                // Top management emails (can be a config or DB query)
                $managementEmails = DB::table('hr_staff')
                    ->where('user_id', 59) // or 'tsuchiya', etc.
                    ->pluck('contact_email');

                foreach ($managementEmails as $email) {
                    Mail::to($email)
                        ->queue(new JobOfferApprovalMail(
                            $applicant->full_name,
                            $validated['draft']['position'] ?? '',
                            $validated['draft']['department'] ?? '',
                            $hrStaff->name ?? 'HR Staff',
                            $jobOfferId,
                            $approvalLink
                        ));
                }
            });

        });

        // Cache invalidation
        Cache::increment('candidates_cache_version');
        $cacheKey = 'board_data_v' . Cache::get('candidates_cache_version', 1);
        Cache::forget($cacheKey);

        return response()->json([
            'message' => 'Job offer saved successfully.'
        ]);
    }


    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,approved,declined,accepted',
            'management_comment' => 'nullable|string',
        ]);

        $now = now();
        $updates = [];
        $params = [];

        $updates[] = "status = ?";
        $params[] = $request->status;

        if ($request->status === 'approved') {
            $updates[] = "approved_at = ?";
            $params[] = $now;
        }

        if ($request->status === 'declined') {
            $updates[] = "declined_at = ?";
            $params[] = $now;
            $updates[] = "declined_reason = ?";
            $params[] = $request->management_comment ?? null;
        }

        $params[] = $id;

        $sql = "UPDATE job_offers SET " . implode(", ", $updates) . " WHERE id = ?";

        DB::update($sql, $params);

        return response()->json([
            'message' => 'Job offer status updated successfully.'
        ]);
    }
}
