<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecruitmentNote extends Model
{
    protected $table = 'recruitment_notes';
    protected $fillable = ['applicant_id', 'hr_id', 'note', 'created_by_user_id', 'removed'];
}
