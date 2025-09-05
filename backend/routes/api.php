<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


use App\Http\Controllers\RequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\RecruitmentBoardController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\HRStaffController;
use App\Http\Controllers\JobOfferController;
use App\Http\Controllers\NotificationController;



// -------------------------------
// Public routes
// -------------------------------
// Route::post('/login', [AuthController::class, 'login'])
//     ->middleware('verify.recaptcha');

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

// -------------------------------
// Protected routes (any authenticated user)
// -------------------------------
// Route::middleware(['auth.cached', 'track.token.usage', 'verify.api'])
// Route::middleware(['auth:sanctum', 'verify.api'])->group(function () {


//     // Check authentication info
//     Route::get('/check-auth', function (Request $request) {
//         // Cache user per request
//         if (!$request->attributes->has('cached_user')) {
//             $request->attributes->set('cached_user', $request->user());
//         }

//         $user = $request->attributes->get('cached_user');

//         return response()->json([
//             'id' => $user->id,
//             'role_id' => $user->role_id,
//             'full_name' => $user->full_name,
//         ]);
//     });

//     // Logout
//     Route::post('/logout', [AuthController::class, 'logout']);
// });

Route::middleware(['auth:sanctum'])->get('/check-auth', function (Request $request) {

    $userId = $request->user()->id;

    // Cache the minimal user info for 1 minute
    $user = Cache::remember("user:{$userId}", 60, function () use ($request) {
        $u = $request->user();
        return [
            'id' => $u->id,
            'role_id' => $u->role_id,
            'full_name' => $u->full_name,
        ];
    });

    return response()->json($user);
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
    Route::post('/candidates', [CandidateController::class, 'createCandidate']);
    Route::put('/candidates/{id}', [CandidateController::class, 'updateCandidate']);

    // Recruitment routes
    Route::get('/recruitment-board', [RecruitmentBoardController::class, 'getBoard']);
    Route::get('/recruitment-board/{stage}', [RecruitmentBoardController::class, 'getStageApplicants']);
    Route::get('/recruitment-board-details/{id}', [RecruitmentBoardController::class, 'getApplicantDetails']);

    // Schedule routes
    Route::get('/schedules', [ScheduleController::class, 'index']);
    Route::post('/schedules', [ScheduleController::class, 'updateSchedule']);

    // HR staff routes
    Route::get('/hr-staff', [HRStaffController::class, 'index']);
    Route::post('/hr-staff', [HRStaffController::class, 'store']);
    Route::put('/hr-staff/{id}', [HRStaffController::class, 'update']);
    Route::delete('/hr-staff/{id}', [HRStaffController::class, 'destroy']);


    // Job offer routes
    Route::get('/job-offers', [JobOfferController::class, 'index']);
    Route::get('/job-offers/chart', [JobOfferController::class, 'chartData']);
    Route::get('/job-offers/{id}', [JobOfferController::class, 'show']);


    // notification routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'updateReadStatus']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
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
