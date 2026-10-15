<?php

namespace App\Services\Catalog;

/**
 * Catálogo OFICIAL de sucursales y rutas de MR LANA (16-oct-2026) — fuente
 * ÚNICA ruta → sucursal para importaciones, colaboradores, OKR, colocación y
 * reportes por sucursal. Proporcionado por la empresa; reemplaza las
 * equivalencias heredadas que lo contradecían (p. ej. APIZ-CEN → TLAXCALA,
 * CENTRO B/C/D → CÓRDOBA, rutas de MIACATLÁN → CUERNAVACA). Los datos ya
 * importados (columna "Oficina" de Lendus) coinciden con este catálogo.
 *
 * Reglas:
 *  - Una ruta NO es una sucursal; toda ruta pertenece a UNA sucursal oficial.
 *  - Una ruta INACTIVA se conserva para histórico pero nunca respalda una
 *    asignación operativa NUEVA.
 *  - Solo las 11 sucursales de OKR_BRANCHES son operativas para OKR. TENANGO
 *    DEL VALLE se conserva como referencia histórica (no se agrega como
 *    sucursal operativa nueva y sus movimientos nunca se reasignan a otra).
 *  - Una ruta desconocida devuelve null: jamás se convierte en sucursal nueva
 *    ni se adivina por parecido.
 *
 * Los nombres de sucursal coinciden con `branches.name` (sin acentos donde la
 * BD no los tiene: CORDOBA, MIACATLAN, SAN LUIS POTOSI).
 */
