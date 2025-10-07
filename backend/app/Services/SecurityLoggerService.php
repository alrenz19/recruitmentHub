<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use App\Models\SecurityLog;
use Illuminate\Support\Facades\DB;

class SecurityLoggerService
{
    /**
     * Log a security event to both database and file
     *
     * @param string $event The security event type
     * @param string $message The message to log
     * @param array $context Additional context data
     * @return void
     */
    public static function log(string $event, string $message, array $context = []): void
    {
        $request = app('request');
        
        // Extract user information
        $userId = $context['user_id'] ?? Auth::id();
        $userEmail = $context['user_email'] ?? null;
        
        // Prepare log data for database
        $logData = [
            'event_type' => $event,
            'level' => $context['level'] ?? 'info',
            'message' => $message,
            'user_id' => $userId,
            'user_email' => $userEmail,
            'details' => $context,
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ];

        // Store in database with error handling
        try {
            DB::transaction(function () use ($logData) {
                SecurityLog::create($logData);
            });
        } catch (\Exception $e) {
            // If database fails, log to file with error details
            Log::channel('security')->error('Failed to save security log to database: ' . $e->getMessage(), [
                'event' => $event,
                'message' => $message,
                'context' => $context
            ]);
            return; // Exit early if database fails
        }

        // Also log to file for redundancy (with different log level based on context)
        $fileLogContext = array_merge([
            'event' => $event,
            'user_id' => $userId,
            'ip_address' => $logData['ip_address'],
            'timestamp' => now()->toIso8601String(),
        ], $context);

        $logLevel = $logData['level'] ?? 'notice';
        
        switch ($logLevel) {
            case 'error':
                Log::channel('security')->error($message, $fileLogContext);
                break;
            case 'warning':
                Log::channel('security')->warning($message, $fileLogContext);
                break;
            case 'info':
                Log::channel('security')->info($message, $fileLogContext);
                break;
            default:
                Log::channel('security')->notice($message, $fileLogContext);
                break;
        }
    }
    
    /**
     * Log an authentication event
     *
     * @param string $status Success or failure
     * @param string $username The username that was used
     * @param array $context Additional context data
     * @return void
     */
    public static function authAttempt(string $status, string $username, array $context = []): void
    {
        $message = "Authentication {$status} for user {$username}";
        $level = $status === 'success' ? 'info' : 'warning';
        
        self::log('auth_attempt', $message, array_merge([
            'username' => $username,
            'level' => $level,
            'auth_status' => $status
        ], $context));
    }
    
    /**
     * Log a permission denied event
     *
     * @param string $resource The resource that was accessed
     * @param string $action The action that was attempted
     * @param array $context Additional context data
     * @return void
     */
    public static function permissionDenied(string $resource, string $action, array $context = []): void
    {
        $message = "Permission denied for {$action} on {$resource}";
        
        self::log('permission_denied', $message, array_merge([
            'resource' => $resource,
            'action' => $action,
            'level' => 'warning'
        ], $context));
    }
    
    /**
     * Log a sensitive data access event
     *
     * @param string $resource The resource that was accessed
     * @param array $context Additional context data
     * @return void
     */
    public static function sensitiveDataAccess(string $resource, array $context = []): void
    {
        $message = "Sensitive data accessed: {$resource}";
        
        self::log('sensitive_data_access', $message, array_merge([
            'resource' => $resource,
            'level' => 'info'
        ], $context));
    }
    
    /**
     * Log a security configuration change
     *
     * @param string $component The component that was changed
     * @param string $change Description of the change
     * @param array $context Additional context data
     * @return void
     */
    public static function configChange(string $component, string $change, array $context = []): void
    {
        $message = "Security configuration changed for {$component}: {$change}";
        
        self::log('config_change', $message, array_merge([
            'component' => $component,
            'change' => $change,
            'level' => 'info'
        ], $context));
    }
    
    /**
     * Log a user account activity
     *
     * @param string $activity Description of the activity
     * @param array $context Additional context data
     * @return void
     */
    public static function userActivity(string $activity, array $context = []): void
    {
        $message = "User activity: {$activity}";
        
        self::log('user_activity', $message, array_merge([
            'activity' => $activity,
            'level' => 'info'
        ], $context));
    }
    
    /**
     * Log a system security event
     *
     * @param string $event Description of the system event
     * @param array $context Additional context data
     * @return void
     */
    public static function systemEvent(string $event, array $context = []): void
    {
        $message = "System security event: {$event}";
        
        self::log('system_event', $message, array_merge([
            'system_event' => $event,
            'level' => 'info'
        ], $context));
    }
    
    /**
     * Get security logs with optional filtering
     *
     * @param array $filters
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public static function getLogs(array $filters = [])
    {
        $query = SecurityLog::with('user')->latest();
        
        if (isset($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }
        
        if (isset($filters['level'])) {
            $query->where('level', $filters['level']);
        }
        
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        
        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        
        return $query->paginate($filters['per_page'] ?? 20);
    }
    
    /**
     * Clean up old security logs
     *
     * @param int $daysOld Delete logs older than this many days
     * @return int Number of deleted logs
     */
    public static function cleanupOldLogs(int $daysOld = 90): int
    {
        $cutoffDate = now()->subDays($daysOld);
        
        return SecurityLog::where('created_at', '<', $cutoffDate)->delete();
    }
}