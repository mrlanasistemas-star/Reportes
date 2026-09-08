<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alertas INTERNAS (nunca WhatsApp/SMS/correo/push externo en esta fase). */
class OkrAlert extends Model
{
    public const TYPE_RISK            = 'risk';
    public const TYPE_CHECKIN_PENDING = 'checkin_pending';
    public const TYPE_DEADLINE_NEAR   = 'deadline_near';
    public const TYPE_OFF_TRACK       = 'off_track';

    protected $fillable = [
        'okr_objective_id', 'type', 'message', 'dedupe_key', 'read_at', 'read_by',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
