<?php

namespace App\Services;

use App\Models\Branch;
use App\Services\Catalog\BranchRouteCatalog;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve la sucursal REAL a partir del prefijo del código de contrato/promotor o
 * del nombre de ruta/oficina, con la misma lógica de equivalencias del Excel de la empresa.
 *
 * Prioridad: los prefijos de 3 letras se evalúan antes que los de 2 (CH, DG) para
 * evitar colisiones parciales (replica la fórmula SI() anidada del Excel original).
 * Además normaliza nombres de producto, identifica productos excluidos/seguros y
 * expone los catálogos de sucursales operativas (13 sucursales del reporte financiero).
 *
 * Lo usan los importadores Lendus, el resolvedor de identidades, la asignación de
 * sucursales y los cálculos/exportaciones de la radiografía. Mantiene una caché en
 * memoria de registros Branch por instancia.
 */
class BranchResolverService
{
    // Se evalúan PRIMERO — prefijos de 3 letras
    private const PREFIX_3 = [
        'AGS' => 'AGUASCALIENTES',
        'ATL' => 'ATLIXCO',
        'SJR' => 'SAN JUAN DEL RÍO',
        'MIA' => 'MIACATLAN',
        'HUA' => 'HUAMANTLA',
        'ORI' => 'ORIZABA',
        'COR' => 'CORDOBA',
        'TLX' => 'TLAXCALA',
        'TLN' => 'TULANCINGO',
        'PAC' => 'PACHUCA',
        'ATC' => 'ATLACOMULCO',
        'IXT' => 'IXTLAHUACA',
        'TNG' => 'TENANGO DEL VALLE',
        'TLA' => 'TULA',
        'CUE' => 'CUERNAVACA',
        'SLP' => 'SAN LUIS POTOSI',
    ];

    // Se evalúan DESPUÉS de los de 3 letras — prefijos de 2 letras
    private const PREFIX_2 = [
        'CH' => 'CHIHUAHUA',
        'DG' => 'DURANGO',
    ];

    // Productos excluidos de las métricas financieras
    private const EXCLUDED_PRODUCT_PATTERNS = [
        'REESTRUCTURA',
        'UNIFICACION',
        'UNIFICACIONES',
    ];

    // Productos que son seguros (excluidos de cartera/colocación)
    private const INSURANCE_PRODUCT_PATTERNS = [
        'SEGURO',
    ];

    /** @var array<string, Branch> Caché en memoria: nombre normalizado => Branch. */
    private array $branchCache = [];

    /**
     * Vacía la caché en memoria de sucursales (útil entre importaciones o en pruebas).
     */
    public function clearCache(): void
    {
        $this->branchCache = [];
    }

    /**
     * Devuelve el nombre de la sucursal real a partir de un código de contrato/promotor.
     *
     * Acepta el formato heredado (prefijo al inicio, p. ej. "ORI09247") y el nuevo con
     * año de 2 dígitos antes de las letras (p. ej. "25CUE0667939"). Devuelve null si el
     * prefijo no se reconoce.
     *
     * @param  string  $code  Código de contrato o promotor.
     * @return string|null Nombre de sucursal en MAYÚSCULAS o null.
     *
     * Ejemplos:
     *   "ORI09247"  → "ORIZABA"
     *   "HUA071663" → "HUAMANTLA"
     *   "TLA07553"  → "TULA"
     *   "SLP123"    → "SAN LUIS POTOSI"
     *   "CH001"     → "CHIHUAHUA"
     */
    public function resolveBranchNameFromCode(string $code): ?string
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        // Formato heredado: ORI09247, HUA071663, SLP123
        $prefix3 = substr($code, 0, 3);
        if (isset(self::PREFIX_3[$prefix3])) {
            return self::PREFIX_3[$prefix3];
        }

        $prefix2 = substr($code, 0, 2);
        if (isset(self::PREFIX_2[$prefix2])) {
            return self::PREFIX_2[$prefix2];
        }

        // Formato nuevo: 25CUE0667939, 24ORI032294 — año de 2 dígitos antes de las letras de sucursal.
        if (strlen($code) > 4 && ctype_digit(substr($code, 0, 2))) {
            $stripped = substr($code, 2);
            $p3 = substr($stripped, 0, 3);
            if (isset(self::PREFIX_3[$p3])) {
                return self::PREFIX_3[$p3];
            }
            $p2 = substr($stripped, 0, 2);
            if (isset(self::PREFIX_2[$p2])) {
                return self::PREFIX_2[$p2];
            }
        }

