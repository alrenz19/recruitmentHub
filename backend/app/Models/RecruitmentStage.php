<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecruitmentStage extends Model
{
    protected $table = 'recruitment_stages';
    protected $fillable = ['stage_name', 'stage_order', 'removed'];

    public function pipelines()
    {
        return $this->hasMany(ApplicantPipeline::class, 'current_stage_id');
    }
}
