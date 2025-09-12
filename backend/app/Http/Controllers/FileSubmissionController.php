<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApplicantFiles;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\NotificationService;

class FileSubmissionController extends Controller
{
    /**
     * List files of logged-in applicant.
     */
    public function index()
    {
        $applicantId = Auth::user()->applicant->id ?? null;
        if (!$applicantId) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $files = ApplicantFiles::where('applicant_id', $applicantId)
            ->where('removed', 0)
            ->get();

        return response()->json($files);
    }

    /**
     * Upload a file.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
            'file_name' => 'required|string|max:255',
        ]);

        $userId = Auth::id();
        $applicant = DB::table('applicants')->where('user_id', $userId)->first();
        $applicantId = $applicant->id ?? null;

        if (!$applicantId) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $file = $request->file('file');
        $filePath = $file->store("applicant_files/{$applicantId}", 'public');

        $appFile = ApplicantFiles::create([
            'applicant_id' => $applicantId,
            'file_name' => $request->file_name,
            'file_path' => $filePath,
            'file_type' => $file->getClientMimeType(),
            'status' => 'pending',
            'removed' => 0,
        ]);

         NotificationService::send(null, "Attachment Uploaded", "{$applicant->full_name} has uploaded a file.", 'file', '/recruitment-board', 'hr');

        return response()->json([
            'message' => 'File uploaded successfully',
            'file' => $appFile
        ], 201);
    }

    /**
     * Delete a file (soft delete).
     */
    public function destroy($id)
    {
        $file = ApplicantFiles::find($id);

        if (!$file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $file->removed = 1;
        $file->save();

        return response()->json(['message' => 'File removed successfully']);
    }
}
