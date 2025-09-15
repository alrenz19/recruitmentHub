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
use App\Http\Controllers\FileSubmissionController;
use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\ApplicantSettingsController;
use App\Http\Controllers\JobOfferController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ApplicantPipelineScoreController;
use App\Http\Controllers\RecruitmentNoteController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\UserPrivacyController;
use App\Http\Controllers\ApplicantDashboardController;
use App\Http\Controllers\ApplicantNotificationController;



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
            'accept_privacy_policy' => $u->accept_privacy_policy === 0 ? false : true
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
    Route::put('/assessments/{id}', [AssessmentController::class, 'update']);
    Route::delete('/assessments/{id}', [AssessmentController::class, 'destroy']);

    // Candidate routes
    Route::get('/candidates/{id}', [CandidateController::class, 'show']);
    Route::get('/candidates', [CandidateController::class, 'index']);
    Route::post('/candidates', [CandidateController::class, 'createCandidate']);
    Route::put('/candidates/{id}', [CandidateController::class, 'updateCandidate']);

    // Recruitment routes
    Route::get('/recruitment-board', [RecruitmentBoardController::class, 'getBoard']);
    Route::get('/recruitment-board/{stage}', [RecruitmentBoardController::class, 'getStageApplicants']);
    Route::get('/recruitment-board-details/{id}', [RecruitmentBoardController::class, 'getApplicantDetails']);
    Route::patch('/applicant-pipeline/{id}/status', [RecruitmentBoardController::class, 'updateStatus']);
    
    // Score row
    Route::post('/applicant-pipeline/score', [ApplicantPipelineScoreController::class, 'updateScore']);

    // Schedule routes
    Route::get('/schedules', [ScheduleController::class, 'index']);
    Route::post('/schedules', [ScheduleController::class, 'updateSchedule']);

    // HR staff routes
    Route::get('/hr-staff', [HRStaffController::class, 'index']);
    Route::post('/hr-staff', [HRStaffController::class, 'store']);
    Route::put('/hr-staff/{id}', [HRStaffController::class, 'update']);
    Route::delete('/hr-staff/{id}', [HRStaffController::class, 'destroy']);
    Route::get('/participants/search', [HRStaffController::class, 'search']);


    // Job offer routes
    Route::get('/job-offers', [JobOfferController::class, 'index']);
    Route::get('/job-offers/chart', [JobOfferController::class, 'chartData']);
    Route::get('/job-offers/{id}', [JobOfferController::class, 'show']);
    Route::post('/job-offers', [JobOfferController::class, 'store']);
    Route::patch('/job-offers/{id}/status', [JobOfferController::class, 'updateStatus']);


    // notification routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'updateReadStatus']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // notes
    Route::post('/recruitment-notes', [RecruitmentNoteController::class, 'store']);

    // chat
    Route::get('/chat/history/{applicantId}', [ChatController::class, 'history']);
    Route::post('/chat/send', [ChatController::class, 'send']);
    Route::post('/chat/typing', [ChatController::class, 'typing']);
    Route::get('/chat/contacts', [ChatController::class, 'contacts']);


    Route::get('/chat/applicant-history/', [ChatController::class, 'ApplicantChatHistory']);
    Route::post('/chat/applicant-send', [ChatController::class, 'ApplicantChatSend']);
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

Route::middleware(['auth:sanctum'])->group(function () {  
    // Data Privacy update
    Route::patch('/users/privacy/accept', [UserPrivacyController::class, 'acceptPrivacyPolicy']);
    Route::post('/job-application', [UserPrivacyController::class, 'store'])->middleware('verify.recaptcha');
    // Dashboard stats
    Route::get('/applicant-dashboard', [ApplicantDashboardController::class, 'index']);

    // Candidates Notification
    Route::get('/applicant-notifications', [ApplicantNotificationController::class, 'index']);

    // Candidates Examination routes
    Route::get('/applicant/examinations', [ExaminationController::class, 'retrieveAssignedAssessment']);
    Route::post('/examinations/submit-all', [ExaminationController::class, 'submitAll']);
    Route::get('/examinations', [ExaminationController::class, 'index']);
    Route::get('/examinations/{id}', [ExaminationController::class, 'show']);
    Route::post('/examinations', [ExaminationController::class, 'store']);
    Route::post('/examinations/{id}/submit', [ExaminationController::class, 'submit']);
    Route::put('/examinations/{id}', [ExaminationController::class, 'update']);
    Route::delete('/examinations/{id}', [ExaminationController::class, 'destroy']);

    // Candidates File Submit routes
    Route::get('/file-submission', [FileSubmissionController::class, 'index']);
    Route::post('/file-submission', [FileSubmissionController::class, 'store']);
    Route::delete('/file-submission/{id}', [FileSubmissionController::class, 'destroy']);

    //Candidates Settings routes
    Route::post('/settings/profile', [ApplicantSettingsController::class, 'updateProfile']);
    Route::post('/settings/change-email', [ApplicantSettingsController::class, 'changeEmail']);
    Route::post('/settings/change-password', [ApplicantSettingsController::class, 'changePassword']);
    Route::get('/settings/profile', [ApplicantSettingsController::class, 'getProfile']);
    

});


// -------------------------------
// Catch-all route (must be at the end)
// -------------------------------
Route::any('{any}', function () {
    return response()->json([
        'message' => 'API endpoint not found'
    ], 404);
})->where('any', '.*');
