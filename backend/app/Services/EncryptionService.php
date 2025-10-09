<?php
// app/Services/EncryptionService.php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Encryption\Encrypter;

class EncryptionService
{
    /**
     * Encrypt a value
     */
    public function encrypt($value)
    {
        if (empty($value)) {
            return $value;
        }

        try {
            return Crypt::encrypt($value);
        } catch (\Exception $e) {
            Log::error('Encryption failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Decrypt a value
     */
    public function decrypt($value)
    {
        if (empty($value)) {
            return $value;
        }

        try {
            return Crypt::decrypt($value);
        } catch (\Exception $e) {
            Log::warning('Decryption failed, might not be encrypted: ' . $e->getMessage());
            return $value; // Return original if decryption fails
        }
    }

    /**
     * Deterministic encryption - same input always produces same output
     * Useful for querying encrypted data
     * 
     * WARNING: Less secure than standard encryption - use only when necessary for queries
     */
    public function encryptDeterministic($value): string
    {
        if (empty($value)) {
            return $value;
        }

        $key = config('app.key');
        
        // Derive a deterministic key from app key
        $deterministicKey = hash('sha256', $key . 'deterministic', true);
        
        // Use AES-256-ECB for deterministic encryption (same input = same output)
        $encrypted = openssl_encrypt(
            $value,
            'AES-256-ECB',
            $deterministicKey,
            OPENSSL_RAW_DATA
        );
        
        return base64_encode($encrypted);
    }

    /**
     * Decrypt deterministically encrypted value
     */
    public function decryptDeterministic($encryptedValue): string
    {
        if (empty($encryptedValue)) {
            return $encryptedValue;
        }

        $key = config('app.key');
        $deterministicKey = hash('sha256', $key . 'deterministic', true);
        
        $decrypted = openssl_decrypt(
            base64_decode($encryptedValue),
            'AES-256-ECB',
            $deterministicKey,
            OPENSSL_RAW_DATA
        );
        
        return $decrypted;
    }

    /**
     * Bulk encrypt array of data
     */
    public function encryptArray(array $data, array $fieldsToEncrypt)
    {
        foreach ($fieldsToEncrypt as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $data[$field] = $this->encrypt($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Bulk decrypt array of data
     */
    public function decryptArray(array $data, array $fieldsToDecrypt)
    {
        foreach ($fieldsToDecrypt as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $data[$field] = $this->decrypt($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Bulk encrypt array of data using deterministic encryption
     */
    public function encryptArrayDeterministic(array $data, array $fieldsToEncrypt)
    {
        foreach ($fieldsToEncrypt as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $data[$field] = $this->encryptDeterministic($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Encrypt database records in bulk
     */
    public function encryptTableRecords($table, array $encryptableFields, $chunkSize = 1000)
    {
        DB::table($table)->orderBy('id')->chunk($chunkSize, function ($records) use ($table, $encryptableFields) {
            foreach ($records as $record) {
                $updateData = [];
                foreach ($encryptableFields as $field) {
                    if (!empty($record->$field)) {
                        try {
                            $updateData[$field] = $this->encrypt($record->$field);
                        } catch (\Exception $e) {
                            Log::error("Failed to encrypt {$table}.{$field} for record {$record->id}");
                        }
                    }
                }
                
                if (!empty($updateData)) {
                    DB::table($table)->where('id', $record->id)->update($updateData);
                }
            }
        });
    }

    /**
     * Check if a string is encrypted
     */
    public function isEncrypted($value)
    {
        if (!is_string($value) || empty($value)) {
            return false;
        }

        try {
            Crypt::decrypt($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a string is deterministically encrypted
     */
    public function isDeterministicallyEncrypted($value)
    {
        if (!is_string($value) || empty($value)) {
            return false;
        }

        try {
            $this->decryptDeterministic($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get encryption status of database fields
     */
    public function getEncryptionStatus($table, $fields)
    {
        $status = [];
        
        foreach ($fields as $field) {
            $sample = DB::table($table)
                ->whereNotNull($field)
                ->where($field, '!=', '')
                ->first();
            
            if ($sample) {
                $status[$field] = [
                    'standard' => $this->isEncrypted($sample->$field),
                    'deterministic' => $this->isDeterministicallyEncrypted($sample->$field)
                ];
            } else {
                $status[$field] = null;
            }
        }
        
        return $status;
    }

    /**
     * Generate a hash for querying (alternative to deterministic encryption)
     * More secure but can't decrypt - only for comparison
     */
    public function hashForQuery($value, $algorithm = 'sha256', $salt = ''): string
    {
        if (empty($value)) {
            return $value;
        }

        $pepper = config('app.key');
        return hash($algorithm, $value . $salt . $pepper);
    }

    /**
     * Verify a value against a query hash
     */
    public function verifyHash($value, $hash, $algorithm = 'sha256', $salt = ''): bool
    {
        return hash_equals($hash, $this->hashForQuery($value, $algorithm, $salt));
    }
}