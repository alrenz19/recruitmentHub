<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Notifiable;
    use HasApiTokens;

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'role_id', 'user_email', 'password_hash', 'remember_token', 'is_removed'
    ];

    protected $hidden = [
        'password_hash', 'remember_token',
    ];

    // Always include full_name in API responses
    protected $appends = ['full_name'];

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function getEmailForPasswordReset()
    {
        return $this->user_email;
    }

    public function getRememberToken()
    {
        return $this->remember_token;
    }

    public function setRememberToken($value)
    {
        $this->remember_token = $value;
    }

    public function getRememberTokenName()
    {
        return 'remember_token';
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    public function hrStaff()
    {
        return $this->hasOne(HrStaff::class, 'user_id', 'id')->where('removed', 0);
    }

    public function applicant()
    {
        return $this->hasOne(Applicant::class, 'user_id', 'id')->where('removed', 0);
    }

    // 👇 Custom accessor for full_name
    public function getFullNameAttribute()
    {
        if ($this->hrStaff) {
            return $this->hrStaff->full_name;
        }

        if ($this->applicant) {
            return $this->applicant->full_name;
        }

        return null;
    }
}
