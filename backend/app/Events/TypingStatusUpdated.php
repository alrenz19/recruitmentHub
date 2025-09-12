<?php
namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;

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
        // All HRs and applicant listening on this applicant's typing channel
        return new PrivateChannel('typing.' . $this->typingStatus['applicant_id']);
    }
}
