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
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ApproverJobOfferController;
use App\Http\Controllers\ApproverBoardController;
use App\Http\Controllers\ApproverSettingsController;
use App\Http\Controllers\ApplicantPipelineController;
use App\Http\Controllers\PasswordResetController;

// -------------------------------
// Public routes (No CSRF protection needed)
// -------------------------------

Route::post('/verify-credentials', [AuthController::class, 'verifyCredentials']);
Route::post('/verify-otp-login', [AuthController::class, 'verifyOtpAndLogin']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/check-otp-status', [AuthController::class, 'checkOtpStatus']);
Route::post('/logout', [AuthController::class, 'logout']);

// Route::post('/login', [AuthController::class, 'login']);
    // Reset Token
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/validate-reset-token', [PasswordResetController::class, 'validateResetToken']);
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

Route::get('sessions', [AuthController::class, 'getActiveSessions'])->middleware('auth:sanctum');
Route::delete('sessions/{tokenId}', [AuthController::class, 'revokeSession'])->middleware('auth:sanctum');

// Admin routes
Route::post('force-logout/{userId}', [AuthController::class, 'forceLogoutUser'])->middleware('auth:sanctum');
Route::post('cleanup-tokens', [AuthController::class, 'cleanupExpiredTokens'])->middleware('auth:sanctum');

// Route::middleware(['web'])->group(function () {
// });

// -------------------------------
// Protected routes with Sanctum
// -------------------------------

// Broadcasting auth route
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/broadcasting/auth', function (Request $request) {
        if (!auth()->check()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        return Broadcast::auth($request);
    });
});

// Chat routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/chat/history/{applicantId}', [ChatController::class, 'history']);
    Route::post('/chat/send', [ChatController::class, 'send']);
    Route::post('/chat/typing', [ChatController::class, 'typing']);
    Route::get('/chat/contacts', [ChatController::class, 'contacts']);
    Route::get('/chat/typing-status/{applicantId}', [ChatController::class, 'getTypingStatus']);
    Route::get('/chat/applicant-history/', [ChatController::class, 'ApplicantChatHistory']);
    Route::post('/chat/applicant-send', [ChatController::class, 'ApplicantChatSend']);
    Route::get('/chat/notification', [ChatController::class, 'getUnreadCount']);

});

// Authentication check
Route::middleware(['auth:sanctum'])->get('/check-auth', function (Request $request) {
    $userId = $request->user()->id;
    
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

    // Interview routes
    Route::get('/applicants/{applicantId}/interview-summary', [ApplicantPipelineController::class, 'getInterviewSummary']);
    Route::post('/applicants/{id}/interview-summary', [ApplicantPipelineController::class, 'store']);

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
    Route::post('/broadcast-staff-list', [HRStaffController::class, 'broadcastStaffList']);

    // Job offer routes
    Route::get('/job-offers', [JobOfferController::class, 'index']);
    Route::get('/job-offers/chart', [JobOfferController::class, 'chartData']);
    Route::get('/job-offers/{id}', [JobOfferController::class, 'show']);
    Route::post('/job-offers', [JobOfferController::class, 'store']);
    Route::patch('/job-offers/{id}/status', [JobOfferController::class, 'updateStatus']);
    Route::get('/job-offers/{id}/signatures', [RecruitmentBoardController::class, 'getSignatures']);

    // Notification routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'updateReadStatus']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // Notes
    Route::post('/recruitment-notes', [RecruitmentNoteController::class, 'store']);

    // Open positions
    Route::get('/positions', [PositionController::class, 'index']);
    Route::post('/positions', [PositionController::class, 'store']);
    Route::put('/positions/{id}', [PositionController::class, 'update']);
    Route::delete('/positions/{id}', [PositionController::class, 'destroy']);

    // Filter options
    Route::get('/filter/status', [RecruitmentBoardController::class, 'getStatuses']);
    Route::get('/filter/roles', [RecruitmentBoardController::class, 'getRoles']);
});

// -------------------------------
// Applicant routes
// -------------------------------
Route::middleware(['auth:sanctum'])->group(function () {  
    // Data Privacy update
    Route::patch('/users/privacy/accept', [UserPrivacyController::class, 'acceptPrivacyPolicy']);
    Route::post('/job-application', [UserPrivacyController::class, 'store']);
    
    // Dashboard stats
    Route::get('/applicant-dashboard', [ApplicantDashboardController::class, 'index']);
    Route::get('/applicant-job-offers/{id}', [ApplicantDashboardController::class, 'showOffer']);
    Route::post('/applicant-job-offers/signature', [ApplicantDashboardController::class, 'storeSignature']);
    Route::patch('/applicant-job-offers/{id}/status', [ApplicantDashboardController::class, 'updateOfferStatus']);
    Route::get('/applicant-job-offers/{id}/signatures', [ApplicantDashboardController::class, 'getSignatures']);

    // Candidates Notification
    Route::get('/applicant-notifications', [ApplicantNotificationController::class, 'index']);

    // Candidates Examination routes
    Route::get('/applicant/examinations', [ExaminationController::class, 'retrieveAssignedAssessment']);
    Route::post('/examinations/submit-all', [ExaminationController::class, 'submitAll']);
    Route::get('/examinations', [ExaminationController::class, 'index']);
    Route::get('/examinations/{id}', [ExaminationController::class, 'show']);
    Route::put('/examinations/{id}', [ExaminationController::class, 'update']);
    Route::delete('/examinations/{id}', [ExaminationController::class, 'destroy']);
    Route::patch('/examinations/attempt', [ExaminationController::class, 'attempt']);
    Route::patch('/examinations/update-time', [ExaminationController::class, 'saveUsedTime']);

    // Candidates File Submit routes
    Route::get('/file-submission', [FileSubmissionController::class, 'index']);
    Route::post('/file-submission', [FileSubmissionController::class, 'store']);
    Route::delete('/file-submission/{id}', [FileSubmissionController::class, 'destroy']);

    // Candidates Settings routes
    Route::post('/settings/profile', [ApplicantSettingsController::class, 'updateProfile']);
    Route::post('/settings/change-email', [ApplicantSettingsController::class, 'changeEmail']);
    Route::post('/settings/change-password', [ApplicantSettingsController::class, 'changePassword']);
    Route::get('/settings/profile', [ApplicantSettingsController::class, 'getProfile']);
    
    // Approver Job Offer routes
    Route::get('/approver-job-offers', [ApproverJobOfferController::class, 'index']);
    Route::get('/approver-job-offers/{id}', [ApproverJobOfferController::class, 'show']);
    Route::post('/approver-job-offers/signature', [ApproverJobOfferController::class, 'storeSignature']);
    Route::get('/approver-job-offers/{userId}/signature', [ApproverJobOfferController::class, 'getSignature']);
    Route::patch('/approver-job-offers/{id}/status', [ApproverJobOfferController::class, 'updateStatus']);

    // Approver Board routes
    Route::get('/approver-board', [ApproverBoardController::class, 'index']);

    // Approver Settings routes
    Route::post('/approver-settings/change-email', [ApproverSettingsController::class, 'changeEmail']);
    Route::post('/approver-settings/change-password', [ApproverSettingsController::class, 'changePassword']);
});

// -------------------------------
// Catch-all route (must be at the end)
// -------------------------------
Route::any('{any}', function () {
    return response()->json([
        'message' => 'API endpoint not found'
    ], 404);
})->where('any', '.*');