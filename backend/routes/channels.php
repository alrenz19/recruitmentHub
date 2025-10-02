<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

// HR notifications channel - shared for all HR
Broadcast::channel('hr.notifications', function ($user) {
    // HR roles can access HR notifications
    if (in_array($user->role_id, [2, 3, 5])) return true;
    
    return false;
});

// Applicant notifications channel
Broadcast::channel('applicant.notifications', function ($user) {
    // Only applicants can access applicant notifications
    if ($user->role_id == 4) return true;
    
    return false;
});

// HR chat channel (without applicantId) - for shared HR chat
Broadcast::channel('hr.chat', function ($user) {
    // HR roles can access shared HR chat
    if (in_array($user->role_id, [2, 3, 5])) return true;
    
    return false;
});

// Your existing channels...
Broadcast::channel('applicant.{applicantId}', function ($user, $applicantId) {
    // If user is applicant, check if it's their channel
    if ($user->role_id == 4)
        return $user->id == $applicantId ? true : false;
    
    // HR roles can access any applicant channel
    if (in_array($user->role_id, [2, 3, 5])) return true;
    
    return false;
});

// HR chat channel - HR roles can access, applicants can also access to see typing indicators
Broadcast::channel('hr.chat.{applicantId}', function ($user, $applicantId) {
    // HR roles can access any HR chat channel
    if (in_array($user->role_id, [2, 3, 5])) return true;
    
    // Applicants can also access HR chat channels (to see typing indicators)
    if ($user->role_id == 4) {
        return $user->id == $applicantId ? true : false;
    }
    
    return false;
});