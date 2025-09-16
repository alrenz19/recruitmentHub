<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantNotification extends Model
{
    // Use applicant_pipeline as the source
    protected $table = 'applicant_pipeline';

    protected $fillable = [
        'applicant_id',
        'current_stage_id',
        'note',
        'schedule_date',
        'created_at',
        'updated_at',
    ];

    public $timestamps = true;

    // If you want relationship to applicant
    public function applicant()
    {
        return $this->belongsTo(\App\Models\ApplicantDashboard::class, 'applicant_id');
    }
}
