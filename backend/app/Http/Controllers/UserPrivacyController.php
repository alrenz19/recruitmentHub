<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class UserPrivacyController extends Controller
{
    /**
     * Update the current user's accept_privacy_policy to 1
     */
    public function acceptPrivacyPolicy(Request $request)
    {
        $userId = Auth::id(); // get currently logged-in user ID

        if (!$userId) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        // Use raw SQL to update
        $updated = DB::update('UPDATE users SET accept_privacy_policy = 1, updated_at = NOW() WHERE id = ?', [$userId]);

        if ($updated) {
            return response()->json([
                'message' => 'Privacy policy accepted successfully'
            ]);
        } else {
            return response()->json([
                'message' => 'Failed to update privacy policy'
            ], 500);
        }
    }
}
