<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Archivo exportado de una radiografía (Excel o PDF).
 *
 * Tabla: `period_radiography_exports`. Cada fila es un archivo generado a partir de
 * un resumen de periodo y, desde jul-2026, ligado a la ejecución concreta (`run_id`)
 * que lo produjo, para que el reporte simple y el comparativo de un mismo periodo
 * convivan como archivos separados. Lo escribe GenerateRadiographyJob.
 *
 * Columnas clave:
 * - `export_path`: ruta del archivo en el disco de almacenamiento.
 * - `file_type`: `excel` o `pdf`.
 * - `template_version`: versión de plantilla usada al exportar.
 * - `metadata` (array): datos adicionales del export.
 * - `exported_at`/`exported_by`: cuándo y qué usuario lo exportó.
 */
class PeriodRadiographyExport extends Model
{
    protected $fillable = ['period_summary_id', 'run_id', 'export_path', 'file_type', 'template_version', 'metadata', 'exported_at', 'exported_by'];

    protected $casts = ['metadata' => 'array', 'exported_at' => 'datetime'];

    /**
     * Ejecución de radiografía (`period_radiography_runs`) que generó este archivo;
     * identifica tipo de reporte, alcance y periodo de comparación.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PeriodRadiographyRun::class, 'run_id');
    }
}
