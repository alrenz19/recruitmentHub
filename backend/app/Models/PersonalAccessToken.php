<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class PersonalAccessToken extends SanctumToken
{
    // Disable automatic last_used_at updates
    public function updateTokenLastUsed()
    {
        return $this;
    }
}
