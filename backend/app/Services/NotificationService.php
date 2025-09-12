<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Send a notification.
     *
     * @param int $userId
     * @param string $title
     * @param string $message
     * @param string $type
     * @param string|null $link
     * @return bool
     */
    public static function send(int $userId=null, string $title, string $message, string $type = 'general', string $link = null, $role = null)
    {
        return DB::table('notifications')->insert([
            'user_id'    => $userId,
            'title'      => $title,
            'message'    => $message,
            'type'       => $type,
            'link'       => $link,
            'is_read'    => 0,
            'target_role' => $role,
            'created_at' => now(),
            'removed'    => 0,
        ]);
    }
}
