<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssessmentResult extends Model
{
    use HasFactory;

    protected $table = 'assessment_results';

    protected $fillable = [
        'applicant_id',
        'assessment_id',
        'score',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'removed',
    ];

    public $timestamps = true;

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function assessment()
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }
}
