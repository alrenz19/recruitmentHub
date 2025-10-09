<?php

namespace App\Console\Commands;

use App\Models\LoginOtp;
use Illuminate\Console\Command;

class CleanupExpiredOtps extends Command
{
    protected $signature = 'otp:cleanup';
    protected $description = 'Clean up expired OTPs from the database';

    public function handle(): void
    {
        $deleted = LoginOtp::where('expires_at', '<', now())->delete();

        $this->info("Cleaned up {$deleted} expired OTPs.");

        // Also delete verified OTPs older than 1 day
        $verifiedDeleted = LoginOtp::where('verified_at', '<', now()->subDay())->delete();
        
        $this->info("Cleaned up {$verifiedDeleted} verified OTPs.");
    }
}