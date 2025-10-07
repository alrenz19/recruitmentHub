<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class HRStaffListUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $staff; // data to send

    public function __construct($staff)
    {
        $this->staff = $staff;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('hr.staff-list');
    }

    public function broadcastAs()
    {
        return 'staff-list-updated';
    }
}
