<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fotografía diaria de cartera (Saldos por Cliente con fecha) — Efectividad V4.
 * Ver migración 2026_10_13_090000_create_portfolio_snapshot_tables.
 *
 * Tabla: `portfolio_snapshots`. Cada fila es un archivo de fotografía importado
 * para una fecha. Una fotografía nueva de la MISMA fecha y sucursal deja a la
 * anterior como `replaced` (nunca se suman dos fotografías de un mismo día).
 *
 * Columnas clave:
 * - `snapshot_date` (date): fecha real de la fotografía.
 * - `status`: STATUS_ACTIVE o STATUS_REPLACED (la migración también prevé `failed`).
 * - `rows_count` (int): filas importadas.
 * - `branch_ids`/`offices` (array): sucursales/oficinas que cubre el archivo.
 * - `headers` (array)/`header_row`/`source_columns`: estructura detectada del archivo.
 * - `file_sha1`/`importer_version`: huella del archivo y versión del importador.
 * - `replaced_by_id`: fotografía que la reemplazó.
 * - `imported_at` (datetime): cuándo se importó.
 */
class PortfolioSnapshot extends Model
{
    /** Fotografía vigente para su fecha y sucursales. */
    public const STATUS_ACTIVE = 'active';

    /** Fotografía sustituida por otra más reciente de la misma fecha/sucursal. */
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

    /**
     * Archivo cargado (ReportUpload) de la fotografía. Se quitan los scopes globales
     * porque ReportUpload oculta por defecto las cargas con `snapshot_date`
     * (ExcludePortfolioSnapshotUploadsScope), que son justamente estas.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class, 'report_upload_id')->withoutGlobalScopes();
    }

    /** Filas (contratos) de la fotografía. */
    public function rows(): HasMany
    {
        return $this->hasMany(PortfolioSnapshotRow::class);
    }

    /**
     * Scope: solo fotografías vigentes (`status = active`).
     *
     * @param  Builder  $q  Consulta a filtrar.
     * @return Builder La consulta filtrada.
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    /**
     * ¿Incluye la sucursal? Sin sucursal = alcance general (cualquier fotografía sirve).
     *
     * @param  int|null  $branchId  Sucursal a verificar; null significa alcance general.
     * @return bool true si la fotografía cubre la sucursal (o si no se pidió una).
     */
    public function coversBranch(?int $branchId): bool
    {
        return $branchId === null || in_array($branchId, array_map('intval', $this->branch_ids ?? []), true);
    }
}
