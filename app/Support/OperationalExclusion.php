<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sucursales excluidas de las métricas financieras (cartera, mora, colocación,
 * recuperación, productos) por razones operativas, NO por pertenecer a Región Norte.
 *
 * - CORPORATIVO: no genera créditos, colocación, cartera ni recuperación.
 *   SÍ puede aparecer en gastos.
 * - FALSO: sucursal de prueba/relleno; nunca cuenta.
 * - Cualquier sucursal cuyo nombre contenga "inactiva" o "vacante".
 *
 * Se mantiene separada de RegionNorteFilter para que las salidas de depuración
 * distingan "excluido por Norte" de "excluido por Corporativo/Falso".
 */
class OperationalExclusion
{
    private const NAMES = ['corporativo', 'falso'];

    /**
     * Lista de nombres (en minúsculas) excluidos por razones operativas.
     *
     * @return string[] nombres en minúsculas excluidos por razones operativas (no Norte)
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * Indica si la sucursal se excluye por razones operativas (corporativo, falso,
     * o nombre con "inactiva"/"vacante"; comparación sin distinguir mayúsculas).
     *
     * @param  string  $name  Nombre de la sucursal.
     * @return bool true si debe excluirse de las métricas financieras.
     */
    public static function isExcluded(string $name): bool
    {
        $lower = mb_strtolower(trim($name));
        if (str_contains($lower, 'inactiva') || str_contains($lower, 'vacante')) {
            return true;
        }

        return in_array($lower, self::NAMES, true);
    }

    /**
     * Aplica la exclusión operativa a un query builder: deja fuera corporativo,
     * falso y cualquier sucursal con "inactiva"/"vacante". Las filas sin sucursal
     * (NULL) se conservan.
     *
     * @param  string  $branchNameExpr  Expresión SQL de la columna con el nombre de sucursal (p. ej. 'b.name', 'br.name').
     * @param  object  $query  Query builder (Eloquent o Query\Builder) a filtrar.
     * @return object El mismo builder con las condiciones agregadas.
     */
    public static function applyTo(object $query, string $branchNameExpr = 'b.name'): object
    {
        return $query
            ->where(function ($q) use ($branchNameExpr) {
                $q->whereNull($branchNameExpr)
                    ->orWhereNotIn(DB::raw("LOWER({$branchNameExpr})"), self::NAMES);
            })
            ->whereRaw("({$branchNameExpr} IS NULL OR LOWER({$branchNameExpr}) NOT LIKE ?)", ['%inactiva%'])
            ->whereRaw("({$branchNameExpr} IS NULL OR LOWER({$branchNameExpr}) NOT LIKE ?)", ['%vacante%']);
    }
}
