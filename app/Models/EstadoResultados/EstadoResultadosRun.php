<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstadoResultadosRun extends Model
{
    protected $table = 'estado_resultados_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'summary' => 'array',
        'recognition_criterion' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
