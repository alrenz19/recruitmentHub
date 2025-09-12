<?php
Broadcast::channel('typing.{applicantId}', function ($user, $applicantId) {
    // For testing, allow everyone to listen
    return true;
    
    // In production, you can restrict:
    // return $user->role_id === 3 || $user->role_id === 4; 
});