<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lógica de exclusión de la Región Norte (Chihuahua, Durango, Aguascalientes).
 *
 * La Región Norte no forma parte de la operación que mide la radiografía, por lo
 * que se filtra en dos capas:
 *
 * 1. Nombres de sucursal INEQUÍVOCOS: siempre se excluyen en TODAS las métricas.
 *    Solo nombres que son 100% Norte y no pueden compartirse con sucursales
 *    operativas. Se usa en cartera, mora y colocación.
 *
 * 2. Detección PRECISA por payload: se usa en recuperación. Excluye Norte solo
 *    cuando hay evidencia clara en raw_payload: prefijo del acreditado (CH/DG +
 *    dígitos), texto de zone_name (Chihuahua/Durango/AGS) o que la sucursal esté
 *    en la lista inequívoca. Las sub-rutas genéricas (CENTRO, VILLAS,
 *    MANANTIAL…) NO se excluyen salvo que exista alguna de esas señales.
 *
 * Corporativo/Falso NO se tratan aquí (ver OperationalExclusion).
 */
class RegionNorteFilter
{
    /**
     * Nombres de sucursal 100% no operativos e inequívocos.
     * Pueden compararse de forma segura con LOWER(b.name) exacto.
     */
    private const UNAMBIGUOUS = [
        // Solo identificadores de Región Norte — corporativo/falso están en OperationalExclusion
        'matriz norte',
        'region norte',
        // Chihuahua (nivel superior) + sub-rutas claramente Norte
        'chihuahua',
        'vis-nor',
        'vill-pab',
        'cer-gran',
        'orie-sanguil',
        'dovsto',
        'jimenez',
        'lon-cen',
        'volante ch',
        'san-guille',
        'gerencia ch',
        // Durango (nivel superior) + sub-rutas claramente Norte
        'durango',
        'victoria de durango',
        'dom-arrie',
        'joyas',
        'santuario',
        'morga',
        'cipres',
        // Aguascalientes (nivel superior) + sub-rutas claramente Norte
        'aguascalientes',
        'sur poniente',
        'cen-sur',
        'jesu-noro',
        'saf-nore',
        'sur-ori',
        'cen-este',
        'nor-oriente',
        'ags-gte',
    ];

    /**
     * Nombres ambiguos: existen en Norte Y en sucursales operativas.
     * NO se excluyen solo por nombre; se requiere evidencia en el payload.
     * Se listan aquí solo como documentación.
     */
    private const AMBIGUOUS_SUB_ROUTES = [
        'centro', 'villas', 'manantial', 'factor', 'universal',
        'supervisor', 'pri', 'industrial', 'santa fe',
    ];

    /**
     * Nombres de sucursal (minúsculas) a excluir en consultas de cartera / mora / colocación.
     *
     * @return string[] Lista inequívoca de sucursales Norte.
     */
    public static function names(): array
    {
        return self::UNAMBIGUOUS;
    }

    /**
     * Indica si un nombre de sucursal pertenece a la Región Norte (lista inequívoca,
     * comparación exacta en minúsculas). NO incluye Corporativo/Falso (ver OperationalExclusion).
     *
     * @param  string  $name  Nombre de la sucursal.
     * @return bool true si es sucursal Norte.
     */
    public static function isNorte(string $name): bool
    {
        $lower = mb_strtolower(trim($name));

        return in_array($lower, self::UNAMBIGUOUS, true);
    }

    // ── Helpers SQL para recuperación (fact_recoveries) ───────────────────────

