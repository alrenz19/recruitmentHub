<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PositionController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('perPage', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search', '');

        $offset = ($page - 1) * $perPage;

        $total = DB::selectOne(
            "SELECT COUNT(*) as total FROM positions WHERE title LIKE ?",
            ["%{$search}%"]
        )->total;

        $positions = DB::select(
            "SELECT * FROM positions WHERE title LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            ["%{$search}%", $perPage, $offset]
        );

        return response()->json([
            'data' => $positions,
            'meta' => [
                'total' => $total,
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'last_page' => ceil($total / $perPage),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->only(['title', 'location', 'type', 'department']);

        $id = DB::table('positions')->insertGetId([
            'title' => $data['title'],
            'location' => $data['location'],
            'type' => $data['type'],
            'department' => $data['department'],
            'created_at' => now(),
        ]);

        $position = DB::selectOne("SELECT * FROM positions WHERE id = ?", [$id]);

        return response()->json($position, 201);
    }

    public function update(Request $request, $id)
    {
        $data = $request->only(['title', 'location', 'type', 'department']);

        $updated = DB::update(
            "UPDATE positions SET title = ?, location = ?, type = ?, department = ? WHERE id = ?",
            [$data['title'], $data['location'], $data['type'], $data['department'], $id]
        );

        if ($updated) {
            $position = DB::selectOne("SELECT * FROM positions WHERE id = ?", [$id]);
            return response()->json($position);
        }

        return response()->json(['error' => 'Position not found or not updated'], 404);
    }

    public function destroy($id)
    {
        $deleted = DB::delete("DELETE FROM positions WHERE id = ?", [$id]);

        if ($deleted) {
            return response()->json(['message' => 'Position deleted']);
        }

        return response()->json(['error' => 'Position not found'], 404);
    }
}
