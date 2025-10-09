<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\ApplicantSettings;
use App\Models\ApplicantFiles;
use App\Models\User;

class ApplicantSettingsController extends Controller
{

    /**
 * Return applicant profile data including resume url and profile picture url.
 */
public function getProfile()
{
    $user = Auth::user();
    $applicant = $user->applicant;

    if (!$applicant) {
        return response()->json(['error' => 'Applicant not found'], 404);
    }

    // Find resume row (if any)
    $resume = ApplicantFiles::where('applicant_id', $applicant->id)
        ->where('file_name', 'Resume')
        ->where('removed', 0)
        ->first();

    // Build public URLs (assumes you used the "public" disk when storing)
    $profilePicUrl = $applicant->profile_picture ? asset('storage/' . $applicant->profile_picture) : null;
    $resumeUrl = $resume ? asset('storage/' . $resume->file_path) : null;

    return response()->json([
        'full_name'       => $applicant->full_name,
        'civil_status'    => $applicant->civil_status,
        'user_email'      => $user->user_email,
        'phone'           => $applicant->phone,
        'present_address' => $applicant->present_address,
        'profile_picture' => $profilePicUrl,
        'resume_url'      => $resumeUrl,
    ]);
}



    /**
     * Update profile info (applicants table + resume + profile picture).
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        $applicant = $user->applicant; // assumes User hasOne Applicant relation

        $request->validate([
            'full_name'       => 'required|string|max:191',
            'civil_status'    => 'nullable|string|max:50',
            'email'           => 'required|email|max:191',
            'phone'           => 'nullable|string|max:50',
            'present_address' => 'nullable|string|max:255',
            'resume'          => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
            'profile_picture' => 'nullable|file|mimes:jpg,jpeg,png|max:10240'
        ]);

        // Update applicant info
        $applicant->update([
            'full_name'       => $request->full_name,
            'civil_status'    => $request->civil_status,
            'email'           => $request->email,
            'phone'           => $request->phone,
            'present_address' => $request->present_address,
        ]);

        // Profile picture upload
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');
            $filePath = $file->store("profile_pictures", 'public');

            $applicant->update([
                'profile_picture' => $filePath
            ]);
        }

        // Resume upload (update existing row if exists, else create new)
        if ($request->hasFile('resume')) {
            $file = $request->file('resume');
            $filePath = $file->store("resumes", 'public');

            ApplicantFiles::updateOrCreate(
                [
                    'applicant_id' => $applicant->id,
                    'file_name'    => 'resume',
                ],
                [
                    'file_path'    => $filePath,
                    'file_type'    => $file->getClientMimeType(),
                    'status'       => 'approved',
                    'removed'      => 0,
                ]
            );
        }

        // Log profile update
        SecurityLoggerService::log('profile_updated', "User profile updated", [
            'user_id' => $user->id,
            'user_email' => $user->user_email,
            'updated_fields' => array_keys($updateData)
        ]);


        return response()->json(['message' => 'Profile updated successfully']);
    }

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
        
        SecurityLoggerService::log('password_changed', "User changed password", [
            'user_id' => $user->id,
            'user_email' => $user->user_email,
        ]);


        return response()->json(['message' => 'Password updated successfully']);
    }
}
