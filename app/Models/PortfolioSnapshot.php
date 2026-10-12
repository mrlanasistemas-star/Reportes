<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fotografía diaria de cartera (Saldos por Cliente con fecha) — Efectividad V4.
 * Ver migración 2026_10_13_090000_create_portfolio_snapshot_tables.
 */
class PortfolioSnapshot extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REPLACED = 'replaced';

    protected $fillable = [
        'report_upload_id', 'period_id', 'snapshot_date', 'status', 'rows_count', 'branch_ids', 'offices',
        'headers', 'header_row', 'source_columns', 'file_sha1', 'importer_version', 'replaced_by_id', 'imported_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'branch_ids' => 'array',
        'offices' => 'array',
        'headers' => 'array',
        'rows_count' => 'integer',
        'imported_at' => 'datetime',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class, 'report_upload_id')->withoutGlobalScopes();
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PortfolioSnapshotRow::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    /** ¿Incluye la sucursal? Sin sucursal = alcance general (cualquier fotografía sirve). */
    public function coversBranch(?int $branchId): bool
    {
        return $branchId === null || in_array($branchId, array_map('intval', $this->branch_ids ?? []), true);
    }
}
