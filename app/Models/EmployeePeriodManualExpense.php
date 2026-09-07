<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Gasto general por gestor" persistente por (period_id, employee_id) — ver
 * migración 2026_09_07_000001_create_employee_period_manual_expenses_table y
 * App\Services\EmployeePeriodManualExpenseService (fuente única de lectura/
 * escritura, nunca usar este modelo directamente fuera de ese servicio salvo
 * para relaciones/consultas simples).
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

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
