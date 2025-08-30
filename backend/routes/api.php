<?php

use App\Http\Controllers\RequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// -------------------------------
// Public routes
// -------------------------------
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('verify.recaptcha'); // Recaptcha verification for login

// -------------------------------
// Protected routes (any authenticated user)
// -------------------------------
Route::middleware(['auth:sanctum', 'verify.api', 'throttle:role_based'])->group(function () {
    // User profile
    Route::get('/profile', function (Request $request) {
        return $request->user();
    });

    // Check authentication info
    Route::get('/check-auth', function (Request $request) {
        $user = $request->user();
        return response()->json([
            'id' => $user->id,
            'role_id' => $user->role_id,
            'full_name' => $user->full_name,
        ]);
    });

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);
});

// -------------------------------
// Admin-only routes
// -------------------------------
Route::middleware(['auth:sanctum', 'verify.api', 'throttle:role_based', 'verify.role'])->group(function () {    
    // Dashboard stats
    Route::get('/hr-dashboard/stats', [DashboardController::class, 'getStats'])
        ->name('hr-dashboard.stats');
    
    // Assessment routes
    Route::post('/assessments', [AssessmentController::class, 'store']);
    Route::get('/assessments/{id}', [AssessmentController::class, 'show']);
    Route::get('/assessments', [AssessmentController::class, 'index']);
    Route::put('/assessments/{id}', [AssessmentController::class, 'update']);
    Route::delete('/assessments/{id}', [AssessmentController::class, 'destroy']);
});

// -------------------------------
// Catch-all route (must be at the end)
// -------------------------------
Route::any('{any}', function () {
    return response()->json([
        'message' => 'API endpoint not found'
    ], 404);
})->where('any', '.*');