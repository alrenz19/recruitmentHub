<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;


use Laravel\Sanctum\HasApiTokens;
use App\Models\PersonalAccessToken; // make sure this exists

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
        return $this->hasOne(Candidate::class, 'user_id', 'id')->where('removed', 0);
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

    public function isTokenRequest(Request $request): bool
    {
        return $request->bearerToken() && $this->currentAccessToken() !== null;
    }

        /**
     * Get the current access token being used for this request.
     *
     * @return \Laravel\Sanctum\PersonalAccessToken|null
     */
    public function currentAccessToken()
    {
        $request = app(Request::class);
        $tokenString = $request->bearerToken();

        if (!$tokenString) return null;

        $token = Cache::get("sanctum_token:{$tokenString}");

        // If cache missed, fetch from DB
        if (!$token) {
            $token = PersonalAccessToken::findToken($tokenString);
            if ($token) {
                Cache::put("sanctum_token:{$tokenString}", $token, now()->addMinutes(10));
            }
        }

        return $token;
    }

    public function cachedUser()
    {
        $token = $this->currentAccessToken();
        if (!$token) return null;

        return Cache::remember(
            "sanctum_user:{$token->id}",
            now()->addMinutes(5),
            fn () => $token->tokenable  // assumes tokenable is the User
        );
    }

}
