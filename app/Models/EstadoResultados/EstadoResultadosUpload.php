<?php

namespace App\Models\EstadoResultados;

use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstadoResultadosUpload extends Model
{
    public const STATUS_LOADED = 'cargado';

    public const STATUS_PROCESSED = 'procesado';

    public const STATUS_REPLACED = 'reemplazado';

    protected $table = 'estado_resultados_uploads';

    protected $guarded = ['id'];

    protected $casts = [
        'warnings' => 'array',
        'control_ok' => 'boolean',
        'first_movement_date' => 'date',
        'last_movement_date' => 'date',
        'superseded_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(EstadoResultadosMovement::class, 'upload_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_REPLACED);
    }
}
