<?php
use App\Http\Controllers\RequestController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;

// Public route
Route::post('/login', [AuthController::class, 'login']);

// Protected routes (any authenticated user)
Route::middleware(['auth:sanctum', 'verify.api'])->group(function () {
    Route::post('/submit', [RequestController::class, 'submit']);
    Route::get('/data', [RequestController::class, 'getData']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', function(Request $request) {
        return $request->user();
    });

        
    Route::get('/check-auth', function(Request $request) {
        return response()->json([
            'id' => $request->user()->id,
            'role_id' => $request->user()->role_id,
            'full_name' => $request->user()->full_name,
        ]);
    });

});

// Admin-only routes
Route::middleware(['auth:sanctum', 'role:Admin'])->group(function () {
    Route::get('/admin/dashboard', function () {
        return ['message' => 'Welcome Admin!'];
    });

    Route::post('/admin/create-user', [AuthController::class, 'createUser']);
});
