<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Candidate extends Model
{
    use HasFactory;

    // Table name if different from default 'candidates'
    protected $table = 'applicants';

    // Primary key type
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    // If timestamps are used (created_at, updated_at)
    public $timestamps = true;

    // Mass assignable fields
    protected $fillable = [
        'user_id',
        'full_name',
        'email',
        'phone',
        'profile_picture',
        'birth_date',
        'place_of_birth',
        'civil_status',
        'job_info_id',
        'position_desired',
        'present_address',
        'pre_zip_code',
        'provincial_address',
        'pro_zip_code',
        'religion',
        'age',
        'marital_status',
        'nationality',
        'desired_salary',
        'start_asap',
        'signature',
        'in_active',
        'removed'
    ];

    // Cast attributes to native types
    protected $casts = [
        'user_id' => 'integer',
        'job_info_id' => 'integer',
        'pre_zip_code' => 'integer',
        'pro_zip_code' => 'integer',
        'age' => 'integer',
        'desired_salary' => 'integer',
        'in_active' => 'boolean',
        'removed' => 'boolean',
        'birth_date' => 'date',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function jobInformationSource()
    {
        return $this->belongsTo(JobInformationSource::class, 'job_information_source_id');
    }

    public function assessments()
    {
        return $this->hasMany(ApplicantAssessment::class, 'applicant_id');
    }

    public function applicantFiles()
    {
        return $this->hasMany(ApplicantFiles::class, 'applicant_id');
    }

    public function pipeline()
    {
        return $this->hasOne(ApplicantPipeline::class, 'applicant_id');
    }

    public function recruitmentNotes(): HasMany
    {
        return $this->hasMany(RecruitmentNote::class, 'applicant_id', 'id');
    }

}
