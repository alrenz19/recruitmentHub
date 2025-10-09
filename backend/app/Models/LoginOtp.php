<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB; // ✅ Add this import
use App\Services\EncryptionService;

class LoginOtp extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'otp',
        'token',
        'ip_address',
        'user_agent',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Generate a new OTP with encrypted email
     */
    public static function generateForEmail(string $email): self
    {
        $encryptionService = app(EncryptionService::class);
        
        // Encrypt the email before storing
        $encryptedEmail = $encryptionService->encrypt($email);

        // Use transaction to prevent race conditions
        return DB::transaction(function () use ($encryptedEmail, $email) {
            // Invalidate any existing OTPs for this email
            self::where('email', $encryptedEmail)
                ->where('expires_at', '>', now())
                ->whereNull('verified_at')
                ->update(['expires_at' => now()]);

            return self::create([
                'email' => $encryptedEmail,
                'otp' => self::generateOtp(),
                'token' => Str::random(64),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'expires_at' => now()->addMinutes(10),
            ]);
        });
    }

    /**
     * Find valid OTP by token and email (with decryption)
     */
    public static function findValid(string $token, string $email): ?self
    {
        $encryptionService = app(EncryptionService::class);
        $otp = self::where('token', $token)
                ->where('expires_at', '>', now())
                ->whereNull('verified_at')
                ->first();

        if (!$otp) {
            return null;
        }

        try {
            $decryptedEmail = $encryptionService->decrypt($otp->email);
            
            return $decryptedEmail === $email ? $otp : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the decrypted email
     */
    public function getDecryptedEmail(): string
    {
        $encryptionService = app(EncryptionService::class);
        
        try {
            return $encryptionService->decrypt($this->email);
        } catch (\Exception $e) {
            return $this->email; // Return encrypted as fallback
        }
    }

    /**
     * Scope to get valid OTPs for an email
     */
    public function scopeValidForEmail($query, string $email)
    {
        $encryptionService = app(EncryptionService::class);
        $encryptedEmail = $encryptionService->encrypt($email);

        return $query->where('email', $encryptedEmail)
                    ->where('expires_at', '>', now())
                    ->whereNull('verified_at');
    }

    /**
     * Generate a 6-digit OTP
     */
    private static function generateOtp(): string
    {
        do {
            $otp = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('otp', $otp)->where('expires_at', '>', now())->exists());

        return $otp;
    }

    /**
     * Check if OTP is valid
     */
    public function isValid(): bool
    {
        return $this->expires_at->isFuture() && is_null($this->verified_at);
    }

    /**
     * Mark OTP as verified
     */
    public function markAsVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }

    /**
     * Scope to get valid OTPs
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now())
                    ->whereNull('verified_at');
    }
}