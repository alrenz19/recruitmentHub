<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class UnreadStatusNotification implements ShouldBroadcast
{
    use SerializesModels;

    public $userType;

    public function __construct($userType)
    {
        $this->userType = $userType;
    }

    public function broadcastOn()
    {
        if ($this->userType === 'applicant') {
            return new PrivateChannel("private-applicant.notifications");
        } else {
            // Add "private-" prefix to match frontend subscription
            return new PrivateChannel("private-hr.notifications");
        }
    }

    public function broadcastAs()
    {
        return 'unread-status-notification';
    }

    public function broadcastWith()
    {
        return [
            'user_type' => $this->userType,
            'timestamp' => now(),
        ];
    }
}