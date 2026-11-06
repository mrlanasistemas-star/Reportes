<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstadoResultadosVersionLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_version_logs';

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
