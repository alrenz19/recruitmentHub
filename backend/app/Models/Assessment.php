<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    protected $fillable = [
        'title', 'description', 'created_by_user_id', 'removed'
    ];

    public function questions()
    {
        return $this->hasMany(AssessmentQuestion::class)
            ->where('removed', 0);
    }
}
