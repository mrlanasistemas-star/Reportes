<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo verificable de un movimiento bancario con su contraparte (ERP, NOI u
 * otro movimiento bancario), con importe aplicado y saldo pendiente de ambos lados.
 * Se regenera COMPLETO en cada procesamiento del periodo (idempotente).
 */
class EstadoResultadosReconciliationLink extends Model
{
    protected $table = 'estado_resultados_reconciliation_links';

    protected $guarded = ['id'];

    protected $casts = [
        'evidence' => 'array',
        'delta_days' => 'integer',
    ];
}
