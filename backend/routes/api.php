<?php

use App\Http\Controllers\RequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;

// -------------------------------
// Public route
// -------------------------------
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('verify.recaptcha'); // Recaptcha verification for login

// -------------------------------
// Protected routes (any authenticated user)
// -------------------------------
Route::middleware(['auth:sanctum', 'verify.api'])->group(function () {

    // Dashboard stats
    Route::get('/hr-dashboard/stats', [DashboardController::class, 'getStats'])
        ->name('hr-dashboard.stats');

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

    // Assessment creation (with recaptcha)
    Route::post('/assessments', [AssessmentController::class, 'store'])
        ->middleware('verify.recaptcha');

    // Assessment routes
    Route::get('/assessments/{id}', [AssessmentController::class, 'show']);
    Route::get('/assessments', [AssessmentController::class, 'index']);

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);
});

// -------------------------------
// Admin-only routes
// -------------------------------
Route::middleware(['auth:sanctum', 'role:Admin'])->group(function () {
    
    Route::get('/admin/dashboard', function () {
        return ['message' => 'Welcome Admin!'];
    });

    Route::post('/admin/create-user', [AuthController::class, 'createUser'])
        ->middleware('verify.api');
});
