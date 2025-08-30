<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantFiles extends Model
{

    protected $table = 'applicant_files';
    public $timestamps = true; // created_at and updated_at

    protected $fillable = [
        'applicant_id',
        'file_name',
        'file_path',
        'file_type',
        'status',
        'removed'
    ];

    // Relationships
    public function applicant()
    {
        return $this->belongsTo(Candidate::class, 'applicant_id');
    }
}

