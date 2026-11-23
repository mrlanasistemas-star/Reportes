<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de fuentes de datos que se pueden cargar al sistema.
 *
 * Tabla: `data_sources`. Cada fila es un tipo de archivo/reporte de origen
 * (ej. Lendus ministraciones, NOI nómina, gastos ERP) que el módulo de carga acepta.
 *
 * Columnas clave:
 * - `code`: identificador estable de la fuente (lo usan importadores y servicios).
 * - `name`/`description`: textos para la UI.
 * - `is_active` (bool): si la fuente se ofrece en la carga.
 * - `is_required_for_bd` (bool): si es obligatoria para actualizar la base del periodo.
 * - `is_required_for_report` (bool): si es obligatoria para generar la radiografía.
 */
class DataSource extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'is_required_for_bd',
        'is_required_for_report',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_required_for_bd' => 'boolean',
        'is_required_for_report' => 'boolean',
    ];

    /** Archivos cargados (`report_uploads`) de esta fuente. */
    public function reportUploads(): HasMany
    {
        return $this->hasMany(ReportUpload::class);
    }
}
