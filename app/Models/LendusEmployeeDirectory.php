<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Directorio de empleados exportado de Lendus.
 *
 * Tabla: `lendus_employee_directory`. Cada fila es un empleado tal como aparece
 * en el directorio de Lendus (LendusEmployeeDirectoryImportService). Se usa para
 * resolver gestores por CODIGO y para inferir su sucursal y si es personal operativo.
 *
 * Columnas clave:
 * - `codigo`: código del empleado en Lendus (clave de resolución).
 * - `nombre`/`normalized_name`: nombre original y normalizado.
 * - `puesto`/`area`/`promotor`/`distribuidor`/`estatus`: datos tal como vienen.
 * - `fecha_inicio` (date): fecha de alta.
 * - `inferred_branch_id`/`inferred_branch_name`: sucursal inferida.
 * - `is_operational` (bool): si se considera personal operativo (gestor/coordinador).
 * - `raw_payload` (array): fila original.
 */
class LendusEmployeeDirectory extends Model
{
    protected $table = 'lendus_employee_directory';

    protected $fillable = [
        'report_upload_id',
        'codigo',
        'nombre',
        'normalized_name',
        'puesto',
        'area',
        'promotor',
        'distribuidor',
        'estatus',
        'fecha_inicio',
        'inferred_branch_id',
        'inferred_branch_name',
        'is_operational',
        'raw_payload',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'is_operational' => 'boolean',
        'raw_payload' => 'array',
    ];

    /** Sucursal inferida para el empleado (vía `inferred_branch_id`). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'inferred_branch_id');
    }

    /** Archivo cargado del que proviene la fila. */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class, 'report_upload_id');
    }
}
