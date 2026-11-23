<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⚠️ DESCONECTADO DEL CÁLCULO (reversión 07-sep-2026, cierre) — ver
 * App\Services\EmployeePeriodManualExpenseService. La tabla se conserva
 * (no se borra/trunca), pero ningún flujo real la lee ni la escribe: el
 * "Gasto general por gestor" es 100% efímero por request desde el cierre.
 * No usar este modelo en código nuevo.
 *
 * Tabla: `employee_period_manual_expenses`. Cada fila era un gasto general manual
 * asignado a un gestor en un periodo (`amount` decimal:2, `notes`, auditoría
 * `created_by`/`updated_by`).
 */
class EmployeePeriodManualExpense extends Model
{
    protected $fillable = [
        'period_id',
        'employee_id',
        'amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** Periodo del gasto manual. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Gestor al que se asignó el gasto manual. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
