<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Intento de carga de un estado de cuenta (exitoso, rechazado o duplicado) con sus errores por fila. */
class EstadoResultadosImportAttempt extends Model
{
    public const EN_PROCESO = 'en_proceso';

    public const COMPLETADO = 'completado';

    public const RECHAZADO = 'rechazado';

    public const DUPLICADO = 'duplicado';

    public const LABELS = [
        self::EN_PROCESO => 'En proceso',
        self::COMPLETADO => 'Cargado',
        self::RECHAZADO => 'Rechazado',
        self::DUPLICADO => 'Ya estaba cargado',
    ];

    protected $table = 'estado_resultados_import_attempts';

    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function errors(): HasMany
    {
        return $this->hasMany(EstadoResultadosImportError::class, 'attempt_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
