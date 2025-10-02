<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\MessageSent;
use App\Events\TypingStatusUpdated;
use App\Events\UnreadStatusNotification;

class ChatController extends Controller
{
    public function history($applicantId)
    {
        $user = auth()->user();
        
        // Authorization check - HR can see any applicant, applicants can only see their own
        if ($user->role_id == 4 && $user->id != $applicantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $messages = DB::select("
            SELECT 
                id, 
                message, 
                created_at, 
                applicant_id,
                hr_id,
                is_from_applicant
            FROM chat_messages 
            WHERE applicant_id = ? 
            ORDER BY created_at ASC
        ", [$applicantId]);

        return response()->json($messages);
    }

    public function ApplicantChatHistory()
    {
        $userId = auth()->id();
        
        $messages = DB::select("
            SELECT 
                id, 
                message, 
                created_at, 
                applicant_id,
                hr_id,
                is_from_applicant
            FROM chat_messages 
            WHERE applicant_id = ? 
            ORDER BY created_at ASC
        ", [$userId]);

        return response()->json($messages);
    }

    public function send(Request $request)
    {
        $user = auth()->user();
        
        $request->validate([
            'applicant_id' => 'required|integer',
            'message' => 'required|string',
        ]);

        $isFromApplicant = $user->role_id === 4;
        
        // For HR messages, store which HR sent it
        $hrId = $isFromApplicant ? null : $user->id;
        $applicantId = $isFromApplicant ? $user->id : $request->applicant_id;

        $id = DB::table('chat_messages')->insertGetId([
            'applicant_id' => $applicantId,
            'hr_id' => $hrId,
            'message' => $request->message,
            'is_from_applicant' => $isFromApplicant,
            'created_at' => now(),
            'is_unread' => 1
        ]);

        $message = DB::table('chat_messages')->where('id', $id)->first();

        // Broadcast using the corrected event logic
        broadcast(new MessageSent($message));

        DB::update("
            UPDATE chat_messages 
            SET is_unread = 0 
            WHERE applicant_id = ? && is_from_applicant = 1
        ", [$applicantId]);

        $this->notifyUnreadStatusCheck($isFromApplicant);

        return response()->json($message);
    }

    public function ApplicantChatSend(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $user = auth()->user();
        
        $id = DB::table('chat_messages')->insertGetId([
            'applicant_id' => $user->id,
            'hr_id' => null,
            'message' => $request->message,
            'is_from_applicant' => true,
            'created_at' => now(),
            'is_unread' => 1
        ]);

        $message = DB::table('chat_messages')->where('id', $id)->first();

        broadcast(new MessageSent($message));

        DB::update("
            UPDATE chat_messages 
            SET is_unread = 0 
            WHERE applicant_id = ? && is_from_applicant = 0
        ", [$user->id]);

        $this->notifyUnreadStatusCheck($user->role_id === 4);

        return response()->json($message);
    }

    public function typing(Request $request)
    {
        $user = auth()->user();
        
        $request->validate([
            'is_typing' => 'required|boolean',
            'applicant_id' => 'required|integer',
        ]);

        $isApplicant = $user->role_id === 4;
        
        if ($isApplicant) {
            // Applicant is typing - notify all HR staff for this applicant
            $typingData = [
                'applicant_id' => $user->id,
                'hr_id' => null,
                'is_typing' => $request->is_typing,
                'user_type' => 'applicant',
                'typing_user_name' => 'Applicant',
            ];
        } else {
            // HR staff is typing - notify applicant and other HR staff
            $typingData = [
                'applicant_id' => $request->applicant_id,
                'hr_id' => $user->id,
                'is_typing' => $request->is_typing,
                'user_type' => 'hr',
                'typing_user_name' => 'recruitment team',
                'typing_hr_id' => $user->id,
            ];
        }

        broadcast(new TypingStatusUpdated($typingData));

        return response()->json([
            'success' => true,
            'data' => $typingData
        ]);
    }

    public function getTypingStatus($applicantId)
    {
        $user = auth()->user();
        
        if ($user->role_id == 4 && $user->id != $applicantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $typingStatus = DB::select("
            SELECT cts.typing_user_id, u.name, u.role_id, cts.is_typing, cts.updated_at
            FROM chat_typing_status cts
            JOIN users u ON u.id = cts.typing_user_id
            WHERE cts.applicant_id = ? AND cts.is_typing = 1
            ORDER BY cts.updated_at DESC
        ", [$applicantId]);

        return response()->json($typingStatus);
    }

    public function contacts()
    {
        $user = auth()->user();
        
        if ($user->role_id == 4) {
            return response()->json([]);
        }
        
        $contacts = DB::select("
            SELECT 
                u.id AS user_id,
                a.full_name AS name,
                u.id AS applicant_id,
                MAX(CASE WHEN m.is_unread = 1 AND m.is_from_applicant = 1 THEN 1 ELSE 0 END) as is_unread,
                MAX(m.created_at) as timestamp,
                SUBSTRING_INDEX(MAX(CONCAT(m.created_at, '|||', m.message)), '|||', -1) as last_message
            FROM users u
            JOIN applicants a ON a.user_id = u.id
            LEFT JOIN chat_messages m ON m.applicant_id = u.id
            WHERE u.role_id = 4
            GROUP BY u.id, a.full_name
            ORDER BY timestamp DESC
        ");

        return response()->json($contacts);
    }


    // In your ChatController
    public function getUnreadCount(Request $request)
    {
        $user = auth()->user();
        
        $isApplicant = $user->role_id === 4;
        
        if ($isApplicant) {
            $result = DB::selectOne("
                SELECT EXISTS(
                    SELECT 1 FROM chat_messages 
                    WHERE is_from_applicant = 0 
                    AND applicant_id = ? 
                    AND is_unread = 1
                ) as has_unread
            ", [$user->id]);
        } else {
            // Debug: Check what unread applicant messages exist
            $unreadApplicantMessages = DB::select("
                SELECT id, applicant_id, hr_id, is_unread, is_from_applicant
                FROM chat_messages 
                WHERE is_from_applicant = 1 
                AND is_unread = 1
                ORDER BY created_at DESC
            ");
            $result = DB::selectOne("
                SELECT EXISTS(
                    SELECT 1 FROM chat_messages 
                    WHERE is_from_applicant = 1 
                    AND is_unread = 1
                ) as has_unread
            ");
        }

        return response()->json([
            'has_unread' => (bool) $result->has_unread,
        ]);
    }

    // In your ChatController
    private function notifyUnreadStatusCheck($isFromApplicant)
    {
        if ($isFromApplicant) {
            // Applicant sent message - notify all HR
            broadcast(new UnreadStatusNotification('hr'));
        } else {
            // HR sent message - notify the specific applicant
            broadcast(new UnreadStatusNotification('applicant'));
        }
    }
}