    /**
     * Agrega al query las condiciones para conservar solo recuperaciones (PAGO) de
     * sucursales OPERATIVAS sobre fact_recoveries.
     *
     * Una recuperación es Norte (y se excluye) cuando ocurre CUALQUIERA de:
     *   a) el nombre de sucursal es inequívocamente Norte;
     *   b) el código de accredited_name empieza con CH / DG seguido de dígito;
     *   c) zone_name contiene Chihuahua / Durango / Aguascalientes / Matriz Norte / Región Norte.
     *
     * Usa JSON_EXTRACT y REGEXP (sintaxis MySQL).
     *
     * @param  object  $query  Query builder sobre fact_recoveries.
     * @param  string  $rAlias  Alias de fact_recoveries (por defecto 'r').
     * @param  string  $bAlias  Alias de branches unida por r.branch_id (por defecto 'b').
     * @return object El mismo builder con los filtros aplicados.
     */
    public static function applyRecoveryFilter(object $query, string $rAlias = 'r', string $bAlias = 'b'): object
    {
        $names = self::UNAMBIGUOUS;

        return $query
            // Excluye sucursales Norte inequívocas
            ->where(function ($q) use ($bAlias, $names) {
                $q->whereNull("{$bAlias}.name")
                    ->orWhereNotIn(DB::raw("LOWER({$bAlias}.name)"), $names);
            })
            // Excluye por prefijo Norte del acreditado (CH/DG + dígito = cliente Norte)
            ->whereRaw(
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rAlias}.raw_payload, '$.accredited_name')), '') NOT REGEXP ?",
                ['^(CH|DG)[0-9]']
            )
            // Excluye por zone_name con palabras clave de la Región Norte
            ->whereRaw(
                "UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rAlias}.raw_payload, '$.zone_name')), '')) NOT REGEXP ?",
                ['CHIHUAHUA|DURANGO|AGUASCALIENTES|MATRIZ NORTE|REGION NORTE']
            );
    }

    /**
     * Expresión SQL (para whereRaw/SELECT) que vale 1 cuando una fila de
     * recuperación es Norte (debe excluirse). Útil en desgloses de depuración.
     * Aplica las mismas tres reglas que applyRecoveryFilter().
     *
     * @param  string  $rAlias  Alias de fact_recoveries.
     * @param  string  $bAlias  Alias de branches.
     * @return string Fragmento SQL booleano.
     */
    public static function norteDetectionExpr(string $rAlias = 'r', string $bAlias = 'b'): string
    {
        $inList = implode(',', array_map(fn ($n) => "'".addslashes($n)."'", self::UNAMBIGUOUS));

        return "
            (
                ({$bAlias}.name IS NOT NULL AND LOWER({$bAlias}.name) IN ({$inList}))
                OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rAlias}.raw_payload,'$.accredited_name')),'') REGEXP '^(CH|DG)[0-9]'
                OR UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rAlias}.raw_payload,'$.zone_name')),'')) REGEXP 'CHIHUAHUA|DURANGO|AGUASCALIENTES|MATRIZ NORTE|REGION NORTE'
            )
        ";
    }

    /**
     * Devuelve el motivo de detección Norte de un registro, del lado PHP (para
     * ciclos de depuración/auditoría). Evalúa en orden: sucursal inequívoca,
     * prefijo CH/DG del acreditado y zona Norte.
     *
     * @param  string|null  $branchName  Nombre de la sucursal del registro.
     * @param  array  $payload  raw_payload ya decodificado.
     * @return string|null null si no es Norte; texto con el motivo si lo es.
     */
    public static function detectNorteReason(?string $branchName, array $payload): ?string
    {
        $lower = mb_strtolower(trim($branchName ?? ''));

        if (in_array($lower, self::UNAMBIGUOUS, true)) {
            return 'Sucursal Norte inequívoca: '.$branchName;
        }

        $accredited = strtoupper(trim($payload['accredited_name'] ?? ''));
        if (preg_match('/^(CH|DG)\d/', $accredited)) {
            $prefix = substr($accredited, 0, 2);
            $region = $prefix === 'CH' ? 'Chihuahua' : 'Durango';

            return "Prefijo acreditado {$prefix}: {$region} ({$accredited})";
        }

        $zone = strtoupper(trim($payload['zone_name'] ?? ''));
        if ($zone && preg_match('/(CHIHUAHUA|DURANGO|AGUASCALIENTES|MATRIZ NORTE|REGION NORTE)/i', $zone)) {
            return "Zona Norte: {$zone}";
        }

        return null;
    }
}
