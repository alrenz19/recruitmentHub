<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use App\Mail\HRStaffCreated;


class HRStaffController extends Controller
{
    /**
     * Get all HR staff paginated (default 10).
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page = max((int) $request->get('page', 1), 1);
        $search = $request->get('search');
        $roleId = $request->get('role_id');

        $offset = ($page - 1) * $perPage;

        $query = "
            SELECT hr.id, hr.full_name, hr.contact_email, r.name AS role_name, u.role_id
            FROM hr_staff AS hr
            JOIN users AS u ON hr.user_id = u.id
            JOIN roles AS r ON u.role_id = r.id
            WHERE hr.removed = 0 AND u.is_removed = 0
        ";

        $bindings = [];

        if ($search) {
            $query .= " AND (hr.full_name LIKE ? OR hr.contact_email LIKE ?)";
            $bindings[] = "%$search%";
            $bindings[] = "%$search%";
        }

        if ($roleId) {
            $query .= " AND u.role_id = ?";
            $bindings[] = $roleId;
        }

        $countQuery = "SELECT COUNT(*) as total FROM ($query) as sub";
        $total = DB::selectOne($countQuery, $bindings)->total;

        $query .= " ORDER BY hr.created_at DESC LIMIT ? OFFSET ?";
        $bindings[] = $perPage;
        $bindings[] = $offset;

        $staff = DB::select($query, $bindings);

        return response()->json([
            'data' => $staff,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage),
            ]
        ]);
    }



    /**
     * Store a new HR staff user and send email notification.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'role_id'       => 'required|integer',
            'user_email'    => 'required|email|unique:users,user_email',
            'password_hash' => 'required|string',
            'full_name'     => 'required|string|max:150|unique:hr_staff,full_name',
            'contact_email' => 'required|email|unique:hr_staff,contact_email',
        ]);
        $hashed = bcrypt($validated['password_hash'], ['rounds' => 10]);
        try {
            DB::beginTransaction();

            // Insert into users
            DB::insert("
                INSERT INTO users (role_id, user_email, password_hash, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ", [
                $validated['role_id'],
                $validated['user_email'],
                $hashed,
            ]);

            $userId = DB::getPdo()->lastInsertId();

            // Insert into hr_staff
            DB::insert("
                INSERT INTO hr_staff (user_id, full_name, contact_email, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ", [
                $userId,
                $validated['full_name'],
                $validated['contact_email'],
            ]);

            DB::commit();

            // Send email (using Laravel Mail raw)
            Mail::to($validated['contact_email'])
                ->queue(new HRStaffCreated($validated['user_email'], $validated['password_hash']));


            return response()->json([
                'message' => 'HR Staff created successfully and email sent.',
                'user_id' => $userId,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'role_id'       => 'required|integer',
            'user_email'    => "required|email",
            'password_hash' => 'nullable|string',
            'full_name'     => "required|string|max:150|unique:hr_staff,full_name,{$id},id",
            'contact_email' => "required|email",
        ]);

        try {
            DB::beginTransaction();

            // ✅ First get user_id linked to hr_staff
            $staff = DB::selectOne("SELECT user_id FROM hr_staff WHERE id = ?", [$id]);
            if (!$staff) {
                return response()->json(['error' => 'HR Staff not found'], 404);
            }
            $userId = $staff->user_id;

            // ✅ Update users table
            if (!empty($validated['password_hash'])) {
                $hashed = bcrypt($validated['password_hash'], ['rounds' => 10]);
                DB::update("
                    UPDATE users
                    SET role_id = ?, user_email = ?, password_hash = ?, updated_at = NOW()
                    WHERE id = ?
                ", [
                    $validated['role_id'],
                    $validated['user_email'],
                    $hashed,
                    $userId
                ]);
            } else {
                DB::update("
                    UPDATE users
                    SET role_id = ?, user_email = ?, updated_at = NOW()
                    WHERE id = ?
                ", [
                    $validated['role_id'],
                    $validated['user_email'],
                    $userId
                ]);
            }

            // ✅ Update hr_staff table
            DB::update("
                UPDATE hr_staff
                SET full_name = ?, contact_email = ?, updated_at = NOW()
                WHERE id = ?
            ", [
                $validated['full_name'],
                $validated['contact_email'],
                $id
            ]);

            DB::commit();

            return response()->json([
                'message' => 'HR Staff updated successfully.',
                'staff_id' => $id,
                'user_id'  => $userId,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            // Soft delete hr_staff
            DB::update("
                UPDATE hr_staff
                SET removed = 1, updated_at = NOW()
                WHERE id = ?
            ", [$id]);

            // Soft delete related user
            DB::update("
                UPDATE users
                SET is_removed = 1, updated_at = NOW()
                WHERE id = (
                    SELECT user_id FROM hr_staff WHERE id = ?
                )
            ", [$id]);

            DB::commit();

            return response()->json([
                'message' => 'HR staff soft deleted successfully.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function search(Request $request)
    {
        $q = $request->query('q', '');

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $results = DB::select("
            SELECT id, full_name, contact_email
            FROM hr_staff
            WHERE removed = 0 
            AND (full_name LIKE ?)
            LIMIT 5
        ", ["%$q%"]);

        return response()->json($results);
    }


}
