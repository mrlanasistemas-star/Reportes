<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de consultas a la API externa (v1).
 *
 * Tabla: `api_query_logs`. Cada fila registra una petición hecha por un
 * consumidor de API (ApiConsumer): endpoint, método HTTP, código de respuesta,
 * alcance consultado (sucursal/colaborador/periodo) e IP de origen. La escribe
 * el middleware LogApiQuery para auditoría.
 *
 * No usa timestamps automáticos: solo `created_at` (cast `datetime`) se llena
 * explícitamente al registrar.
 */
class ApiQueryLog extends Model
{
    /** Sin `created_at`/`updated_at` automáticos; `created_at` se asigna a mano. */
    public $timestamps = false;

    protected $fillable = [
        'api_consumer_id',
        'endpoint',
        'method',
        'response_status',
        'scope',
        'branch_id',
        'employee_id',
        'period_id',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** Consumidor de API (sistema externo) que hizo la consulta. */
    public function apiConsumer(): BelongsTo
    {
        return $this->belongsTo(ApiConsumer::class);
    }
}
