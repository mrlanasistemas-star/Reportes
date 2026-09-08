<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OkrCorrectiveAction extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_DONE     = 'done';
    public const STATUS_OVERDUE  = 'overdue';

    protected $fillable = [
        'okr_objective_id', 'okr_check_in_id', 'description', 'responsible_user_id',
        'due_date', 'status', 'completed_at', 'evidence_id',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'date',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(OkrCheckIn::class, 'okr_check_in_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->due_date?->isPast();
    }
}