        return null;
    }

    /**
     * Devuelve el prefijo de 3 o 2 letras reconocido al inicio del código, o null.
     *
     * A diferencia de resolveBranchNameFromCode(), no contempla el formato nuevo con
     * año de 2 dígitos al inicio.
     *
     * @param  string  $code  Código de contrato o promotor.
     * @return string|null Prefijo en MAYÚSCULAS o null si no se reconoce.
     */
    public function extractPrefix(string $code): ?string
    {
        $code = strtoupper(trim($code));

        $prefix3 = substr($code, 0, 3);
        if (isset(self::PREFIX_3[$prefix3])) {
            return $prefix3;
        }

        $prefix2 = substr($code, 0, 2);
        if (isset(self::PREFIX_2[$prefix2])) {
            return $prefix2;
        }

        return null;
    }

    /**
     * Busca o crea el registro Branch de la sucursal real resuelta desde un código.
     *
     * Efecto secundario: puede insertar en branches (ver findOrCreateBranchByName()).
     *
     * @param  string  $code  Código de contrato o promotor.
     * @return Branch|null null si el prefijo no se reconoce.
     */
    public function findOrCreateBranchByCode(string $code): ?Branch
    {
        $branchName = $this->resolveBranchNameFromCode($code);

        if (! $branchName) {
            return null;
        }

        return $this->findOrCreateBranchByName($branchName);
    }

    /**
     * Resuelve de forma segura cualquier nombre crudo de ruta/sucursal a un Branch operativo.
     *
     * - Primero pasa el nombre por resolveRealBranchFromRoute().
     * - Solo crea/devuelve un Branch si el nombre resuelto es una de las sucursales
     *   operativas con hoja propia (isSheetBranch(), hoy 13).
     * - Para rutas desconocidas devuelve null (nunca crea) y registra un warning en log.
     *
     * @param  string|null  $rawName  Nombre de ruta u oficina tal como viene del archivo.
     */
    public function resolveOfficialBranchByName(?string $rawName): ?Branch
    {
        if (! $rawName) {
            return null;
        }

        $officialName = $this->resolveRealBranchFromRoute($rawName);

        if (! $officialName) {
            Log::warning('BranchResolverService: unknown route/branch — skipping create.', ['raw' => $rawName]);

            return null;
        }

        if (! $this->isSheetBranch($officialName)) {
            return null;
        }

        return $this->findOrCreateBranchByName($officialName);
    }

    /**
     * Busca o crea un registro Branch por su nombre real.
     *
     * Usa normalized_name (minúsculas, sin acentos) como llave para evitar duplicados
     * y guarda el resultado en la caché en memoria. Si crea, genera un código único a
     * partir del nombre (agrega sufijo "_N" si choca) y lo deja activo.
     *
     * Efecto secundario: puede insertar en la tabla branches.
     *
     * @param  string  $branchName  Nombre de la sucursal.
     */
    public function findOrCreateBranchByName(string $branchName): Branch
    {
        $normalized = $this->normalizeText($branchName);

        if (isset($this->branchCache[$normalized])) {
            return $this->branchCache[$normalized];
        }

        $existing = Branch::query()
            ->where('normalized_name', $normalized)
            ->first();

        if ($existing) {
            return $this->branchCache[$normalized] = $existing;
        }

        $code = $this->buildBranchCode($branchName);

        // Manejar la rara colisión de código agregando un sufijo
        $finalCode = $code;
        $attempt = 0;
        while (Branch::query()->where('code', $finalCode)->exists()) {
            $attempt++;
            $finalCode = substr($code, 0, 55).'_'.$attempt;
        }

        $branch = Branch::query()->create([
            'code' => $finalCode,
            'name' => $branchName,
            'normalized_name' => $normalized,
            'is_active' => true,
        ]);

        return $this->branchCache[$normalized] = $branch;
    }

    // Productos que siempre pasan sin cambios — nunca se normalizan
    private const SPECIAL_PASSTHROUGH_PATTERN = 'COMADRES|PRIORITY|GANACREDIT|OFICINA MR LANA|CORPORATIVO MENSUAL|CREDITO CORPORATIVO|A LA MEDIDA';

    /**
     * Normaliza un nombre de producto crudo usando Número de pagos y Periodicidad.
     *
     * Vacío → "Sin producto". La familia CRECE se agrupa por plazo (CRECE12/CRECE24,
     * con " SAC" si aplica) sin inventar plazo cuando no se puede determinar.
     *
     * Reglas:
     * - "MR LANA DIARIO i20/i30/i40/i60" + num_payments → "i20"/"i30"/"i40"/"i60"
     * - "CREDITO DIARIO I20" style → i20
     * - "CREDITO SEMANAL 12" style → s12
     * - Periodicidad SEMANAL + num_payments=12/16/20/24 → "s12","s16","s20","s24"
     * - Periodicidad DIARIO + num_payments=20/30/40/60 → "i20","i30","i40","i60"
     * - CRECE12 SAC, CRECE24 SAC, productos especiales → se conservan tal cual
     *
     * @param  string  $product  Nombre de producto tal como viene del archivo.
     * @param  int|null  $numPayments  Número de pagos del crédito, si se conoce.
     * @param  string|null  $periodicity  Periodicidad (SEMANAL/DIARIO…), si se conoce.
     * @return string Producto normalizado (o el original si no aplica ninguna regla).
     */
    public function normalizeProduct(string $product, ?int $numPayments = null, ?string $periodicity = null): string
    {
        $product = trim($product);
        if ($product === '') {
            return 'Sin producto';
        }

        $upper = strtoupper($product);

        // Special named products that must NEVER be altered
        if (preg_match('/'.self::SPECIAL_PASSTHROUGH_PATTERN.'/i', $upper)) {
            return $product;
        }

        // ── CRECE family normalization ────────────────────────────────────────
        // Groups all CRECE variants by plazo and SAC flag.
        // Rules:
        //   CRECE + 12 (explicit in name or numPayments) → CRECE12 (+ SAC if present)
        //   CRECE + 24 → CRECE24
        //   CRECE with no determinable plazo → leave as CRECE (no grouping error)
        if (str_contains($upper, 'CRECE')) {
            $hasSac = str_contains($upper, 'SAC');

            // Plazo explicit in product name ("CRECE12", "CRECE 12", "CRECE 12 CON...", "CRECE 12 MAX...")
            if (preg_match('/CRECE\s*(\d+)/', $upper, $m)) {
                $plazo = (int) $m[1];
                if ($plazo === 12) {
                    return 'CRECE12'.($hasSac ? ' SAC' : '');
                }
                if ($plazo === 24) {
                    return 'CRECE24'.($hasSac ? ' SAC' : '');
                }

                return "CRECE{$plazo}".($hasSac ? ' SAC' : '');
            }

            // Plazo from numPayments parameter (weekly product: 12 weeks = CRECE12)
            if ($numPayments !== null) {
                if ($numPayments === 12) {
                    return 'CRECE12'.($hasSac ? ' SAC' : '');
                }
                if ($numPayments === 24) {
                    return 'CRECE24'.($hasSac ? ' SAC' : '');
                }
            }

            // Already in canonical form ("CRECE12", "CRECE24", "CRECE12 SAC", "CRECE24 SAC")
            if (preg_match('/^CRECE(12|24)( SAC)?$/i', $product)) {
                return strtoupper(trim($product));
            }

            // CRECE with no plazo info — return as-is (do NOT invent a plazo)
            return $product;
        }

        // MR LANA DIARIO / LANA DIARIO / CREDITO DIARIO with multi-option slash notation
        if ((str_contains($upper, 'DIARIO') && (str_contains($upper, 'LANA') || str_contains($upper, 'CREDITO') || str_contains($upper, 'MR')))
            || preg_match('/\bi\d+\s*\/\s*i\d+/i', $product)
        ) {
            if ($numPayments !== null && in_array($numPayments, [20, 30, 40, 60], true)) {
                return 'i'.$numPayments;
            }
        }

        // MR LANA SEMANAL / CREDITO SEMANAL with multi-option slash notation
        if ((str_contains($upper, 'SEMANAL') && (str_contains($upper, 'LANA') || str_contains($upper, 'CREDITO') || str_contains($upper, 'MR')))
            || preg_match('/\bs\d+\s*\/\s*s\d+/i', $product)
        ) {
            if ($numPayments !== null && in_array($numPayments, [12, 16, 20, 24], true)) {
                return 's'.$numPayments;
            }
        }

        // "CREDITO SEMANAL 12" pattern — num embedded in product name
        if (str_contains($upper, 'SEMANAL') && preg_match('/\b(\d+)\b/', $upper, $m)) {
            $n = (int) $m[1];
            if (in_array($n, [12, 16, 20, 24], true)) {
                $hasSpecificName = preg_match('/CRECE|A LA MEDIDA|COMERCIAL|REESTRUCTURA|UNIFICACION/i', $upper);
                if (! $hasSpecificName) {
                    return 's'.$n;
                }
            }
        }

        // "CREDITO DIARIO I20" pattern — i-code embedded in product name (with I prefix)
        if (str_contains($upper, 'DIARIO') && preg_match('/[Ii](\d+)/', $upper, $m)) {
            $n = (int) $m[1];
            if (in_array($n, [20, 30, 40, 60], true)) {
                return 'i'.$n;
            }
        }

        // "DIARIO 40" / "DIARIO 30" — bare number after DIARIO keyword (no I prefix)
        if (str_contains($upper, 'DIARIO') && preg_match('/DIARIO\s+(20|30|40|60)\b/', $upper, $m)) {
            return 'i'.(int) $m[1];
        }

        // CREDITO DIARIO + numPayments → normalize if numPayments provided and no i-code in name
        if (str_contains($upper, 'DIARIO') && $numPayments !== null && in_array($numPayments, [20, 30, 40, 60], true)) {
            $hasICode = preg_match('/[Ii](20|30|40|60)/', $upper);
            if (! $hasICode) {
                return 'i'.$numPayments;
            }
        }

        // Semanal + standard payment counts → normalize only if no specific product name
        if ($periodicity !== null && strtoupper(trim($periodicity)) === 'SEMANAL' && $numPayments !== null) {
            if (in_array($numPayments, [12, 16, 20, 24], true)) {
                $hasSpecificName = preg_match(
                    '/CRECE|A LA MEDIDA|COMERCIAL|REESTRUCTURA|UNIFICACION|CRECE\d/i',
                    $upper
                );
                if (! $hasSpecificName) {
                    return 's'.$numPayments;
                }
            }
        }

        // Diario + standard payment counts → normalize only if no specific product name
        if ($periodicity !== null && strtoupper(trim($periodicity)) === 'DIARIO' && $numPayments !== null) {
            if (in_array($numPayments, [20, 30, 40, 60], true)) {
                $hasSpecificName = preg_match(
                    '/CRECE|A LA MEDIDA|COMERCIAL|REESTRUCTURA|UNIFICACION/i',
                    $upper
                );
                if (! $hasSpecificName) {
                    return 'i'.$numPayments;
                }
            }
        }

        // Bare diario code: "I20", "I30", "I40", "I60" → normalize to lowercase
        if (preg_match('/^[Ii](20|30|40|60)$/', $product)) {
            return 'i'.substr($product, 1);
        }

        // Bare semanal code: "S12", "S16", "S20", "S24" → normalize to lowercase
        if (preg_match('/^[Ss](12|16|20|24)$/', $product)) {
            return 's'.substr($product, 1);
        }

        return $product;
    }

    /**
     * Indica si un producto debe excluirse de las métricas financieras principales
     * (contiene REESTRUCTURA, UNIFICACION o UNIFICACIONES).
     *
     * @param  string  $product  Nombre de producto.
     */
    public function isExcludedProduct(string $product): bool
    {
        $upper = strtoupper(trim($product));

        foreach (self::EXCLUDED_PRODUCT_PATTERNS as $pattern) {
            if (str_starts_with($upper, $pattern) || str_contains($upper, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si un producto es un seguro (contiene SEGURO); se excluye de cartera/colocación.
     *
     * @param  string  $product  Nombre de producto.
     */
    public function isInsuranceProduct(string $product): bool
    {
        $upper = strtoupper(trim($product));

        foreach (self::INSURANCE_PRODUCT_PATTERNS as $pattern) {
            if (str_starts_with($upper, $pattern) || str_contains($upper, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resuelve el nombre de la sucursal real a partir de un nombre de ruta/oficina.
     *
     * Prioridad: 1) prefijo del código (si se envía); 2a) catálogo oficial
     * BranchRouteCatalog; 2) tabla explícita ruta → sucursal (llaves sin acentos en
     * MAYÚSCULAS); 3) el nombre es un código corto de 2–3 letras; 4) el nombre empieza
     * con el nombre de una sucursal real. Devuelve null si no hay equivalencia conocida.
     *
     * @param  string|null  $routeName  Nombre de ruta u oficina.
     * @param  string|null  $code  Código de contrato/promotor opcional (tiene prioridad).
     * @return string|null Nombre de sucursal real o null.
     */
    public function resolveRealBranchFromRoute(?string $routeName, ?string $code = null): ?string
    {
        // 1. El prefijo del código gana sobre todo
        if ($code) {
            $resolved = $this->resolveBranchNameFromCode($code);
            if ($resolved) {
                return $resolved;
            }
        }

        if (! $routeName) {
            return null;
        }

        // 2a. Catálogo OFICIAL de sucursales y rutas (16-oct-2026) — fuente única
        //     proporcionada por la empresa; prevalece sobre las equivalencias
        //     heredadas de abajo (que en varias rutas la contradecían).
        $official = BranchRouteCatalog::officialBranchFor($routeName);
        if ($official !== null) {
            return $official;
        }

        // Normalizar: mayúsculas + sin acentos para que valores como "Fortín" coincidan con "FORTIN"
        $upper = $this->normalizeRouteKey($routeName);

        // 2. Tabla explícita ruta → sucursal real (se evalúa ANTES que el prefijo aproximado)
        //    Todas las llaves deben ir en MAYÚSCULAS sin acentos.
        static $routeMap = [
            // ── Orizaba area ─────────────────────────────────────────────────
            'ORIZABA' => 'ORIZABA',
            'ORIZABA SUR' => 'ORIZABA',
            'ORIZABA NORTE' => 'ORIZABA',
            'ORIZABA CENTRO' => 'ORIZABA',
            'RIO BLANCO' => 'ORIZABA',
            'RIO BLANCO NORTE' => 'ORIZABA',
            'RIO BLANCO SUR' => 'ORIZABA',
            'LA PERLA' => 'ORIZABA',
            'IXTACZOQUITLAN' => 'ORIZABA',
            'IXTACZOQUITLAN SUR' => 'ORIZABA',
            'IXTACZOQUITLAN NORTE' => 'ORIZABA',
            'IXTACZOQUITLAN CENTRO' => 'ORIZABA',
            'IXHUATANCILLO' => 'ORIZABA',
            'IXHUATANCILLO SUR' => 'ORIZABA',
            'FORTIN' => 'ORIZABA',
            'NOGALES' => 'ORIZABA',
            'NOGALES SUR' => 'ORIZABA',
            'NOGALES NORTE' => 'ORIZABA',
            'CIUDAD MENDOZA' => 'ORIZABA',
            'RAFAEL DELGADO' => 'ORIZABA',
            'MOTZORONGO' => 'ORIZABA',
            'TLILAPAN' => 'ORIZABA',
            'JALAPILLA' => 'ORIZABA',
            'LOPEZ ARIAS' => 'ORIZABA',
            'LOPEZ MATEOS' => 'ORIZABA',
            // ── Cordoba area ─────────────────────────────────────────────────
            'CORDOBA' => 'CORDOBA',
            'CORDOBA CENTRO' => 'CORDOBA',
            'CENTRO B' => 'CORDOBA',
            'CENTRO C' => 'CORDOBA',
            'CENTRO D' => 'CORDOBA',
            'COSCOMATEPEC' => 'CORDOBA',
            'COSCOMATEPEC DE BRAVO' => 'CORDOBA',
            'CHOCAMAN' => 'CORDOBA',
            'AMATLÁN DE LOS REYES' => 'CORDOBA',
            'AMATLAN DE LOS REYES' => 'CORDOBA',
            'YANGA' => 'CORDOBA',
            // ── Cuernavaca area ──────────────────────────────────────────────
            'CUERNAVACA' => 'CUERNAVACA',
            'CUERNAVACA GTE' => 'CUERNAVACA',
            'CUERNAVACA ORIENTE' => 'CUERNAVACA',
            'CUERNAVACA NORTE' => 'CUERNAVACA',
            'CUERNAVACA SUR' => 'CUERNAVACA',
            'YAUTEPEC' => 'CUERNAVACA',
            'JIUTEPEC' => 'CUERNAVACA',
            'CUAUTLA' => 'CUERNAVACA',
            'CUAUTLAPAN' => 'CUERNAVACA',
            'DOS RIOS' => 'CUERNAVACA',
            'CUAUTLAPAN / DOS RIOS' => 'CUERNAVACA',
            'CUAUTLAPAN/DOS RIOS' => 'CUERNAVACA',
            'VILLA ALTA' => 'CUERNAVACA',
            'IZACAN' => 'CUERNAVACA',
            'IZTACAN' => 'CUERNAVACA',
            'PASO DEL MACHO' => 'CUERNAVACA',
            'XOCOCOTLA' => 'CUERNAVACA',
            'JOJUTLA' => 'CUERNAVACA',
            'XOCOCOTLA - JOJUTLA' => 'CUERNAVACA',
            'XOCOCOTLA-JOJUTLA' => 'CUERNAVACA',
            // ── Huamantla ────────────────────────────────────────────────────
            'HUAMANTLA' => 'HUAMANTLA',
            'HUAMANTLA (VENCIDOS)' => 'HUAMANTLA',
            'HUAMANTLA VENCIDOS' => 'HUAMANTLA',
            // ── San Juan del Río ─────────────────────────────────────────────
            'SJR' => 'SAN JUAN DEL RÍO',
            'SAN JUAN DEL RIO' => 'SAN JUAN DEL RÍO',
            'SAN JUAN DEL RIO' => 'SAN JUAN DEL RÍO',
            'SAN JUAN DEL RIO CENTRO' => 'SAN JUAN DEL RÍO',
            // ── San Luis Potosí ──────────────────────────────────────────────
            'SLP' => 'SAN LUIS POTOSI',
            'SAN LUIS POTOSI' => 'SAN LUIS POTOSI',
            'SAN LUIS POTOSI CENTRO' => 'SAN LUIS POTOSI',
            // ── Ixtlahuaca ───────────────────────────────────────────────────
            'IXTLAHUACA' => 'IXTLAHUACA',
            'IXTLAHUACA CENTRO' => 'IXTLAHUACA',
            // ── Tenango del Valle ────────────────────────────────────────────
            'TENANGO' => 'TENANGO DEL VALLE',
            'TENANGO DEL VALLE' => 'TENANGO DEL VALLE',
            // ── Tula / Hidalgo area ──────────────────────────────────────────
            'TULA' => 'TULA',
            'TULA CENTRO' => 'TULA',
            'TULA DE ALLENDE' => 'TULA',
            'TEZONTEPEC' => 'TULA',
            'TEZONTEPEC DE ALDAMA' => 'TULA',
            'APIZ CEN' => 'TLAXCALA',
            'APIZACO' => 'TLAXCALA',
            'APIZACO CENTRO' => 'TLAXCALA',
            '20 DE NOVIEMBRE' => 'TLAXCALA',
            'APIZ-CEN' => 'TLAXCALA',
            'APIZ-NOR' => 'TLAXCALA',
            'APIX-SUR' => 'TLAXCALA',
            'CHIAUTEMPAN' => 'TLAXCALA',
            'CONTLA' => 'TLAXCALA',
            'TECOAC' => 'TLAXCALA',
            'TETLANOHCAN' => 'TLAXCALA',
            'ZACATELCO' => 'TLAXCALA',
            'TEPEJI' => 'TULA',
            'TLAXCOAPAN' => 'TULA',
            'ATITALAQUIA' => 'TULA',
            // ── Others (self-map) ────────────────────────────────────────────
            'ATLACOMULCO' => 'ATLACOMULCO',
            'ATLIXCO' => 'ATLIXCO',
            'MIACATLAN' => 'MIACATLAN',
            'TLAXCALA' => 'TLAXCALA',
            'AGUASCALIENTES' => 'AGUASCALIENTES',
            'CHIHUAHUA' => 'CHIHUAHUA',
            'DURANGO' => 'DURANGO',
            'PACHUCA' => 'PACHUCA',
            'TULANCINGO' => 'TULANCINGO',
            // ── Corporativo ──────────────────────────────────────────────────
            'CORPORATIVO' => 'CORPORATIVO',
            'CORP' => 'CORPORATIVO',
            // ── Orizaba: typo/spelling variants ──────────────────────────────
            'FORTN' => 'ORIZABA',
            'IXTACZOQUITLAM' => 'ORIZABA',
            'IXTACZOQUITLAM SUR' => 'ORIZABA',
            'IXTACZOQUITLAM NORTE' => 'ORIZABA',
            'IXTACZOQUITLAM CENTRO' => 'ORIZABA',
            'IXHUATLANCILLO' => 'ORIZABA',
            'IXHUATLANCILLO SUR' => 'ORIZABA',
            // ── Cordoba extra sub-offices ─────────────────────────────────────
            'CORDOBA ALAMEDA' => 'CORDOBA',
            'CORDOBA CENTRO 2' => 'CORDOBA',
            'CORDOBA FORTIN' => 'CORDOBA',
            'CORDOBA PADELMA' => 'CORDOBA',
            'ATZACAN' => 'CORDOBA',
            // ── Atlixco abbreviated routes ────────────────────────────────────
            'ATLIX-GTE' => 'ATLIXCO',
            'ATLIX-MATP' => 'ATLIXCO',
            'ATLIX-NOR' => 'ATLIXCO',
            // ── San Luis Potosi routes ────────────────────────────────────────
            'CENTRO-SLP' => 'SAN LUIS POTOSI',
            'ORIENTE-SLP' => 'SAN LUIS POTOSI',
            'SOLEDAD' => 'SAN LUIS POTOSI',
            // ── San Juan del Rio routes ───────────────────────────────────────
            'SJR II' => 'SAN JUAN DEL RÍO',
            'SJR 2' => 'SAN JUAN DEL RÍO',
            'ESEQUIEL MONTES' => 'SAN JUAN DEL RÍO',
            // ── Rutas huérfanas mapeadas por employee_branch_assignments ──────
            // Confirmadas: el promotor de cada ruta tiene asignación exacta a la ciudad
            'ATOTONILCO' => 'TULA',            // 2 emp → TULA, HIDALGO
            'CRUZ AZUL' => 'TULA',            // 1 emp → TULA, HIDALGO
            'CUITLAHUAC' => 'CORDOBA',         // 1 emp → CORDOBA, VERACRUZ
            'CALZADAS' => 'CORDOBA',         // 1 emp → CORDOBA, VERACRUZ
            'TECAMA TOXPAN' => 'CORDOBA',         // 1 emp → CORDOBA, VERACRUZ
            'MAGUEYES' => 'SAN LUIS POTOSI', // 2 emp → SLP
            'MUNOZ' => 'SAN LUIS POTOSI', // 2 emp → SLP (sin acento)
            'MUÑOZ' => 'SAN LUIS POTOSI', // 2 emp → SLP
            'CHOL' => 'ATLIXCO',         // 1 emp → ATLIXCO, PUEBLA
            'MAT-CEN' => 'ATLIXCO',         // 1 emp → ATLIXCO, PUEBLA
            'TOCH' => 'ATLIXCO',         // 1 emp → ATLIXCO, PUEBLA
            'ACAJ' => 'HUAMANTLA',       // 1 emp → HUAMANTLA, TLAXCALA
            'LIBR' => 'HUAMANTLA',       // 1 emp → HUAMANTLA, HIDALGO
            'BARONA' => 'CUERNAVACA',      // 2 emp → CUERNAVACA, MORELOS
            'SAN PABLO' => 'TLAXCALA',        // 1 emp → TLAXCALA
            'SAN FELIPE' => 'ATLACOMULCO',     // 1 emp → ATLACOMULCO, EDO MEX
            'SAN LORENZO TLACOTEPEC' => 'ATLACOMULCO',    // 1 emp → ATLACOMULCO, EDO MEX
            'SANTIAGO' => 'TENANGO DEL VALLE', // 1 emp → TENANGO DEL VALLE
            // ── Cuernavaca extra routes ───────────────────────────────────────
            'TEMIXCO' => 'CUERNAVACA',
            'XOCHITEPEC' => 'CUERNAVACA',
            'HUITZILAC' => 'CUERNAVACA',
            'XOXOCOTLA - JOJUTLA' => 'CUERNAVACA',
            'XOXOCOTLA  - JOJUTLA' => 'CUERNAVACA',
            'XOXOCOTLA-JOJUTLA' => 'CUERNAVACA',
            'PNT-IXTLA' => 'CUERNAVACA',
            // ── Miacatlan routes ──────────────────────────────────────────────
            'MAZATEPEC' => 'MIACATLAN',
            'TETECALA' => 'MIACATLAN',
            // ── Ixtlahuaca routes ─────────────────────────────────────────────
            'IXT - SANTA ANA' => 'IXTLAHUACA',
            'JIQUIPILCO' => 'IXTLAHUACA',
            'ALMOLOYA DE JUARES' => 'IXTLAHUACA',
            'ALMOLOYA DE JUAREZ' => 'IXTLAHUACA',
            'TEMOAYA' => 'IXTLAHUACA',
            // ── Tenango del Valle routes ──────────────────────────────────────
            'TENANGO-1' => 'TENANGO DEL VALLE',
            'TENANCINGO-1' => 'TENANGO DEL VALLE',
            'CALIMAYA-2' => 'TENANGO DEL VALLE',
            'CAPULHUAC' => 'TENANGO DEL VALLE',
            'METEPEC-2' => 'TENANGO DEL VALLE',
            'SAN BARTOLO MORELOS' => 'TENANGO DEL VALLE',
        ];

        if (isset($routeMap[$upper])) {
            return $routeMap[$upper];
        }

        // 3. El nombre de ruta podría ser un código corto (p. ej. "ORI", "TLA", "SJR", "CH")
        //    Solo se intenta con códigos exactos de 2–3 caracteres para evitar falsos como CHOL → CHIHUAHUA.
        if (strlen($upper) <= 3) {
            $fromCode = $this->resolveBranchNameFromCode($upper);
            if ($fromCode) {
                return $fromCode;
            }
        }

        // 4. Prefijo parcial: el nombre de ruta empieza con el nombre de una sucursal real
        foreach (array_values(array_merge(self::PREFIX_3, self::PREFIX_2)) as $realBranch) {
            if (str_starts_with($upper, $this->normalizeRouteKey($realBranch))) {
                return $realBranch;
            }
        }

        return null;
    }

    /**
     * Pasa una llave de ruta a MAYÚSCULAS y quita acentos del español para que
     * "Fortín" y "FORTIN" coincidan con las llaves sin acento de la tabla de rutas.
     *
     * @param  string  $value  Texto de ruta/sucursal.
     */
    private function normalizeRouteKey(string $value): string
    {
        $value = trim(mb_strtoupper($value, 'UTF-8'));

        return strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);
    }

    /**
     * Devuelve el catálogo completo prefijo → nombre de sucursal (3 letras y luego 2 letras).
     *
     * @return array<string, string>
     */
    public function getCatalog(): array
    {
        $catalog = [];
        foreach (self::PREFIX_3 as $prefix => $name) {
            $catalog[$prefix] = $name;
        }
        foreach (self::PREFIX_2 as $prefix => $name) {
            $catalog[$prefix] = $name;
        }

        return $catalog;
    }

    /**
     * Lista en MAYÚSCULAS de las sucursales operativas reales (todas las del catálogo
     * de prefijos; excluye rutas y CORPORATIVO). Incluye sucursales que no van al
     * reporte financiero (p. ej. AGUASCALIENTES, CHIHUAHUA, DURANGO, PACHUCA).
     *
     * @return array<int, string>
     */
    public function realOperationalBranches(): array
    {
        return array_values(array_unique(array_values(array_merge(self::PREFIX_3, self::PREFIX_2))));
    }

    /**
     * Lista en MAYÚSCULAS de sucursales asignables a colaboradores (operativas + CORPORATIVO).
     *
     * @return array<int, string>
     */
    public function realAssignableBranches(): array
    {
        $branches = $this->realOperationalBranches();
        $branches[] = 'CORPORATIVO';

        return $branches;
    }

    /**
     * Indica si el nombre es una sucursal operativa real (del catálogo de prefijos).
     * Rutas como FORTIN, CENTRO B, JALAPILLA, etc. devuelven false.
     *
     * @param  string  $name  Nombre a evaluar (se ignoran mayúsculas y acentos).
     */
    public function isRealOperationalBranch(string $name): bool
    {
        $key = $this->normalizeRouteKey($name);
        $realKeys = array_map(fn ($n) => $this->normalizeRouteKey($n), $this->realOperationalBranches());

        return in_array($key, $realKeys, true);
    }

    /**
     * Indica si el nombre es una sucursal real o CORPORATIVO.
     * Se usa para decidir si un nombre de sucursal es reportable.
     *
     * @param  string  $name  Nombre a evaluar.
     */
    public function isRealReportBranch(string $name): bool
    {
        if ($this->isRealOperationalBranch($name)) {
            return true;
        }

        return $this->normalizeRouteKey($name) === 'CORPORATIVO';
    }

    // Las 13 sucursales operativas incluidas en el reporte financiero.
    // SAN JUAN DEL RÍO depende del periodo: se incluye si tiene movimiento en el periodo.
    private const OPERATIVE_SHEET_BRANCHES = [
        'ATLACOMULCO',
        'ATLIXCO',
        'CORDOBA',
        'CUERNAVACA',
        'HUAMANTLA',
        'IXTLAHUACA',
        'MIACATLAN',
        'ORIZABA',
        'SAN JUAN DEL RÍO',
        'SAN LUIS POTOSI',
        'TENANGO DEL VALLE',
        'TLAXCALA',
        'TULA',
    ];

    /**
     * Devuelve las 13 sucursales operativas del reporte financiero.
     *
     * SAN JUAN DEL RÍO depende del periodo (se incluye si tiene movimiento; esa decisión
     * la toma quien consume la lista). Excluye AGUASCALIENTES, CHIHUAHUA, DURANGO y
     * CORPORATIVO — ninguna cuenta para cartera, colocación, recuperación ni mora.
     *
     * @return array<int, string>
     */
    public function operativeFinancialBranches(): array
    {
        return self::OPERATIVE_SHEET_BRANCHES;
    }

    /**
     * Indica si la sucursal debe tener su propia hoja en el Excel (una de las 13 operativas).
     * Excluye CHIHUAHUA, DURANGO, CORPORATIVO, TULANCINGO, PACHUCA, etc.
     *
     * @param  string  $name  Nombre a evaluar (se ignoran mayúsculas y acentos).
     */
    public function isSheetBranch(string $name): bool
    {
        $key = $this->normalizeRouteKey($name);
        foreach (self::OPERATIVE_SHEET_BRANCHES as $branch) {
            if ($this->normalizeRouteKey($branch) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza un nombre para usarlo como normalized_name de branches:
     * minúsculas, sin acentos y con espacios colapsados.
     *
     * @param  string  $value  Nombre de sucursal.
     */
    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );

        return preg_replace('/\s+/', ' ', $value) ?? trim($value);
    }

    /**
     * Construye el código de una sucursal nueva: MAYÚSCULAS sin acentos, cualquier
     * carácter no alfanumérico a "_", máximo 60 caracteres. Si queda vacío usa
     * "BR_" + 8 caracteres del md5 del nombre.
     *
     * @param  string  $branchName  Nombre de sucursal.
     */
    private function buildBranchCode(string $branchName): string
    {
        $code = strtoupper($branchName);
        $code = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ', 'á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N', 'A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $code,
        );
        $code = preg_replace('/[^A-Z0-9]+/', '_', $code) ?? $code;
        $code = trim($code, '_');

        if ($code === '') {
            $code = 'BR_'.substr(md5($branchName), 0, 8);
        }

        return substr($code, 0, 60);
    }
}
