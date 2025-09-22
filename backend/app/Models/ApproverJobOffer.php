<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApproverJobOffer extends Model
{
    protected $table = 'job_offers';

    protected $fillable = [
        'applicant_id',
        'status',
        'offer_details',
        'created_at',
        'updated_at',
    ];

    public $timestamps = true;

    public function applicant()
    {
        return $this->belongsTo(\App\Models\ApplicantDashboard::class, 'applicant_id');
    }
}
