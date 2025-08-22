<?php
use App\Http\Controllers\RequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;

// Public route
Route::post('/login', [AuthController::class, 'login'])->middleware('verify.recaptcha');

// Protected routes (any authenticated user)
Route::middleware(['auth:sanctum', 'verify.api'])->group(function () {

    $user = request()->user(); // store the authenticated user

    Route::get('/hr-dashboard/stats', function() use ($user) {
        // Pass $user to controller if needed
        return app(DashboardController::class)->getStats($user);
    })->name('hr-dashboard.stats');

    Route::get('/profile', function() use ($user) {
        return $user;
    });

    Route::get('/check-auth', function() use ($user) {
        return response()->json([
            'id' => $user->id,
            'role_id' => $user->role_id,
            'full_name' => $user->full_name,
        ]);
    });
});


// Admin-only routes
Route::middleware(['auth:sanctum', 'role:Admin'])->group(function () {
    Route::get('/admin/dashboard', function () {
        return ['message' => 'Welcome Admin!'];
    });

    Route::post('/admin/create-user', [AuthController::class, 'createUser'])->middleware('verify.api');
});

