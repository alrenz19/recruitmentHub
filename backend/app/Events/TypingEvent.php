<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class TypingEvent implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $senderType; 
    public $senderName; 
    public $applicantId;

    public function __construct($senderType, $senderName, $applicantId)
    {
        $this->senderType = $senderType;
        $this->senderName = $senderName;
        $this->applicantId = $applicantId;
    }

    public function broadcastOn()
    {
        return new Channel("chat.{$this->applicantId}");
    }
}
