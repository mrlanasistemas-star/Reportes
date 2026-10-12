<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EfectividadCobranzaRun extends Model
{
    protected $fillable = [
        'period_id',
        'branch_id',
        'status',
        'log',
        'metadata',
        'coverage',
        'result_rows',
        'output_excel_path',
        'error_message',
        'created_by',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'coverage'    => 'array',
        'result_rows' => 'array',
        'queued_at'   => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Última ejecución EXITOSA para esta identidad exacta (period_id + branch_id,
     * con sucursal NULL = alcance general) — nunca "el último intento", que puede
     * estar failed/running y ocultar un éxito previo válido.
     */
    public static function latestSuccessFor(int $periodId, ?int $branchId): ?self
    {
        return static::query()
            ->where('period_id', $periodId)
            ->where(fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q->whereNull('branch_id'))
            ->where('status', 'success')
            ->latest('id')
            ->first();
    }

    public static function latestFor(int $periodId, ?int $branchId): ?self
    {
        return static::query()
            ->where('period_id', $periodId)
            ->where(fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q->whereNull('branch_id'))
            ->latest('id')
            ->first();
    }
}
