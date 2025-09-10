<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantAssessment extends Model
{
    protected $table = 'applicant_assessments';
    public $timestamps = true; // created_at and updated_at

    protected $fillable = [
        'applicant_id',
        'assessment_id',
        'assigned_by',
        'status',
        'removed',
        'attempts_used',
    ];

    // Relationships
    public function applicant()
    {
        return $this->belongsTo(Candidate::class, 'applicant_id');
    }

    public function assessment()
    {
        return $this->belongsTo(Examination::class, 'assessment_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
