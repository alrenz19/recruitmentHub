<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $senderType; // 'hr' or 'applicant'
    public $senderName; // actual HR name or "Recruitment Team"
    public $applicantId;
    public $message;

    public function __construct($senderType, $senderName, $applicantId, $message)
    {
        $this->senderType = $senderType;
        $this->senderName = $senderName;
        $this->applicantId = $applicantId;
        $this->message = $message;
    }

    public function broadcastOn()
    {
        return new Channel("chat.{$this->applicantId}");
    }
}
