<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Intento de carga de un estado de cuenta (exitoso, rechazado o duplicado) con sus errores por fila
 * (tabla `estado_resultados_import_attempts`).
 *
 * Se registra TODO intento, incluso los rechazados, para que el usuario vea por qué falló.
 * Columnas clave: `period_id`, `account_slot` y `file_part` (cuenta y parte del archivo, hay
 * hasta dos archivos por cuenta), datos del archivo (`original_name`, `extension`, `mime`,
 * `file_hash`, `file_size`, `stored_path` — se guarda copia aun si se rechazó), `status`,
 * `lifecycle`, `upload_id` (carga resultante si se aceptó), `message`, `errors_count`,
 * `user_id` y tiempos (`started_at`, `validated_at`, `finished_at`).
 */
class EstadoResultadosImportAttempt extends Model
{
    /** El archivo se está leyendo/validando. */
    public const EN_PROCESO = 'en_proceso';

    /** El archivo se aceptó y generó una carga (`upload_id`). */
    public const COMPLETADO = 'completado';

    /** El archivo no pasó la validación (ver `errors()`). */
    public const RECHAZADO = 'rechazado';

    /** El mismo archivo (mismo hash) ya estaba cargado en el periodo. */
    public const DUPLICADO = 'duplicado';

    /** Etiquetas en español de cada estado para la pantalla. */
    public const LABELS = [
        self::EN_PROCESO => 'En proceso',
        self::COMPLETADO => 'Cargado',
        self::RECHAZADO => 'Rechazado',
        self::DUPLICADO => 'Ya estaba cargado',
    ];

    protected $table = 'estado_resultados_import_attempts';

    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime', 'validated_at' => 'datetime'];

    /**
     * Errores por archivo/fila/columna detectados en este intento.
     */
    public function errors(): HasMany
    {
        return $this->hasMany(EstadoResultadosImportError::class, 'attempt_id');
    }

    /**
     * Usuario que intentó la carga.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Carga (estado de cuenta publicado) que produjo el intento; null si se rechazó.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosUpload::class, 'upload_id');
    }
}
