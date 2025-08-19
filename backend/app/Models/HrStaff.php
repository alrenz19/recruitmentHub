<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrStaff extends Model
{
    protected $table = 'hr_staff';
    protected $primaryKey = 'id';
    public $incrementing = true;
    public $timestamps = true;

    protected $fillable = [
        'user_id', 'full_name', 'department', 'position', 'contact_email', 'profile_picture', 'removed'
    ];

    // Relationship to User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
