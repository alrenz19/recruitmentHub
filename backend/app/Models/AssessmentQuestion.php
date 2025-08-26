<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentQuestion extends Model
{
    protected $fillable = [
        'assessment_id', 'question_text', 'image_path', 'question_type', 'removed'
    ];

    public $timestamps = true; 

    public function options()
    {
        return $this->hasMany(AssessmentOption::class, 'question_id')
        ->where('removed', 0);
    }
}
