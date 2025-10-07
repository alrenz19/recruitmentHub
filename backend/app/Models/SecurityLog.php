<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'level',
        'user_id',
        'user_email',
        'details',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'details' => 'array'
    ];
}