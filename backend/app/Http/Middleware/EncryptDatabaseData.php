<?php
// app/Http/Middleware/EncryptDatabaseData.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class EncryptDatabaseData
{
    /**
     * List of tables and their encryptable fields
     * Add your tables and fields here
     */
    private $encryptableFields = [
        'users' => [
            'user_email'
        ],
        'applicants' => [
            'full_name', 'email', 'phone', 'profile_picture', 
            'place_of_birth', 'present_address', 'provincial_address',
            'desired_salary', 'signature', 'birth_date'
        ],
        'hr_staff' => [
            'full_name', 'contact_email', 'profile_picture', 'signature'
        ],
        'educational_background' => [
            'name_of_school', 'degree_major', 'award'
        ],
        'employment_history' => [
            'employer', 'last_position', 'benefits', 'salary'
        ],
        'family_background' => [
            'fname', 'relationship'
        ],
        'emergency_contact' => [
            'fname', 'contact', 'address', 'relationship'
        ],
        'additional_information' => [
            'question', 'answer', 'reason'
        ],
        'messages' => [
            'content'
        ],
        'chat_messages' => [
            'message'
        ],
        'recruitment_notes' => [
            'note'
        ],
        'job_offers' => [
            'position', 'offer_details', 'declined_reason'
        ],
        'assessment_questions' => [
            'question_text'
        ],
        'assessment_options' => [
            'option_text'
        ],
        'applicant_pipeline' => [
            'note', 'comments'
        ],
        'applicant_pipeline_score' => [
            'raw_Score', 'score_details', 'decision', 'comments'
        ],
        'applicant_files' => [
            'file_name', 'file_path'
        ]
    ];

    /**
     * Handle incoming request - encrypt data before it hits controller
     */
    public function handle(Request $request, Closure $next)
    {
        // Encrypt data before processing
        $this->encryptRequestData($request);
        
        $response = $next($request);
        
        return $response;
    }

    /**
     * Encrypt sensitive data in the request
     */
    private function encryptRequestData(Request $request)
    {
        $data = $request->all();
        $encryptedData = $this->processEncryption($data, 'encrypt');
        $request->replace($encryptedData);
    }

    /**
     * Process encryption/decryption recursively
     */
    public function processEncryption($data, $action = 'encrypt')
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->processEncryption($value, $action);
            }
            return $data;
        }
        
        if (is_string($data) && !empty(trim($data))) {
            try {
                return $action === 'encrypt' ? Crypt::encrypt($data) : Crypt::decrypt($data);
            } catch (\Exception $e) {
                Log::warning("Encryption error: " . $e->getMessage());
                return $data; // Return original data if encryption fails
            }
        }
        
        return $data;
    }

    /**
     * Check if a field should be encrypted for a specific table
     */
    public function shouldEncryptField($table, $field)
    {
        return isset($this->encryptableFields[$table]) && 
               in_array($field, $this->encryptableFields[$table]);
    }

    /**
     * Get all encryptable fields
     */
    public function getEncryptableFields()
    {
        return $this->encryptableFields;
    }
}