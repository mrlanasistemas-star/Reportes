<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conciliación autorizada de la sucursal vigente de un colaborador (solo módulo OKR).
 *
 * Tabla: `okr_branch_reconciliations`. Cuando las fuentes (nómina, Lendus,
 * asignaciones) no coinciden en la sucursal de un colaborador, un usuario elige
 * cuál vale para OKR y queda registrado aquí con su evidencia.
 *
 * Columnas clave:
 * - `normalized_name`: nombre normalizado del colaborador.
 * - `branch_id`: sucursal elegida.
 * - `evidence_date` (date): fecha de la evidencia que respalda la decisión.
 * - `chosen_source`: fuente que se tomó como válida.
 * - `sources` (array): lo que decía cada fuente al momento de conciliar.
 * - `notes`/`resolved_by`: notas y usuario que resolvió.
 */
class OkrBranchReconciliation extends Model
{
    protected $fillable = [
        'employee_id', 'normalized_name', 'branch_id', 'evidence_date',
        'chosen_source', 'sources', 'notes', 'resolved_by',
    ];

    protected $casts = [
        'evidence_date' => 'date',
        'sources' => 'array',
    ];

    /** Colaborador cuya sucursal se concilió. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Sucursal elegida como vigente para OKR. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Usuario que autorizó la conciliación. */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
