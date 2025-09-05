<?php

// app/Http/Controllers/JobOfferController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

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
                ap.salary_offer AS salaryOffer,
                jo.status
            FROM job_offers jo
            JOIN applications ap ON ap.job_offer_id = jo.id
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

    public function show($id)
    {
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
              AND jo.id = ?
            LIMIT 1
        ";

        $offer = DB::selectOne($sql, [$id]);

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
}
