<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     */
    protected array $commands = [
        \App\Console\Commands\EncryptDatabase::class,
        // Add other commands here
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Option 1: Using a dedicated command (recommended)
        $schedule->command('auth:cleanup-tokens')
                 ->dailyAt('03:00')
                 ->onOneServer();

        // Option 2: Using closure with proper error handling
        $schedule->call(function () {
            try {
                app(\App\Http\Controllers\AuthController::class)->cleanupExpiredTokens();
                \Log::info('Expired tokens cleaned up successfully');
            } catch (\Exception $e) {
                \Log::error('Token cleanup failed: ' . $e->getMessage());
            }
        })->dailyAt('03:00');

        $schedule->command('otp:cleanup')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        // Alternatively, you can manually register commands:
        // require base_path('routes/console.php');
    }
}