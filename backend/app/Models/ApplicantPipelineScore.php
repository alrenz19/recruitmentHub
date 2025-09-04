<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantPipelineScore extends Model
{
    protected $table = 'applicant_pipeline_score';
    protected $fillable = ['applicant_pipeline_id', 'raw_score', 'overall_score', 'type', 'removed'];
}
