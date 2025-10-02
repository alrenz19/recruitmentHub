<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use SerializesModels;

    public $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function broadcastOn()
    {
        if ($this->message->is_from_applicant) {
            return [new PrivateChannel("hr.chat.{$this->message->applicant_id}")];
        } else {
            return [
                new PrivateChannel("applicant.{$this->message->applicant_id}"),
                new PrivateChannel("hr.chat.{$this->message->applicant_id}"),
            ];
        }
    }

    public function broadcastAs()
    {
        return 'message-sent';
    }

    public function broadcastWith()
    {

        return [
            'message' => [
                'id' => $this->message->id,
                'applicant_id' => $this->message->applicant_id,
                'hr_id' => $this->message->hr_id,
                'message' => $this->message->message,
                'is_from_applicant' => $this->message->is_from_applicant,
                'created_at' => $this->message->created_at,
            ]
        ];
    }
    
}
