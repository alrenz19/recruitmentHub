<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantSettings extends Model
{
    protected $table = 'applicants';
    public $timestamps = true;

    protected $fillable = [
        'full_name',
        'civil_status',
        'phone',
        'email',
        'present_address',
        'profile_picture',
    ];

    // Relation to User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relation to applicant_files
    public function files()
    {
        return $this->hasMany(ApplicantFiles::class, 'applicant_id');
    }
}
