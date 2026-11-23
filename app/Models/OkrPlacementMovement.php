<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de colocación importado para medir metas OKR.
 *
 * Tabla: `okr_placement_movements`. Cada fila es una operación de colocación
 * leída de un archivo de colocación OKR (OkrPlacementUpload), atribuida (o pendiente
 * de atribuir) a un gestor por OkrPlacementAttributionResolver.
 *
 * Columnas clave:
 * - `employee_id`/`employee_name_raw`: gestor resuelto y nombre tal como venía.
 * - `attribution_status`: estado de atribución (atribuido o pendiente).
 * - `attribution_method`: cómo se resolvió (ej. por código, `name_exact`).
 * - `attribution_note`: nota explicativa de la atribución.
 * - `amount` (float), `operation_date` (date): monto y fecha de la operación.
 * - `fingerprint`: huella para evitar duplicados al recargar.
 * - `raw_payload` (array): fila original del archivo.
 */
class OkrPlacementMovement extends Model
{
    protected $fillable = [
        'okr_placement_upload_id', 'employee_id', 'employee_name_raw',
        'attribution_status', 'attribution_method', 'attribution_note',
        'amount', 'operation_date', 'fingerprint', 'raw_payload',
    ];

    protected $casts = [
        'operation_date' => 'date',
        'amount' => 'float',
        'raw_payload' => 'array',
    ];

    /** Carga de colocación OKR de la que proviene el movimiento. */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(OkrPlacementUpload::class, 'okr_placement_upload_id');
    }

    /** Gestor al que se atribuyó la colocación (null si sigue pendiente). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
