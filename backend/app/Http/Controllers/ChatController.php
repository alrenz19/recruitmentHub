<?php
use App\Events\MessageSent;
use App\Events\TypingEvent;

class ChatController extends Controller
{
    public function sendMessage(Request $request)
    {
        $senderType = auth()->user()->is_hr ? 'hr' : 'applicant';
        $senderName = $senderType === 'hr' ? auth()->user()->name : 'Recruitment Team';
        
        broadcast(new MessageSent(
            $senderType,
            $senderName,
            $request->applicant_id,
            $request->message
        ))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    public function typing(Request $request)
    {
        $senderType = auth()->user()->is_hr ? 'hr' : 'applicant';
        $senderName = $senderType === 'hr' ? auth()->user()->name : 'Recruitment Team';

        broadcast(new TypingEvent(
            $senderType,
            $senderName,
            $request->applicant_id
        ))->toOthers();

        return response()->json(['status' => 'typing']);
    }
}
