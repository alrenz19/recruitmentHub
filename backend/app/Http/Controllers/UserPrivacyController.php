<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

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

     public function store(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $rules = [
            'position_desired' => 'required|string|max:100',
            'full_name' => 'required|string|max:255',
            'birth_date' => 'required|date',
            'place_of_birth' => 'required|string|max:100',
            'civil_status' => 'required|string|max:50',
            'present_address' => 'required|string|max:100',
            'provincial_address' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:50',
            'age' => 'required|string|max:50',
            'gender' => 'required|string|max:50',
            'start_asap' => 'required|string|max:50',
            'religion' => 'nullable|string|max:100',
            'nationality' => 'required|string|max:100',
            'job_sources' => 'required|string',
            'emergency_contact' => 'required|array',
            'family_background' => 'required|array',
            'education_background' => 'required|array',
            'employment_history' => 'required|array',
            'additional_information' => 'required|array',
            'profile_picture' => 'nullable|string', // base64
            'resume' => 'nullable|string',          // base64
            'signature' => 'nullable|string',       // base64
            'licensure_exam' => 'nullable|string',
            'license_no' => 'nullable|string',
            'extra_curricular' => 'nullable|string',
        ];

        $validated = $request->validate($rules);

        DB::beginTransaction();
        try {
            // Handle job_sources
            $jobSource = $validated['job_sources'];
            $jobInfoId = DB::table('job_information_source')->updateOrInsert(
                ['name' => $jobSource, 'removed' => 0],
                ['updated_at' => now(), 'created_at' => now()]
            );

            $jobInfoId = DB::table('job_information_source')
                ->where('name', $jobSource)
                ->where('removed', 0)
                ->value('id');

            $applicant = DB::table('applicants')
                ->where('user_id', $userId)
                ->first();

            // Insert or update applicant record
            DB::table('applicants')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'full_name'        => $validated['full_name'],
                    'email'            => $validated['email'],
                    'phone'            => $validated['phone'],
                    'profile_picture'  => $profilePicturePath ?? optional($applicant)->profile_picture,
                    'birth_date'       => $validated['birth_date'],
                    'place_of_birth'   => $validated['place_of_birth'],
                    'civil_status'     => $validated['civil_status'],
                    'position_desired' => $validated['position_desired'],
                    'present_address'  => $validated['present_address'],
                    'provincial_address'=> $validated['provincial_address'],
                    'religion'         => $validated['religion'] ?? null,
                    'nationality'      => $validated['nationality'],
                    'age'              => $validated['age'],
                    'sex'           => $validated['gender'],
                    'start_asap'       => $validated['start_asap'],
                    'job_info_id'      => $jobInfoId,
                    'signature'        => $signaturePath ?? optional($applicant)->signature,
                    'updated_at'       => now(),
                    'created_at'       => optional($applicant)->created_at ?? now(),
                ]
            );

            // Get applicant ID safely
            $applicantId = DB::table('applicants')
                ->where('user_id', $userId)
                ->value('id');

            // Save base64 files
            $profilePicturePath = !empty($validated['profile_picture'])
                ? $this->saveProfilePicture($applicantId, $validated['profile_picture'])
                : optional($applicant)->profile_picture;

            $signaturePath = !empty($validated['signature'])
                ? $this->saveBase64Image($validated['signature'], 'signatures')
                : null;


            // Save resume into applicant_files (upsert)
            if (!empty($validated['resume'])) {
                $resumePath = $this->saveResumeFile($applicantId, $validated['resume'], 'resume');

                DB::table('applicant_files')->updateOrInsert(
                    [
                        'applicant_id' => $applicantId, // condition → check if this applicant already has a resume
                        'file_type'    => 'resume',
                        'removed'      => 0,
                    ],
                    [
                        'file_name'    => 'resume',
                        'file_path'    => $resumePath,
                        'status'       => 'approved',
                        'uploaded_at'  => now(),
                        'removed'   => 0,
                    ]
                );
            }

            // Clear related data
            DB::table('emergency_contact')->where('applicant_id', $applicantId)->delete();
            DB::table('family_background')->where('applicant_id', $applicantId)->delete();
            DB::table('educational_background')->where('applicant_id', $applicantId)->delete();
            DB::table('employment_history')->where('applicant_id', $applicantId)->delete();
            DB::table('additional_information')->where('applicant_id', $applicantId)->delete();
            DB::table('applicant_achievements')->where('applicant_id', $applicantId)->delete();

            // Insert related tables
            $this->storeEmergencyContact($applicantId, $validated['emergency_contact']);
            $this->storeFamilyBackground($applicantId, $validated['family_background']);
            $this->storeEducationBackground($applicantId, $validated['education_background']);
            $this->storeEmploymentHistory($applicantId, $validated['employment_history']);
            $this->storeAdditionalInformation($applicantId, $validated['additional_information']);

            $this->storeAchievements($applicantId, [
                'licensure_exam'   => $validated['licensure_exam'] ?? '',
                'license_no'       => $validated['license_no'] ?? '',
                'extra_curricular' => $validated['extra_curricular'] ?? '',
            ]);
            
            DB::update('UPDATE users SET accept_privacy_policy = 1, updated_at = NOW() WHERE id = ?', [$userId]);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Application submitted successfully',
                'applicant_id' => $applicantId,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Job application error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit application',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /* ------------ File Helpers ------------ */

    protected function saveProfilePicture(int $applicantId, string $base64File): ?string
    {
        $applicant = DB::table('applicants')->where('id', $applicantId)->first();

        // delete existing profile picture if it exists
        if ($applicant && $applicant->profile_picture && Storage::disk('public')->exists($applicant->profile_picture)) {
            Storage::disk('public')->delete($applicant->profile_picture);
        }

        // save new file
        return $this->saveBase64File($base64File, 'profile_pictures');
    }

    protected function saveResumeFile(int $applicantId, string $base64File): ?string
    {
        $existingFile = DB::table('applicant_files')
            ->where('applicant_id', $applicantId)
            ->where('file_type', 'resume')
            ->where('removed', 0)
            ->first();

        // delete old file if it exists
        if ($existingFile && $existingFile->file_path && Storage::disk('public')->exists($existingFile->file_path)) {
            Storage::disk('public')->delete($existingFile->file_path);
        }

        // save new file into /resumes
        $newPath = $this->saveBase64File($base64File, 'resumes');

        return $newPath;
    }


    protected function saveBase64File(string $base64File, string $directory, array $allowedExtensions = ['png', 'jpg', 'jpeg', 'pdf', 'doc', 'docx']): ?string
    {
        if (preg_match('/^data:(.*?);base64,(.*)$/', $base64File, $matches)) {
            $mimeType = $matches[1];
            $data = base64_decode($matches[2]);

            if ($data === false) {
                throw new \Exception("Invalid base64 data");
            }

            $extension = $this->mimeToExtension($mimeType);
            if (!in_array($extension, $allowedExtensions)) {
                throw new \Exception("File type not allowed: $extension");
            }

            $filename = uniqid() . '.' . $extension;
            $path = $directory . '/' . $filename;

            Storage::disk('public')->put($path, $data);

            return $path;
        }
        return null;
    }

    protected function saveBase64Image(string $base64Image, string $directory): ?string
    {
        return $this->saveBase64File($base64Image, $directory, ['png', 'jpg', 'jpeg']);
    }

    private function mimeToExtension(string $mimeType): string
    {
        $map = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        return $map[$mimeType] ?? 'bin';
    }

    /* ------------ Related Records ------------ */

    private function storeEmergencyContact(int $applicantId, array $data)
    {
        DB::table('emergency_contact')->insert([
            'applicant_id' => $applicantId,
            'fname'        => $data['full_name'] ?? null,
            'contact'      => $data['contact_no'] ?? null,
            'address'      => $data['present_address'] ?? null,
            'relationship' => $data['relationship'] ?? null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function storeFamilyBackground(int $applicantId, array $data)
    {
        foreach ($data as $row) {
            DB::table('family_background')->insert([
                'applicant_id' => $applicantId,
                'fname'        => $row['name'] ?? null,
                'date_birth'   => $row['date_of_birth'] ?? null,
                'age'          => $row['age'] ?? null,
                'relationship' => $row['relationship'] ?? null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    private function storeEducationBackground(int $applicantId, array $data)
    {
        foreach ($data as $level => $row) {
            $academicLevelId = $this->getAcademicLevelId($level);

            DB::table('educational_background')->insert([
                'applicant_id'      => $applicantId,
                'academic_level_id' => $academicLevelId,
                'name_of_school'    => $row['name_of_school'] ?? null,
                'from_date'         => $row['from_year'] ?? null,
                'to_date'           => $row['to_year'] ?? null,
                'degree_major'      => $row['degree_major'] ?? null,
                'award'             => $row['award'] ?? null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    private function storeAchievements(int $applicantId, array $data)
    {
        // Split by comma
        $licensureExams = isset($data['licensure_exam']) ? array_map('trim', explode(',', $data['licensure_exam'])) : [];
        $licenseNos     = isset($data['license_no']) ? array_map('trim', explode(',', $data['license_no'])) : [];
        $extraCurr      = isset($data['extra_curricular']) ? array_map('trim', explode(',', $data['extra_curricular'])) : [];

        // Determine max count
        $count = max(count($licensureExams), count($licenseNos), count($extraCurr));

        for ($i = 0; $i < $count; $i++) {
            DB::table('applicant_achievements')->insert([
                'applicant_id'    => $applicantId,
                'licensure_exam'  => $licensureExams[$i] ?? null,
                'license_no'      => $licenseNos[$i] ?? null,
                'extra_curricular'=> $extraCurr[$i] ?? null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    private function storeEmploymentHistory(int $applicantId, array $data)
    {
        foreach ($data as $row) {
            DB::table('employment_history')->insert([
                'applicant_id' => $applicantId,
                'employer'     => $row['employer'] ?? null,
                'last_position'=> $row['last_position'] ?? null,
                'from_date'    => $row['from_date'] ?? null,
                'to_date'      => $row['to_date'] ?? null,
                'salary'       => $row['salary'] ?? null,
                'benefits'     => $row['benefits'] ?? null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }


    private function storeAdditionalInformation(int $applicantId, array $data)
    {
        foreach ($data as $question => $answer) {
            // detect if this field has a reason counterpart
            $reasonKey = $question . '_reason';
            $reason = $data[$reasonKey] ?? null;

            // skip reason-only keys so we don't insert them separately
            if (str_ends_with($question, '_reason')) {
                continue;
            }

            DB::table('additional_information')->insert([
                'applicant_id' => $applicantId,
                'question'     => $question,
                'answer'       => $answer,
                'reason'       => $reason,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    private function getAcademicLevelId($level)
    {
        $levelMap = [
            'elementary_school' => 'Elementary',
            'high_school' => 'High School',
            'senior_high' => 'Senior High School',
            'vocational_school' => 'Vocational',
            'college_school' => 'College',
            'graduate_school' => 'Graduate School'
        ];

        $levelName = $levelMap[$level] ?? ucfirst(str_replace('_', ' ', $level));

        $academicLevel = DB::table('academic_level')
            ->where('name', 'like', '%' . $levelName . '%')
            ->where('removed', 0)
            ->first();

        if ($academicLevel) {
            return $academicLevel->id;
        }

        return DB::table('academic_level')->insertGetId([
            'name' => $levelName,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}