<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Check-in semanal por Objective — ver docs/MANUAL-DESARROLLADOR.md, sección Check-in.
 *
 * Tabla: `okr_check_ins`. Cada fila es el seguimiento de una semana de un
 * objetivo: principal bloqueo, acción correctiva y foto de los valores reales
 * de sus resultados clave en ese momento.
 *
 * Columnas clave:
 * - `week_number`: número de semana del ciclo.
 * - `check_in_date` (date): fecha del check-in.
 * - `main_blocker`/`corrective_action`: textos capturados por el responsable.
 * - `actual_value_snapshot` (array): valores reales de los KR al registrar.
 */
class OkrCheckIn extends Model
{
    protected $fillable = [
        'okr_objective_id', 'week_number', 'check_in_date', 'user_id',
        'main_blocker', 'corrective_action', 'actual_value_snapshot',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'actual_value_snapshot' => 'array',
    ];

    /** Objetivo OKR al que pertenece el check-in. */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    /** Usuario que registró el check-in. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Acciones correctivas derivadas de este check-in. */
    public function correctiveActions(): HasMany
    {
        return $this->hasMany(OkrCorrectiveAction::class, 'okr_check_in_id');
    }
}
