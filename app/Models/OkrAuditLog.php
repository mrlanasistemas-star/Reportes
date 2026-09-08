<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora de auditoría — ver OkrAuditLogger. "Una meta no se puede modificar silenciosamente." */
class OkrAuditLog extends Model
{
    public const TYPE_OBJECTIVE  = 'objective';
    public const TYPE_KEY_RESULT = 'key_result';

    protected $fillable = [
        'auditable_type', 'auditable_id', 'user_id', 'action',
        'field', 'old_value', 'new_value', 'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
