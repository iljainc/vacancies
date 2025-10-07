<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLog extends Model
{
    protected $fillable = [
        'method',
        'url',
        'ip_address',
        'real_ip',
        'user_agent',
        'status_code',
        'execution_time',
        'request_size',
        'response_size',
        'request_data',
        'response_data',
        'user_id',
    ];

    protected $casts = [
        'execution_time' => 'float',
        'request_size' => 'integer',
        'response_size' => 'integer',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
