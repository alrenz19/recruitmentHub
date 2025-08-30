<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class Assessment extends Model
{
    protected $fillable = [
        'title', 'description', 'time_allocated', 'time_unit', 'created_by_user_id', 'removed',
    ];

    public $timestamps = true; 

    public function questions()
    {
        return $this->hasMany(AssessmentQuestion::class)
            ->where('removed', 0);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
