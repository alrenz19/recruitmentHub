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
            'desired_salary' => 'required|string|max:50',
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
            'resume' => 'nullable|string', 
            'tor' => 'nullable|string', 
            'coe' => 'nullable|string', 
            'signature' => 'nullable|string', 
            'licensure_exam' => 'nullable|string',
            'license_no' => 'nullable|string',
            'extra_curricular' => 'nullable|string',
        ];

        $validated = $request->validate($rules);

        DB::beginTransaction();
        try {
            // 1️⃣ Handle Job Source (raw SQL)
            DB::statement("
                INSERT INTO job_information_source (name, removed, created_at, updated_at)
                VALUES (?, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ", [$validated['job_sources']]);

            $jobInfoId = DB::table('job_information_source')
                ->where('name', $validated['job_sources'])
                ->where('removed', 0)
                ->value('id');

            // 2️⃣ Save profile picture and signature first
            $applicant = DB::table('applicants')->where('user_id', $userId)->first();

            $profilePicturePath = !empty($validated['profile_picture'])
                ? $this->saveProfilePicture(optional($applicant)->id ?? 0, $validated['profile_picture'])
                : optional($applicant)->profile_picture;

            $signaturePath = !empty($validated['signature'])
                ? $this->saveBase64Image($validated['signature'], 'signatures')
                : optional($applicant)->signature;

            // 3️⃣ Insert/Update applicant (raw SQL)
            DB::statement("
                INSERT INTO applicants
                (user_id, full_name, email, phone, profile_picture, birth_date, place_of_birth, civil_status,
                position_desired, present_address, provincial_address, religion, nationality, age, sex,
                desired_salary, start_asap, job_info_id, signature, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    email = VALUES(email),
                    phone = VALUES(phone),
                    profile_picture = VALUES(profile_picture),
                    birth_date = VALUES(birth_date),
                    place_of_birth = VALUES(place_of_birth),
                    civil_status = VALUES(civil_status),
                    position_desired = VALUES(position_desired),
                    present_address = VALUES(present_address),
                    provincial_address = VALUES(provincial_address),
                    religion = VALUES(religion),
                    nationality = VALUES(nationality),
                    age = VALUES(age),
                    sex = VALUES(sex),
                    desired_salary = VALUES(desired_salary),
                    start_asap = VALUES(start_asap),
                    job_info_id = VALUES(job_info_id),
                    signature = VALUES(signature),
                    updated_at = NOW()
            ", [
                $userId,
                $validated['full_name'],
                $validated['email'],
                $validated['phone'],
                $profilePicturePath,
                $validated['birth_date'],
                $validated['place_of_birth'],
                $validated['civil_status'],
                $validated['position_desired'],
                $validated['present_address'],
                $validated['provincial_address'],
                $validated['religion'] ?? null,
                $validated['nationality'],
                $validated['age'],
                $validated['gender'],
                $validated['desired_salary'],
                $validated['start_asap'],
                $jobInfoId,
                $signaturePath,
            ]);

            // 4️⃣ Get applicant ID
            $applicantId = DB::table('applicants')
                ->where('user_id', $userId)
                ->value('id');

            // 5️⃣ Save files into applicant_files
            foreach (['resume' => 'resumes', 'tor' => 'tors', 'coe' => 'coes'] as $fileType => $folder) {
                if (!empty($validated[$fileType])) {
                    $this->saveApplicantFile($applicantId, $validated[$fileType], $fileType, $folder);
                }
            }

            // 6️⃣ Clear related data
            foreach (['emergency_contact','family_background','educational_background','employment_history','additional_information','applicant_achievements'] as $table) {
                DB::table($table)->where('applicant_id', $applicantId)->delete();
            }

            // 7️⃣ Emergency Contact
            DB::statement("
                INSERT INTO emergency_contact (applicant_id, fname, contact, address, relationship, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $applicantId,
                $validated['emergency_contact']['full_name'] ?? null,
                $validated['emergency_contact']['contact_no'] ?? null,
                $validated['emergency_contact']['present_address'] ?? null,
                $validated['emergency_contact']['relationship'] ?? null,
            ]);

            // 8️⃣ Family Background (batch insert)
            if (!empty($validated['family_background'])) {
                $values = [];
                $bindings = [];
                foreach ($validated['family_background'] as $row) {
                    $values[] = "(?, ?, ?, ?, ?, NOW(), NOW())";
                    $bindings[] = $applicantId;
                    $bindings[] = $row['name'] ?? null;
                    $bindings[] = $row['date_of_birth'] ?? null;
                    $bindings[] = $row['age'] ?? null;
                    $bindings[] = $row['relationship'] ?? null;
                }
                DB::insert("
                    INSERT INTO family_background (applicant_id, fname, date_birth, age, relationship, created_at, updated_at)
                    VALUES " . implode(", ", $values), $bindings
                );
            }

            // 9️⃣ Education Background (batch insert)
            if (!empty($validated['education_background'])) {
                $values = [];
                $bindings = [];
                foreach ($validated['education_background'] as $level => $row) {
                    // get academic level ID
                    DB::statement("
                        INSERT INTO academic_level (name, created_at, updated_at)
                        VALUES (?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = NOW()
                    ", [$levelName = ucfirst(str_replace('_',' ',$level))]);
                    $academicLevelId = DB::getPdo()->lastInsertId();

                    $values[] = "(?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $bindings[] = $applicantId;
                    $bindings[] = $academicLevelId;
                    $bindings[] = $row['name_of_school'] ?? null;
                    $bindings[] = $row['from_year'] ?? null;
                    $bindings[] = $row['to_year'] ?? null;
                    $bindings[] = $row['degree_major'] ?? null;
                    $bindings[] = $row['award'] ?? null;
                }
                DB::insert("
                    INSERT INTO educational_background (applicant_id, academic_level_id, name_of_school, from_date, to_date, degree_major, award, created_at, updated_at)
                    VALUES ".implode(", ", $values), $bindings
                );
            }

            // 10️⃣ Employment History (batch insert)
            if (!empty($validated['employment_history'])) {
                $values = [];
                $bindings = [];
                foreach ($validated['employment_history'] as $row) {
                    $values[] = "(?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $bindings[] = $applicantId;
                    $bindings[] = $row['employer'] ?? null;
                    $bindings[] = $row['last_position'] ?? null;
                    $bindings[] = $row['from_date'] ?? null;
                    $bindings[] = $row['to_date'] ?? null;
                    $bindings[] = $row['salary'] ?? null;
                    $bindings[] = $row['benefits'] ?? null;
                }
                DB::insert("
                    INSERT INTO employment_history (applicant_id, employer, last_position, from_date, to_date, salary, benefits, created_at, updated_at)
                    VALUES ".implode(", ", $values), $bindings
                );
            }

            // 11️⃣ Additional Information (batch insert)
            if (!empty($validated['additional_information'])) {
                $values = [];
                $bindings = [];
                foreach ($validated['additional_information'] as $row) {
                    $values[] = "(?, ?, ?, NOW(), NOW())";
                    $bindings[] = $applicantId;
                    $bindings[] = $row['title'] ?? null;
                    $bindings[] = $row['description'] ?? null;
                }
                DB::insert("
                    INSERT INTO additional_information (applicant_id, title, description, created_at, updated_at)
                    VALUES ".implode(", ", $values), $bindings
                );
            }

            // 12️⃣ Achievements / Licenses
            DB::statement("
                INSERT INTO applicant_achievements (applicant_id, licensure_exam, license_no, extra_curricular, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ", [
                $applicantId,
                $validated['licensure_exam'] ?? '',
                $validated['license_no'] ?? '',
                $validated['extra_curricular'] ?? ''
            ]);

            // 13️⃣ Accept Privacy Policy
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


    /**
     * Save applicant file (resume, tor, coe) into applicant_files table.
     */
    protected function saveApplicantFile(int $applicantId, string $base64File, string $fileType, string $folder): ?string
    {
        $existingFile = DB::table('applicant_files')
            ->where('applicant_id', $applicantId)
            ->where('file_type', $fileType)
            ->where('removed', 0)
            ->first();

        // Delete old file if exists
        if ($existingFile && $existingFile->file_path && Storage::disk('public')->exists($existingFile->file_path)) {
            Storage::disk('public')->delete($existingFile->file_path);
        }

        // Save new file (organized per folder: resumes, tors, coes, etc.)
        $newPath = $this->saveBase64File($base64File, $folder);

        // Upsert into DB
        DB::table('applicant_files')->updateOrInsert(
            [
                'applicant_id' => $applicantId,
                'file_type'    => $fileType,
                'removed'      => 0,
            ],
            [
                'file_name'   => $fileType,
                'file_path'   => $newPath,
                'status'      => 'approved',
                'uploaded_at' => now(),
                'removed'     => 0,
            ]
        );

        return $newPath;
    }



    protected function saveBase64File(string $base64File, string $directory, array $allowedExtensions = ['png', 'jpg', 'jpeg', 'pdf', 'doc', 'docx']): string
    {
        if (!preg_match('/^data:([a-zA-Z0-9]+\/[a-zA-Z0-9-.+]+);base64,(.*)$/', $base64File, $matches)) {
            throw new \Exception("Invalid base64 file format");
        }

        $mimeType = $matches[1];
        $data = base64_decode($matches[2], true);
        if ($data === false) throw new \Exception("Invalid base64 data");

        if (strlen($data) > 5242880) throw new \Exception("File size too large");

        $extension = $this->mimeToExtension($mimeType);
        if (!in_array($extension, $allowedExtensions)) throw new \Exception("File type not allowed: $extension");

        if (in_array($extension, ['png', 'jpg', 'jpeg'])) {
            $imageInfo = getimagesizefromstring($data);
            if ($imageInfo === false) throw new \Exception("Invalid image file");

            $allowedImageTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
            if (!in_array($imageInfo[2], $allowedImageTypes)) throw new \Exception("Invalid image type");
        }

        // Sanitize directory & ensure it exists
        $directory = preg_replace('/[^a-zA-Z0-9_\-]/', '', $directory);
        Storage::disk('public')->makeDirectory($directory);

        $filename = time() . '_' . \Illuminate\Support\Str::random(12) . '.' . $extension;
        $path = $directory . '/' . $filename;

        Storage::disk('public')->put($path, $data);

        return $path;
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

}