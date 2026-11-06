<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;

class EstadoResultadosImportError extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_import_errors';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];
}