final class BranchRouteCatalog
{
    /** Sucursales operativas del alcance OKR (once). */
    public const OKR_BRANCHES = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA', 'IXTLAHUACA',
        'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI', 'TLAXCALA', 'TULA',
    ];

    /** Estructuras que nunca son sucursal operativa en OKR (históricos intactos). */
    public const NON_OPERATIVE = ['MATRIZ', 'MATRIZ NORTE', 'REGION Q1', 'REGION Q2', 'REGION Q3', 'AGUASCALIENTES'];

    /** Sucursal => [ruta => activa]. */
    private const ROUTES = [
        'CUERNAVACA' => [
            'VOLANTE CUERNAVACA' => true, 'YAUTEPEC' => true, 'BARONA' => true, 'JIUTEPEC' => true, 'HUITZILAC' => true,
            'CUERNAVACA SUR' => true, 'CUERNAVACA CENTRO' => true, 'CUERNAVACA GTE' => true, 'TEMIXCO' => true,
            'ZAPATA' => true, 'CUERNAVACA ORIENTE' => true, 'CUERNAVACA SUBGTE' => true,
        ],
        'MIACATLAN' => [
            'PNT-IXTLA' => true, 'EML-ZAPATA' => true, 'VOLANTE MIACATLAN' => true, 'ZACATEPEC' => false, 'MAZATEPEC' => true,
            'JOJUTLA' => false, 'XOCHITEPEC' => true, 'XOXOCOTLA - JOJUTLA' => true, 'TETECALA' => true,
            'MIACATLAN GERENCIA' => true, 'MIACATLAN SUBGERENCIA' => true,
        ],
        'ATLACOMULCO' => [
            'VOLANTE ATLACOMULCO' => true, 'ATLACOMULCO CENTRO' => true, 'ACAMBAY' => true, 'SAN FELIPE' => true, 'TECOAC' => true,
            'TEMASCALCINGO' => true, 'SAN LORENZO TLACOTEPEC' => true, 'SAN BARTOLO MORELOS' => true,
            'ATLACOMULCO GERENCIA' => true, 'ATLACOMULCO SUBGERENCIA' => true,
        ],
        'IXTLAHUACA' => [
            'TEMOAYA' => true, 'SAN PEDRO' => true, 'IXTLAHUACA CENTRO' => true, 'JIQUIPILCO' => true, 'IXT - SANTA ANA' => true,
            'ALMOLOYA DE JUARES' => true, 'IXTLAHUACA GERENCIA' => true, 'IXTLAHUACA SUBGERENCIA' => true,
        ],
        'SAN LUIS POTOSI' => [
            'VOLANTE SLP2' => true, 'SAN LUIS SUBGERENCIA' => true, 'SOLEDAD' => true, 'VOLANTE SLP' => true, 'MUÑOZ' => true,
            'MAGUEYES' => true, 'ORIENTE-SLP' => true, 'CENTRO-SLP' => true, 'ZONA SUR' => true, 'SAN LUIS GERENCIA' => true,
        ],
        'ATLIXCO' => [
            'ATLIX-CEN' => true, 'ATLIX-SUBGTE' => true, 'VOLANTE ATLIXCO' => true, 'ATLIX-SUR' => true, 'ATLIX-NOR' => true,
            'TOCH' => true, 'TLAX' => true, 'SACHOL' => true, 'HUAQ' => true, 'SJ' => true, 'MAT-CEN' => true,
            'ATLIX-MATP' => true, 'ATLIX-MATS' => true, 'ATLIX-GTE' => true, 'CHOL' => true,
        ],
        'CORDOBA' => [
            'VOLANTE CORDOBA' => true, 'CORDOBA FORTIN' => true, 'CORDOBA PADELMA' => true, 'VILLA JARA' => true,
            'CORDOBA CENTRO' => true, 'CORDOBA CENTRO 2' => true, '20 DE NOVIEMBRE' => true, 'CALZADAS' => true,
            'CHOCAMAN' => true, 'COSCOMATEPEC' => true, 'CUITLAHUAC' => true, 'FORTN' => true, 'LOPEZ ARIAS' => true,
            'PASO DEL MACHO' => true, 'SAN ROMAN' => true, 'TECAMA TOXPAN' => true, 'YANGA' => true,
            'CORDOBA GERENCIA' => true, 'CORDOBA SUBGERENCIA' => true, 'CORDOBA ALAMEDA' => true,
        ],
        'HUAMANTLA' => [
            'GRAJ' => false, 'APIZ-CEN' => true, 'HUAMANTLA (VENCIDOS)' => true, 'HUAMANTLA (CASTIGO)' => true,
            'APIX-NORESTE' => true, 'APIZACO' => true, 'HUAM-CEN' => false, 'HUAM-NOR' => false, 'HUAM-SUR' => false,
            'HUAM-EST' => false, 'CUAPI' => false, 'ORIEN' => false, 'LIBR' => true, 'ACAJ' => true, 'APIZ-NOR' => true,
            'APIX-SUR' => true, 'XALO' => false, 'TLAXCO' => false, 'HUAM-GTE' => true, 'IXTENGO' => false,
            'TEAC' => false, 'SAN COSME' => false, 'HUAM-SUBGTE' => true, 'VOLANTE HUAMANTLA' => true,
        ],
        'ORIZABA' => [
            'CENTRO C' => true, 'VOLANTE ORIZABA' => true, 'GRUPALES SUR' => true, 'GRUPALES NORTE' => true,
            'VOLANTE 2 ORIZABA' => true, 'ATZACAN' => true, 'CENTRO A' => true, 'CENTRO B' => true, 'CENTRO D' => true,
            'CIUDAD MENDOZA' => true, 'CUAUTLAPAN / DOS RIOS' => true, 'IXHUATLANCILLO' => true,
            'IXTACZOQUITLAM NORTE' => true, 'IXTACZOQUITLAM SUR' => true, 'JALAPILLA' => true, 'LA PERLA' => true,
            'NOGALES' => true, 'ORIZABA SUR' => true, 'RIO BLANCO NORTE' => true, 'RIO BLANCO SUR' => true,
            'ORIZABA GERENCIA' => true, 'ORIZABA SUBGERENCIA' => true,
        ],
        'TULA' => [
            'VOLANTE TULA' => true, 'ATITALAQUIA' => true, 'TULA GERENCIA' => true, 'TULA SUBGERENCIA' => true,
            'TLAXCOAPAN' => true, 'TLAHUELILPAN' => true, 'TEZONTEPEC' => true, 'QUMA' => true, 'TULA CENTRO' => true,
            'TEPEJI' => true, 'SANTA ANA' => true, 'CRUZ AZUL' => true, 'ATOTONILCO' => true,
        ],
        'TLAXCALA' => [
            'VOLANTE TLAXCALA' => true, 'VOLANTE 2 TLAXCALA' => true, 'TLAXCALA CENTRO' => true, 'CONTLA' => true,
            'NATIVITAS' => true, 'ZACATELCO' => true, 'VILLA ALTA' => true, 'CHIAUTEMPAN' => true, 'TOTOLAC' => true,
            'SAN MATIAS' => true, 'SAN PABLO' => true, 'TETLANOHCAN' => true, 'TLAXCALA GERENCIA' => true,
            'TLAXCALA SUBGERENCIA' => true,
        ],
        // Histórico / pendiente de validación — NO operativa en OKR.
        'TENANGO DEL VALLE' => [
            'VOLANTE TENANGO' => true, 'CAPULHUAC' => true, 'CHAPULTEPEC' => true, 'TENANGO-2' => true,
            'TENANGO GERENCIA' => true, 'TENANGO SUBGERENCIA' => true, 'TENANCINGO-1' => true, 'TENANGO-1' => true,
            'CALIMAYA-2' => true, 'SANTIAGO' => true, 'METEPEC-1' => false, 'METEPEC-2' => true,
        ],
    ];

    /**
     * Variantes VERIFICADAS de captura (clave normalizada => ruta oficial).
     * Solo errores/formatos comprobados — nunca coincidencias por parecido.
     */
    private const ALIASES = [
        'APIZ CEN' => 'APIZ-CEN',
        'FORTIN' => 'FORTN',
        'XOCOCOTLA - JOJUTLA' => 'XOXOCOTLA - JOJUTLA',
        'IXTACZOQUITLAN NORTE' => 'IXTACZOQUITLAM NORTE',
        'IXTACZOQUITLAN SUR' => 'IXTACZOQUITLAM SUR',
        'ALMOLOYA DE JUAREZ' => 'ALMOLOYA DE JUARES',
        'CUAUTLAPAN/DOS RIOS' => 'CUAUTLAPAN / DOS RIOS',
        'HUAMANTLA VENCIDOS' => 'HUAMANTLA (VENCIDOS)',
        'HUAMANTLA CASTIGO' => 'HUAMANTLA (CASTIGO)',
    ];

    /** Nombre de sucursal (o variante con acentos) => nombre oficial. */
    private const BRANCH_ALIASES = [
        'CORDOBA' => 'CORDOBA', 'MIACATLAN' => 'MIACATLAN', 'SAN LUIS POTOSI' => 'SAN LUIS POTOSI', 'SLP' => 'SAN LUIS POTOSI',
        'TENANGO' => 'TENANGO DEL VALLE', 'TENANGO DEL VALLE' => 'TENANGO DEL VALLE',
    ];

    /** @var array<string, array{route:string, branch:string, active:bool}>|null */
    private static ?array $index = null;

    /** Clave comparable: mayúsculas, sin acentos, sin "(INACTIVA)", espacios colapsados y uniformes en guiones/diagonales. */
    public static function normalize(?string $value): string
    {
        $value = mb_strtoupper(trim((string) $value), 'UTF-8');
        $value = strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U']);
        $value = preg_replace('/\(\s*INACTIVA\s*\)/', '', $value) ?? $value;
        $value = preg_replace('/\s*-\s*/', '-', $value) ?? $value;
        $value = preg_replace('/\s*\/\s*/', '/', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /** @return array<string, array{route:string, branch:string, active:bool}> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (self::ROUTES as $branch => $routes) {
            foreach ($routes as $route => $active) {
                $index[self::normalize($route)] = ['route' => $route, 'branch' => $branch, 'active' => $active];
            }
        }
        foreach (self::ALIASES as $alias => $route) {
            $index[self::normalize($alias)] ??= $index[self::normalize($route)];
        }

        return self::$index = $index;
    }

    /** @return array{route:string, branch:string, active:bool}|null */
    public static function route(?string $raw): ?array
    {
        $key = self::normalize($raw);

        return $key === '' ? null : (self::index()[$key] ?? null);
    }

    /** Sucursal oficial de una ruta del catálogo, o null si la ruta es desconocida. */
    public static function branchForRoute(?string $raw): ?string
    {
        return self::route($raw)['branch'] ?? null;
    }

    /**
     * Sucursal oficial para un nombre que puede ser ruta O sucursal (p. ej.
     * "CHOL", "Córdoba", "ATLIX-GTE"). Null si no está en el catálogo.
     */
    public static function officialBranchFor(?string $raw): ?string
    {
        $key = self::normalize($raw);
        if ($key === '') {
            return null;
        }
        if (isset(self::BRANCH_ALIASES[$key])) {
            return self::BRANCH_ALIASES[$key];
        }
        foreach (array_keys(self::ROUTES) as $branch) {
            if (self::normalize($branch) === $key) {
                return $branch;
            }
        }

        return self::branchForRoute($raw);
    }

    public static function isInactiveRoute(?string $raw): bool
    {
        $route = self::route($raw);

        return $route !== null && $route['active'] === false;
    }

    public static function isOkrOperative(?string $branchName): bool
    {
        $key = self::normalize($branchName);

        return $key !== '' && in_array($key, array_map([self::class, 'normalize'], self::OKR_BRANCHES), true);
    }

    /** @return array<string, bool> rutas de una sucursal (ruta => activa). */
    public static function routesFor(string $branch): array
    {
        return self::ROUTES[self::officialBranchFor($branch) ?? ''] ?? [];
    }

    /** @return array<string, array<string, bool>> */
    public static function all(): array
    {
        return self::ROUTES;
    }
}
