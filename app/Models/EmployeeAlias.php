<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alias (variante de nombre) de un colaborador.
 *
 * Tabla: `employee_aliases`. Cada fila es otra forma en que aparece escrito el
 * nombre de un empleado en las fuentes (NOI, Lendus, ERP, Excel), para que la
 * resolución de identidad lo asocie al mismo `Employee`.
 *
 * Columnas clave:
 * - `alias_name`: nombre tal como viene en la fuente.
 * - `normalized_alias`: nombre normalizado usado para hacer el match.
 * - `source`: origen del alias.
 * - `confidence`: confianza del emparejamiento.
 * - `created_by`: usuario que lo registró (si fue manual).
 */
class EmployeeAlias extends Model
{
    protected $fillable = [
        'employee_id',
        'alias_name',
        'normalized_alias',
        'source',
        'confidence',
        'created_by',
    ];

    /** Colaborador canónico al que pertenece este alias. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
