<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class TypingStatusUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public $typingStatus;

    public function __construct($typingStatus)
    {
        $this->typingStatus = $typingStatus;
    }

    public function broadcastOn()
    {
        if ($this->typingStatus['user_type'] === 'applicant') {
            // Applicant typing → only HR team sees it
            return [new PrivateChannel("hr.chat.{$this->typingStatus['applicant_id']}")];
        } else {
            // HR typing → applicant sees generic "recruitment team typing"
            // Other HR staff see specific "team member [name] is responding"
            return [
                new PrivateChannel("applicant.{$this->typingStatus['applicant_id']}"),
                new PrivateChannel("hr.chat.{$this->typingStatus['applicant_id']}"),
            ];
        }
    }

    public function broadcastAs()
    {
        return 'typing-status-updated';
    }
}