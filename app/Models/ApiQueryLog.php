<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiQueryLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'api_consumer_id',
        'endpoint',
        'method',
        'response_status',
        'scope',
        'branch_id',
        'employee_id',
        'period_id',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function apiConsumer(): BelongsTo
    {
        return $this->belongsTo(ApiConsumer::class);
    }
}
