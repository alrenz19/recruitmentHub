<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Examination extends Model
{
    use HasFactory;

    protected $table = 'assessments';

    protected $fillable = [
        'title',
        'description',
        'time_allocated',
        'time_unit',
        'created_by_user_id',
        'removed'
    ];

    public function questions()
    {
        return $this->hasMany(AssessmentQuestion::class, 'assessment_id')
            ->where('removed', 0)
            ->with('options');
    }
}
