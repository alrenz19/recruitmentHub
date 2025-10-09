<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Mail\PasswordResetMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

use Illuminate\Support\Facades\Log;

class PasswordResetController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        // Check if user exists in your recruitment system
        $user = User::where('user_email', $request->email)->first();

        if (!$user) {
            // Return same response for security (don't reveal if email exists)
            return response()->json([
                'success' => true,
                'message' => 'If an account with that email exists, a reset link has been sent.'
            ]);
        }

        // Generate token
        $token = Str::random(60);
        
        // Store token in password reset tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => Hash::make($token),
                'created_at' => now()
            ]
        );

        // Build reset URL
        $resetUrl = frontend_url("reset-password") . "?token={$token}&email={$request->email}";
        // Get the user's name with proper fallbacks
        $userName = $this->getUserName($user);

        // Send email notification via queue using Mailable
        Mail::to($user->user_email)
            ->queue(new PasswordResetMail(
                $user->user_email,      // email parameter
                $userName,              // name parameter with proper fallback
                $resetUrl               // reset URL parameter
            ));

        return response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a reset link has been sent.'
        ]);
    }


    /**
     * Get user name with proper fallbacks
     */
    private function getUserName(User $user): string
    {
        // Try to get name from relationships with fallbacks
        if ($user->hrStaff && !empty($user->hrStaff->full_name)) {
            return $user->hrStaff->full_name;
        }

        if ($user->applicant && !empty($user->applicant->full_name)) {
            return $user->applicant->full_name;
        }

        // Use the accessor if available
        if (!empty($user->full_name)) {
            return $user->full_name;
        }

        // Final fallback: use email username
        return explode('@', $user->user_email)[0];
    }

    public function validateResetToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email'
        ]);

        $tokenData = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$tokenData || !Hash::check($request->token, $tokenData->token)) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid or expired reset token.'
            ]);
        }

        // Check if token is expired (1 hour)
        if (now()->diffInMinutes($tokenData->created_at) > 60) {
            // Delete expired token
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            
            return response()->json([
                'valid' => false,
                'message' => 'Reset token has expired.'
            ]);
        }

        return response()->json([
            'valid' => true,
            'message' => 'Token is valid.'
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $tokenData = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$tokenData || !Hash::check($request->token, $tokenData->token)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token.'
            ], 422);
        }

        // Check if token is expired
        if (now()->diffInMinutes($tokenData->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            
            return response()->json([
                'success' => false,
                'message' => 'Reset token has expired.'
            ], 422);
        }

        // FIX: Use user_email consistently like in forgotPassword
        $user = User::where('user_email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.'
            ], 422);
        }

        $user->password_hash = Hash::make($request->password);
        $user->save();

        // Delete the used token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully.'
        ]);
    }
}