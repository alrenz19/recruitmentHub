<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssessmentAnswer extends Model
{
    use HasFactory;

    protected $table = 'assessment_answers';

    protected $fillable = [
        'applicant_id',
        'question_id',
        'answer_text',
        'selected_option_id',
        'submitted_at',
        'removed',
    ];

    public $timestamps = false;

    public function question()
    {
        return $this->belongsTo(AssessmentQuestion::class, 'question_id');
    }

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }
}
