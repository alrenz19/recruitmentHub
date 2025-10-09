<?php
// app/Traits/EncryptsAttributes.php

namespace App\Traits;

use App\Services\EncryptionService;
use Illuminate\Support\Facades\Log;

trait EncryptsAttributes
{
    /**
     * Boot the trait
     */
    public static function bootEncryptsAttributes()
    {
        static::retrieved(function ($model) {
            $model->decryptAttributes();
        });

        static::saving(function ($model) {
            $model->encryptAttributes();
        });
    }

    /**
     * Get encryptable attributes for this model
     */
    public function getEncryptableAttributes()
    {
        return property_exists($this, 'encryptable') ? $this->encryptable : [];
    }

    /**
     * Get encryption service instance
     */
    protected function getEncryptionService()
    {
        return app(EncryptionService::class);
    }

    /**
     * Encrypt attributes before saving
     */
    public function encryptAttributes()
    {
        $encryptable = $this->getEncryptableAttributes();
        $encryptionService = $this->getEncryptionService();
        
        foreach ($encryptable as $attribute) {
            if (isset($this->attributes[$attribute]) && !empty($this->attributes[$attribute])) {
                try {
                    $this->attributes[$attribute] = $encryptionService->encrypt($this->attributes[$attribute]);
                } catch (\Exception $e) {
                    Log::error("Encryption failed for {$attribute}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Decrypt attributes after retrieval
     */
    public function decryptAttributes()
    {
        $encryptable = $this->getEncryptableAttributes();
        $encryptionService = $this->getEncryptionService();
        
        foreach ($encryptable as $attribute) {
            if (isset($this->attributes[$attribute]) && !empty($this->attributes[$attribute])) {
                try {
                    $this->attributes[$attribute] = $encryptionService->decrypt($this->attributes[$attribute]);
                } catch (\Exception $e) {
                    Log::warning("Decryption failed for {$attribute}, might not be encrypted: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Get attribute with automatic decryption
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        
        $encryptable = $this->getEncryptableAttributes();
        
        if (in_array($key, $encryptable) && is_string($value) && !empty($value)) {
            try {
                return $this->getEncryptionService()->decrypt($value);
            } catch (\Exception $e) {
                return $value; // Return as-is if decryption fails
            }
        }
        
        return $value;
    }

    /**
     * Set attribute with automatic encryption
     */
    public function setAttribute($key, $value)
    {
        $encryptable = $this->getEncryptableAttributes();
        
        if (in_array($key, $encryptable) && !empty($value)) {
            try {
                $value = $this->getEncryptionService()->encrypt($value);
            } catch (\Exception $e) {
                Log::error("Encryption failed for {$key}: " . $e->getMessage());
            }
        }
        
        return parent::setAttribute($key, $value);
    }

    /**
     * Get original (encrypted) value
     */
    public function getOriginalEncrypted($key = null)
    {
        if ($key) {
            return $this->getOriginal($key);
        }
        
        return $this->getOriginal();
    }

    /**
     * Get deterministically encrypted value for querying
     */
    public function getDeterministicEncrypted($key)
    {
        $value = $this->getAttribute($key);
        
        if (empty($value)) {
            return $value;
        }
        
        return $this->getEncryptionService()->encryptDeterministic($value);
    }

    /**
     * Query scope for deterministically encrypted attributes
     */
    public function scopeWhereEncrypted($query, $column, $value)
    {
        $encryptedValue = $this->getEncryptionService()->encryptDeterministic($value);
        
        return $query->where($column, $encryptedValue);
    }

    /**
     * Query scope for multiple deterministically encrypted values
     */
    public function scopeWhereEncryptedIn($query, $column, array $values)
    {
        $encryptedValues = array_map(
            [$this->getEncryptionService(), 'encryptDeterministic'], 
            $values
        );
        
        return $query->whereIn($column, $encryptedValues);
    }
}