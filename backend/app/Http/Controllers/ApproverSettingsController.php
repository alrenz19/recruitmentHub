<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class ApproverSettingsController extends Controller
{
    /**
     * Change email (users table).
     */
    public function changeEmail(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'new_email' => 'required|email|unique:users,user_email,' . $user->id,
        ]);

        $user->user_email = $request->new_email;
        $user->save();

        return response()->json(['message' => 'Email updated successfully']);
    }

    /**
     * Change password (users table).
     */
    public function changePassword(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password_hash)) {
            return response()->json(['error' => 'Current password is incorrect'], 400);
        }

        $user->password_hash = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Password updated successfully']);
    }
}
