<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Par de colaboradores que un usuario indicó que NO son la misma persona.
 *
 * Tabla: `employee_match_rejections`. Cada fila evita que la deduplicación
 * canónica (CanonicalEmployeeResolver, PeopleDirectoryService) vuelva a fusionar
 * dos registros de Employee con nombres parecidos.
 *
 * Columnas clave:
 * - `employee_id_a`/`employee_id_b`: los dos colaboradores del par.
 * - `period_id`: periodo en que se registró el rechazo (opcional).
 * - `pair_key`: llave canónica "minId-maxId" (ver pairKey()) para búsquedas únicas.
 * - `reason`/`created_by`: motivo y usuario que lo registró.
 */
class EmployeeMatchRejection extends Model
{
    protected $table = 'employee_match_rejections';

    protected $fillable = [
        'employee_id_a',
        'employee_id_b',
        'period_id',
        'pair_key',
        'reason',
        'created_by',
    ];

    /**
     * Llave canónica del par en formato "minId-maxId" — úsala para búsquedas únicas.
     * El orden de los argumentos no importa: (5, 3) y (3, 5) dan "3-5".
     *
     * @param  int  $a  ID de un colaborador.
     * @param  int  $b  ID del otro colaborador.
     * @return string Llave del par.
     */
    public static function pairKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    /** Primer colaborador del par rechazado. */
    public function employeeA(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id_a');
    }

    /** Segundo colaborador del par rechazado. */
    public function employeeB(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id_b');
    }

    /** Periodo en que se registró el rechazo. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
