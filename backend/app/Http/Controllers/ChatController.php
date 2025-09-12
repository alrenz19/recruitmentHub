<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\MessageSent;
use App\Events\TypingStatusUpdated;

class ChatController extends Controller
{
    public function history($applicantId)
    {
        $messages = DB::select("
            SELECT id, applicant_id, hr_id, message, created_at
            FROM chat_messages
            WHERE applicant_id = ?
            ORDER BY created_at ASC
        ", [$applicantId]);

        return response()->json($messages);
    }

    public function ApplicantChatHistory()
    {
        $signId = auth()->id();
        $applicant = DB::table('applicants')
            ->where('user_id', $signId)
            ->first();
        if (!$applicant) {
            return response()->json(['message' => 'Applicant record not found for current user'], 422);
        }
        $applicantId = $applicant->id ?? 0;
        $messages = DB::select("
            SELECT id, applicant_id, hr_id, message, created_at
            FROM chat_messages
            WHERE applicant_id = ?
            ORDER BY created_at ASC
        ", [$applicantId]);

        return response()->json($messages);
    }

    public function send(Request $request)
    {
        $request->validate([
            'applicant_id' => 'required|integer',
            'message' => 'required|string',
            'hr_id' => 'nullable|integer',
        ]);

        $id = DB::table('chat_messages')->insertGetId([
            'applicant_id' => $request->applicant_id,
            'hr_id'        => $request->hr_id,
            'message'      => $request->message,
            'created_at'   => now(),
        ]);

        $message = DB::table('chat_messages')->where('id', $id)->first();

        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message);
    }

    public function ApplicantChatSend(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $signId = auth()->id();
        $applicant = DB::table('applicants')
            ->where('user_id', $signId)
            ->first();
        if (!$applicant) {
            return response()->json(['message' => 'Applicant record not found for current user'], 422);
        }

        $applicantId = $applicant->id ?? 0;
        $id = DB::table('chat_messages')->insertGetId([
            'applicant_id' => $applicantId,
            'message'      => $request->message,
            'created_at'   => now(),
        ]);

        $message = DB::table('chat_messages')->where('id', $id)->first();

        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message);
    }

    public function typing(Request $request)
    {
        $user = auth()->user();

        // Determine who is typing
        if ($user->role_id === 4) { // Applicant
            $applicant_id = $request->applicant_id;
            $hr_id        = $request->hr_id ?? 1;
        } else { // HR
            $hr_id        = $request->hr_id;
            $applicant_id = $request->applicant_id;
        }

        // Validate input
        $request->validate([
            'is_typing' => 'required|boolean',
            'applicant_id' => $user->role_id === 3 ? 'required|integer' : 'nullable',
        ]);

        // Update or insert typing status
        DB::table('chat_typing_status')
            ->updateOrInsert(
                [
                    'applicant_id' => $applicant_id,
                    'hr_id'        => $hr_id,
                ],
                [
                    'is_typing'  => $request->is_typing,
                    'updated_at' => now(),
                ]
            );

        // Broadcast to everyone listening to this applicant
        broadcast(new TypingStatusUpdated([
            'applicant_id' => $applicant_id,
            'hr_id'        => $hr_id,
            'is_typing'    => (bool) $request->is_typing,
        ]))->toOthers();

        return response()->json(['success' => true]);
    }

    public function contacts()
    {
        $contacts = DB::select("
            SELECT a.id, a.full_name AS name,
                MAX(m.is_unread) as is_unread,
                MAX(m.created_at) as timestamp,
                SUBSTRING_INDEX(MAX(CONCAT(m.created_at, '|||', m.message)), '|||', -1) as last_message
            FROM applicants a
            JOIN chat_messages m ON m.applicant_id = a.id
            GROUP BY a.id, a.full_name
            ORDER BY timestamp DESC
        ");

        return response()->json($contacts);
    }
}
