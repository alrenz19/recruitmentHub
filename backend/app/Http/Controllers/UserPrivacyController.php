<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $userId = Auth::id(); // get currently logged-in user ID

        if (!$userId) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        // Validate the request (reCAPTCHA is handled by middleware)
        $validator = Validator::make($request->all(), [
            'position_desired' => 'required|string|max:100',
            'full_name' => 'required|string|max:255',
            'birth_date' => 'required|date',
            'place_of_birth' => 'required|string|max:100',
            'civil_status' => 'required|string|max:50',
            'present_address' => 'required|string|max:100',
            'provincial_address' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:50',
            'religion' => 'nullable|string|max:100',
            'nationality' => 'required|string|max:100',
            'job_sources' => 'required|string',
            'emergency_contact' => 'required|string',
            'family_background' => 'required|string',
            'education_background' => 'required|string',
            'employment_history' => 'required|string',
            'additional_information' => 'required|string',
            'signature' => 'nullable|string',
            'profile_picture' => 'nullable|string',
            'licensure_exam' => 'nullable|string',
            'license_no' => 'nullable|string',
            'extra_curricular' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $data = $request->all();
            
            // Check if applicant already exists
            $existingApplicant = DB::table('applicants')
                ->where('user_id', $userId)
                ->first();

            // Decode JSON strings from the payload
            $emergencyContact = json_decode($data['emergency_contact'], true);
            $familyBackground = json_decode($data['family_background'], true);
            $educationBackground = json_decode($data['education_background'], true);
            $employmentHistory = json_decode($data['employment_history'], true);
            $additionalInformation = json_decode($data['additional_information'], true);
            
            // 1. First, save job information sources and get their IDs

            $jobSource = $data['job_sources']; // already a string

            $existingSource = DB::table('job_information_source')
                ->where('name', $jobSource)
                ->where('removed', 0)
                ->first();

            if ($existingSource) {
                $jobInfoId = $existingSource->id;
            } else {
                $jobInfoId = DB::table('job_information_source')->insertGetId([
                    'name' => $jobSource,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Use the first job info ID for the applicant
            $jobInfoId = !empty($jobInfoIds) ? $jobInfoIds[0] : null;
            
            // Handle profile picture if provided
            $profilePicturePath = null;
            if (!empty($data['profile_picture'])) {
                $profilePicturePath = $this->saveBase64Image($data['profile_picture'], 'profile_pictures');
            } elseif ($existingApplicant && $existingApplicant->profile_picture) {
                $profilePicturePath = $existingApplicant->profile_picture;
            }
            
            // Handle signature if provided
            $signaturePath = null;
            if (!empty($data['signature'])) {
                $signaturePath = $this->saveBase64Image($data['signature'], 'signatures');
            } elseif ($existingApplicant && $existingApplicant->signature) {
                $signaturePath = $existingApplicant->signature;
            }
            
            // 2. Save or update the main applicant information
            $applicantData = [
                'user_id' => $userId,
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'profile_picture' => $profilePicturePath,
                'birth_date' => $data['birth_date'],
                'place_of_birth' => $data['place_of_birth'],
                'civil_status' => $data['civil_status'],
                'position_desired' => $data['position_desired'],
                'present_address' => $data['present_address'],
                'provincial_address' => $data['provincial_address'],
                'religion' => $data['religion'] ?? null,
                'nationality' => $data['nationality'],
                'job_info_id' => $jobInfoId,
                'signature' => $signaturePath,
                'licensure_exam' => $data['licensure_exam'] ?? null,
                'license_no' => $data['license_no'] ?? null,
                'extra_curricular' => $data['extra_curricular'] ?? null,
                'updated_at' => now()
            ];

            if ($existingApplicant) {
                // Update existing applicant
                $applicantId = $existingApplicant->id;
                DB::table('applicants')
                    ->where('id', $applicantId)
                    ->update($applicantData);
            } else {
                // Create new applicant
                $applicantData['created_at'] = now();
                $applicantId = DB::table('applicants')->insertGetId($applicantData);
            }
            
            // 3. Delete existing related records before inserting new ones
            DB::table('emergency_contact')->where('applicant_id', $applicantId)->delete();
            DB::table('family_background')->where('applicant_id', $applicantId)->delete();
            DB::table('educational_background')->where('applicant_id', $applicantId)->delete();
            DB::table('employment_history')->where('applicant_id', $applicantId)->delete();
            DB::table('additional_information')->where('applicant_id', $applicantId)->delete();
            
            // 4. Save emergency contact
            if (!empty($emergencyContact) && is_array($emergencyContact)) {
                DB::table('emergency_contact')->insert([
                    'applicant_id' => $applicantId,
                    'fname' => $emergencyContact['full_name'] ?? null,
                    'contact' => $emergencyContact['contact_no'] ?? null,
                    'address' => $emergencyContact['present_address'] ?? null,
                    'relationship' => $emergencyContact['relationship'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            
            // 5. Save family background
            if (!empty($familyBackground) && is_array($familyBackground)) {
                foreach ($familyBackground as $familyMember) {
                    if (!empty($familyMember['name'])) {
                        DB::table('family_background')->insert([
                            'applicant_id' => $applicantId,
                            'fname' => $familyMember['name'],
                            'date_birth' => $familyMember['date_of_birth'] ?? null,
                            'age' => $familyMember['age'] ?? null,
                            'relationship' => $familyMember['relationship'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                }
            }
            
            // 6. Save educational background
            if (!empty($educationBackground) && is_array($educationBackground)) {
                foreach ($educationBackground as $level => $education) {
                    if (!empty($education['name_of_school'])) {
                        // Get academic level ID based on the level key
                        $academicLevelId = $this->getAcademicLevelId($level);
                        
                        DB::table('educational_background')->insert([
                            'applicant_id' => $applicantId,
                            'academic_level_id' => $academicLevelId,
                            'name_of_school' => $education['name_of_school'],
                            'from_date' => $education['from_year'] ? $education['from_year'] . '-01-01 00:00:00' : null,
                            'to_date' => $education['to_year'] ? $education['to_year'] . '-01-01 00:00:00' : null,
                            'degree_major' => $education['degree_major'] ?? null,
                            'award' => $education['award'] ?? null,
                            'licensure_exam' => $data['licensure_exam'] ?? null,
                            'license_no' => $data['license_no'] ?? null,
                            'extra_curricular' => $data['extra_curricular'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                }
            }
            
            // 7. Save employment history
            if (!empty($employmentHistory) && is_array($employmentHistory)) {
                foreach ($employmentHistory as $employment) {
                    if (!empty($employment['employer'])) {
                        DB::table('employment_history')->insert([
                            'applicant_id' => $applicantId,
                            'employer' => $employment['employer'],
                            'last_position' => $employment['last_position'] ?? null,
                            'from_date' => $employment['from_date'] ?? null,
                            'to_date' => $employment['to_date'] ?? null,
                            'salary' => $employment['salary'] ?? null,
                            'benefits' => $employment['benefits'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                }
            }
            
            // 8. Save additional information
            if (!empty($additionalInformation) && is_array($additionalInformation)) {
                foreach ($additionalInformation as $question => $answer) {
                    DB::table('additional_information')->insert([
                        'applicant_id' => $applicantId,
                        'question' => $question,
                        'answer' => is_array($answer) ? json_encode($answer) : $answer,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
            
            DB::update('UPDATE users SET accept_privacy_policy = 1, updated_at = NOW() WHERE id = ?', [$userId]);

            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => $existingApplicant ? 'Application updated successfully' : 'Application submitted successfully',
                'applicant_id' => $applicantId
            ], $existingApplicant ? 200 : 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Job application error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit application',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Save base64 encoded image to storage
     */
    private function saveBase64Image($base64Image, $folder)
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $type)) {
            $image = substr($base64Image, strpos($base64Image, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, gif
            
            if (!in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) {
                throw new \Exception('Invalid image type');
            }
            
            $image = str_replace(' ', '+', $image);
            $image = base64_decode($image);
            
            if ($image === false) {
                throw new \Exception('Base64 decode failed');
            }
        } else {
            throw new \Exception('Invalid base64 image format');
        }
        
        $fileName = Str::random(20) . '.' . $type;
        $filePath = $folder . '/' . $fileName;
        
        // Save the file to storage
        \Storage::disk('public')->put($filePath, $image);
        
        return $filePath;
    }
    
    /**
     * Map education level to academic level ID
     */
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
        
        // Try to find the academic level in the database
        $academicLevel = DB::table('academic_level')
            ->where('name', 'like', '%' . $levelName . '%')
            ->where('removed', 0)
            ->first();
        
        if ($academicLevel) {
            return $academicLevel->id;
        }
        
        // If not found, create a new academic level
        return DB::table('academic_level')->insertGetId([
            'name' => $levelName,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}