<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentOption extends Model
{
    protected $fillable = ['question_id', 'option_text', 'is_correct', 'removed'];
}

