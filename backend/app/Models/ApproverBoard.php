<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApproverBoard extends Model
{
    protected $table = 'applicant_pipeline';

    protected $fillable = [
        'applicant_id',
        'current_stage_id',
        'schedule_date',
        'created_at',
        'updated_at'
    ];

    public $timestamps = true;

    public function applicant()
    {
        return $this->belongsTo(\App\Models\ApplicantDashboard::class, 'applicant_id');
    }
}
