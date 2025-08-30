<?php

use App\Http\Controllers\RequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateController;
use Illuminate\Support\Facades\Mail;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// -------------------------------
// Public routes
// -------------------------------
// Route::post('/login', [AuthController::class, 'login'])
//     ->middleware('verify.recaptcha');

Route::post('/login', [AuthController::class, 'login']);

// -------------------------------
// Protected routes (any authenticated user)
// -------------------------------
// Route::middleware(['auth.cached', 'track.token.usage', 'verify.api'])
Route::middleware(['auth:sanctum', 'verify.api'])->group(function () {


    // Check authentication info
    Route::get('/check-auth', function (Request $request) {
        // Cache user per request
        if (!$request->attributes->has('cached_user')) {
            $request->attributes->set('cached_user', $request->user());
        }

        $user = $request->attributes->get('cached_user');

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

//Route::middleware(['auth.cached', 'verify.api', 'verify.role'])->group(function ()
Route::middleware(['auth:sanctum'])->group(function () {    
    // Dashboard stats
    Route::get('/hr-dashboard/stats', [DashboardController::class, 'getStats'])
        ->name('hr-dashboard.stats');
    
    // Assessment routes
    Route::get('/assessments/{id}', [AssessmentController::class, 'show']);
    Route::get('/assessments', [AssessmentController::class, 'index']);
    Route::get('/retrieve-assessments', [AssessmentController::class, 'retrieveAssessments']);

    Route::post('/assessments', [AssessmentController::class, 'store']);
    Route::put('/assessments/{assessment}', [AssessmentController::class, 'update']);
    Route::delete('/assessments/{assessment}', [AssessmentController::class, 'destroy']);

    // Candidate routes
    Route::get('/candidates/{id}', [CandidateController::class, 'show']);
    Route::get('/candidates', [CandidateController::class, 'index']);
});

// //route for submit request
// Route::middleware(['auth:sanctum', 'verify.api', 'verify.role'])->group(function () {  
//     // Assessment routes
//     Route::post('/assessments', [AssessmentController::class, 'store']);
//     Route::put('/assessments/{assessment}', [AssessmentController::class, 'update']);
//     Route::delete('/assessments/{assessment}', [AssessmentController::class, 'destroy']);


//     // Candidate routes
//     Route::post('/candidates', [CandidateController::class, 'createCandidate']);
// });

// -------------------------------
// Catch-all route (must be at the end)
// -------------------------------
Route::any('{any}', function () {
    return response()->json([
        'message' => 'API endpoint not found'
    ], 404);
})->where('any', '.*');
