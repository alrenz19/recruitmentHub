<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantPipeline extends Model
{
    protected $table = 'applicant_pipeline';
    public $timestamps = true;

    protected $fillable = [
        'applicant_id',
        'current_stage_id',
        'updated_by_user_id',
        'note',
        'schedule_date',
        'removed',
    ];

    // Relationships
    public function applicant()
    {
        return $this->belongsTo(Candidate::class, 'applicant_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function currentStage()
    {
        return $this->belongsTo(RecruitmentStage::class, 'current_stage_id');
    }
}
