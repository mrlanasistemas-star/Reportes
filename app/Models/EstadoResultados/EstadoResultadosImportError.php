<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;

/**
 * Error de validación de un intento de carga de estado de cuenta
 * (tabla `estado_resultados_import_errors`).
 *
 * Cada fila es un problema concreto de un `EstadoResultadosImportAttempt` (`attempt_id`):
 * `row_number` y `column_name` (dónde, si aplica) y `message` (qué está mal), para que el
 * usuario pueda corregir el archivo. Sólo tiene `created_at`.
 */
class EstadoResultadosImportError extends Model
{
    /** Sin columna updated_at: los errores sólo se insertan. */
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_import_errors';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];
}
