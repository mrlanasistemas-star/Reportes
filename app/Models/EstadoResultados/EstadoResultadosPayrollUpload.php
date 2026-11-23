<?php

namespace App\Models\EstadoResultados;

use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carga de nómina PROPIA del Estado de Resultados (hoy: NOI de corporativo).
 * Nunca se mezcla con report_uploads / fact_noi_movements (Radiografía).
 *
 * Tabla `estado_resultados_payroll_uploads`. Cada fila es un archivo de nómina de un periodo.
 * Columnas clave: `kind` (tipo de nómina), datos del archivo (`original_name`, `file_hash`,
 * `file_size`, `stored_path`), `status` (cargado | reemplazado), contadores (`rows_read`,
 * `lines_count`, `employees_count`), totales (`total_percepciones`, `total_deducciones`),
 * `warnings` y sustitución (`superseded_at`, `superseded_by_upload_id`).
 */
class EstadoResultadosPayrollUpload extends Model
{
    /** Tipo de carga: nómina NOI del corporativo. */
    public const KIND_NOI_CORPORATIVO = 'noi_corporativo';

    /** Carga vigente. */
    public const STATUS_LOADED = 'cargado';

    /** Carga sustituida por otra más reciente (sólo historial). */
    public const STATUS_REPLACED = 'reemplazado';

    protected $table = 'estado_resultados_payroll_uploads';

    protected $guarded = ['id'];

    protected $casts = [
        'warnings' => 'array',
        'superseded_at' => 'datetime',
        'rows_read' => 'integer',
        'lines_count' => 'integer',
        'employees_count' => 'integer',
        'total_percepciones' => 'decimal:2',
        'total_deducciones' => 'decimal:2',
    ];

    /**
     * Periodo al que corresponde la nómina.
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * Usuario que subió el archivo (columna `uploaded_by`).
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Renglones (concepto por empleado) leídos de la carga.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(EstadoResultadosPayrollLine::class, 'upload_id');
    }

    /**
     * Scope: sólo cargas no reemplazadas (las que cuentan para el periodo).
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_REPLACED);
    }
}
