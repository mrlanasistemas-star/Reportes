<?php

namespace App\Models;

use App\Enums\ReportUploadStatus;
use App\Models\Scopes\ExcludePortfolioSnapshotUploadsScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Archivo fuente cargado al sistema (reporte de Lendus, NOI, ERP, etc.).
 *
 * Tabla: `report_uploads`. Cada fila es un archivo subido para un periodo y una
 * fuente de datos (DataSource); a partir de él los importadores llenan las tablas
 * de hechos (colocación, cobranza, cartera, gastos, nómina). Las fotografías
 * diarias de cartera (Efectividad V4) también viven aquí con `snapshot_date`,
 * pero un scope global las oculta del flujo mensual (ver snapshots()).
 *
 * Columnas clave:
 * - `period_id`: periodo principal del archivo.
 * - `covered_period_ids` (array): periodos que abarca el archivo (puede cubrir varios).
 * - `data_source_id`: fuente de datos.
 * - `original_name`/`stored_path`/`mime_type`/`file_size` (int): datos del archivo.
 * - `uploaded_by`/`uploaded_at` (datetime): quién y cuándo lo subió.
 * - `status` (enum ReportUploadStatus): estado de la carga/procesamiento.
 * - `notes`: notas.
 * - `snapshot_date` (date): solo para fotografías de cartera; null en cargas mensuales.
 */
class ReportUpload extends Model
{
    protected $fillable = [
        'period_id',
        'covered_period_ids',
        'data_source_id',
        'original_name',
        'stored_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
        'status',
        'notes',
        'snapshot_date',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'file_size' => 'integer',
        'status' => ReportUploadStatus::class,
        'covered_period_ids' => 'array',
        'snapshot_date' => 'date',
    ];

    /**
     * Registra el scope global que oculta las fotografías diarias de cartera
     * (ExcludePortfolioSnapshotUploadsScope) en todas las consultas.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(ExcludePortfolioSnapshotUploadsScope::NAME, new ExcludePortfolioSnapshotUploadsScope);
    }

    /**
     * Fotografías diarias de cartera (Efectividad V4). El scope global las oculta
     * del flujo mensual; este es el ÚNICO punto de acceso a ellas.
     *
     * @return Builder Consulta de cargas con `snapshot_date` no nula.
     */
    public static function snapshots(): Builder
    {
        return static::query()
            ->withoutGlobalScope(ExcludePortfolioSnapshotUploadsScope::NAME)
            ->whereNotNull('snapshot_date');
    }

    /** Periodo principal del archivo. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Fuente de datos (tipo de reporte) del archivo. */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /** Usuario que subió el archivo. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Bitácoras de procesamiento (importaciones) del archivo. */
    public function processRuns()
    {
        return $this->hasMany(ProcessRun::class);
    }

    /** Movimientos de nómina NOI importados de este archivo. */
    public function noiMovements(): HasMany
    {
        return $this->hasMany(NoiMovement::class);
    }

    /** Colocaciones importadas de este archivo. */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }

    /** Pagos de cobranza importados de este archivo. */
    public function recoveries(): HasMany
    {
        return $this->hasMany(Recovery::class);
    }

    /** Filas de cartera mensual importadas de este archivo. */
    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    /** Gastos importados de este archivo. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
