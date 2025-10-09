<?php 

// app/Http/Controllers/NotificationController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotificationController extends Controller
{
    // Fetch notifications categorized
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        // Unread notifications (all)
        $unread = DB::select("
            SELECT 
                id,
                title,
                message,
                type,
                is_read AS isUnread,
                created_at,
                CASE 
                    WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, created_at, NOW()), ' hrs ago')
                    ELSE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
                END AS time
            FROM notifications
            WHERE target_role = ? AND is_read = 0 AND removed = 0
            ORDER BY created_at DESC
        ", ['hr']);

        // Read notifications (limit 5)
        $read = DB::select("
            SELECT 
                id,
                title,
                message,
                type,
                is_read AS isUnread,
                created_at,
                CASE 
                    WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, created_at, NOW()), ' hrs ago')
                    ELSE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
                END AS time
            FROM notifications
            WHERE target_role = ? AND is_read = 1 AND removed = 0
            ORDER BY created_at DESC
            LIMIT 5
        ", ['hr']);

        return response()->json([
            [
                'category' => 'unread',
                'notifications' => $unread
            ],
            [
                'category' => 'read',
                'notifications' => $read
            ]
        ]);
    }


    // Update is_read status
    public function updateReadStatus(Request $request, $id)
    {
        $userId = auth()->id();
        $isUnread = $request->input('isUnread', true); // true = mark as unread
        $isReadValue = $isUnread ? 0 : 1;

        $updated = DB::update("UPDATE notifications SET is_read = ? WHERE id = ? AND removed = 0 AND user_id = ?", [$isReadValue, $id, $userId]);

        if ($updated) {
            return response()->json(['message' => 'Notification updated successfully']);
        } else {
            return response()->json(['message' => 'Notification not found or already updated'], 404);
        }
    }

    // Soft delete notification (set removed = 1)
    public function destroy(Request $request, $id)
    {
        DB::update("
            UPDATE notifications
            SET removed = 1
            WHERE id = ?
        ", [$id]);

        return response()->json(['message' => 'Notification removed successfully']);
    }
}
