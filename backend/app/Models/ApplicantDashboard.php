<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantDashboard extends Model
{
    protected $table = 'applicants';

    protected $fillable = [
        'user_id',
        'full_name',
        'email',
        'phone',
        'profile_picture',
        'position_desired',
        'desired_salary',
        'created_at',
    ];

    public function pipeline()
    {
        return $this->hasOne(\App\Models\ApplicantPipeline::class, 'applicant_id');
    }
}
