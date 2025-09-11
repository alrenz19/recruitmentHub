<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class RecruitmentNoteController extends Controller
{
    public function store(Request $request)
    {
        // validate input
        $request->validate([
            'applicant_id' => 'required|integer',
            'note' => 'required|string',
        ]);

        $userId = auth()->id(); // same as created_by_user_id
        $hrStaff = DB::table('hr_staff')
            ->where('user_id', $userId)
            ->first();

        $hrId = $hrStaff->id ?? 1;

        // raw SQL insert
        $sql = "INSERT INTO recruitment_notes 
                   (applicant_id, hr_id, note, created_by_user_id, created_at, removed) 
                VALUES (?, ?, ?, ?, NOW(), 0)";

        DB::insert($sql, [
            $request->applicant_id,
            $hrId,
            $request->note,
            $userId
        ]);

        // 🚀 Invalidate board cache by bumping version
        Cache::increment('candidates_cache_version');
        $cacheVersion = Cache::get('candidates_cache_version', 1);
        // Build cache key based on version and request parameters
        $cacheKey = 'board_data_v' . $cacheVersion;
        Cache::forget($cacheKey);

        return response()->json(['message' => 'Note added successfully']);
    }
}
