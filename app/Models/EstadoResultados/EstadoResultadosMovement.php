<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstadoResultadosMovement extends Model
{
    protected $table = 'estado_resultados_movements';

    protected $guarded = ['id'];

    protected $casts = [
        'movement_date' => 'date:Y-m-d',
        'detail_lines' => 'array',
        'raw_payload' => 'array',
        'reconciliation_detail' => 'array',
        'in_period' => 'boolean',
        'needs_review' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosUpload::class, 'upload_id');
    }
}
