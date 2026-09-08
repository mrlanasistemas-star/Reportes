<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Check-in semanal por Objective — ver docs/OKR.md, sección Check-in. */
class OkrCheckIn extends Model
{
    protected $fillable = [
        'okr_objective_id', 'week_number', 'check_in_date', 'user_id',
        'main_blocker', 'corrective_action', 'actual_value_snapshot',
    ];

    protected $casts = [
        'check_in_date'         => 'date',
        'actual_value_snapshot' => 'array',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(OkrCorrectiveAction::class, 'okr_check_in_id');
    }
}
