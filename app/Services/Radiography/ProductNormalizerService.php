<?php

namespace App\Services\Radiography;

/**
 * Resultado de la normalización de un producto de crédito: nombre original,
 * nombre normalizado (p. ej. CRECE12 SAC, i40, s12), familia, plazo y banderas
 * de clasificación (operativo, CRECE, diario, semanal, reestructura, fondeo,
 * seguro, excluido) con el motivo de la regla aplicada.
 */
class ProductNormalizationResult
{
    /**
     * @param  string  $rawProduct  Texto original del producto.
     * @param  string  $normalizedProduct  Nombre normalizado.
     * @param  string  $family  Familia: crece, diario, semanal, operativo, restructura, fondeo, seguro, oficina_producto o unknown.
     * @param  int|null  $term  Plazo (número de pagos) si se determinó.
     * @param  bool  $isOperational  Es producto de crédito operativo.
     * @param  bool  $isCrece  Pertenece a la familia CRECE.
     * @param  bool  $isDaily  Es crédito diario.
     * @param  bool  $isWeekly  Es crédito semanal.
     * @param  bool  $isRestructure  Es reestructura/unificación.
     * @param  bool  $isFunding  Es fondeo/línea (fuente de capital, no producto).
     * @param  bool  $isInsurance  Es seguro/póliza.
     * @param  bool  $isExcluded  Debe excluirse de métricas de producto.
     * @param  string  $reason  Explicación de la regla aplicada.
     */
    public function __construct(
        public readonly string $rawProduct,
        public readonly string $normalizedProduct,
        public readonly string $family,
        public readonly ?int $term,
        public readonly bool $isOperational,
        public readonly bool $isCrece,
        public readonly bool $isDaily,
        public readonly bool $isWeekly,
        public readonly bool $isRestructure,
        public readonly bool $isFunding,
        public readonly bool $isInsurance,
        public readonly bool $isExcluded,
        public readonly string $reason,
    ) {}

    /**
     * @return array<string,mixed> Resultado en formato snake_case (para auditorías/JSON).
     */
    public function toArray(): array
    {
        return [
            'raw_product' => $this->rawProduct,
            'normalized_product' => $this->normalizedProduct,
            'family' => $this->family,
            'term' => $this->term,
            'is_operational' => $this->isOperational,
            'is_crece' => $this->isCrece,
            'is_daily' => $this->isDaily,
            'is_weekly' => $this->isWeekly,
            'is_restructure' => $this->isRestructure,
            'is_funding' => $this->isFunding,
            'is_insurance' => $this->isInsurance,
            'is_excluded' => $this->isExcluded,
            'reason' => $this->reason,
        ];
    }
}

/**
 * Reglas centralizadas de normalización de productos de crédito.
 *
 * Pretende reemplazar las regex dispersas en BranchResolverService, snapshot,
 * importadores y comandos. Actualmente solo lo usa AuditProductsCommand; los
 * importadores siguen usando BranchResolverService::normalizeProduct().
 * Orden de reglas: reestructura → fondeo → seguro (salvo CRECE) → CRECE →
 * DIARIO → SEMANAL → códigos i/s sueltos → productos operativos conocidos →
 * OFICINA MR LANA → operativo por defecto.
 */
class ProductNormalizerService
{
    // ── Patrones de clasificación ──────────────────────────────────────────────

    private const RESTRUCTURE_PATTERN = 'REESTRUCTURA|UNIFICACION|UNIFICACIÓN|MIGRACION|MIGRACIÓN|INSOLUTOS';

    private const FUNDING_PATTERN = 'RECURSOS PROPIOS|FONDEO EXTERNO|LINEA DE CREDITO|LÍNEA DE CRÉDITO|CAPITAL EXTERNO';

    private const INSURANCE_PATTERN = 'SEGURO|PÓLIZA|POLIZA|COBERTURA';

    private const CRECE_PATTERN = 'CRECE';

    private const DAILY_PATTERN = 'DIARIO';

    private const WEEKLY_PATTERN = 'SEMANAL';

    // Productos operativos reales que NO deben reclasificarse
    private const PASSTHROUGH_OPERATIONAL = [
        'A LA MEDIDA',
        'COMADRES',
        'CREDITO COMADRES',
        'PRIORITY',
        'MEMBRESÍA PRIORITY',
        'GANACREDITS',
        'GANACREDIT',
        'CORPORATIVO MENSUAL',
        'CREDITO CORPORATIVO',
        'CRÉDITO CORPORATIVO',
        'PRESTAMO PERSONAL MENSUAL',
        'CREDITO CONSUMO',
    ];

    // OFICINA MR LANA: el nombre del producto es la propia oficina — se acepta tal cual
    // pero se marca para revisión porque no tiene sucursal asignada
    private const OFFICE_PRODUCT_PATTERN = 'OFICINA MR LANA';

    /**
     * Normaliza un nombre de producto crudo aplicando las reglas en orden y
     * devuelve la clasificación completa.
     *
     * @param  string  $rawProduct  Valor de la columna "Producto de crédito".
     * @param  int|null  $term  "Número de pagos" / plazo (p. ej. 12, 24, 40).
     * @param  string|null  $periodicity  "Periodicidad" (SEMANAL / DIARIO / MENSUAL).
     * @param  array  $rawPayload  Contexto adicional de la fila original, si existe (no se usa actualmente).
     * @return ProductNormalizationResult Resultado de la normalización.
     */
    public function normalize(
        string $rawProduct,
        ?int $term = null,
        ?string $periodicity = null,
        array $rawPayload = []
    ): ProductNormalizationResult {
        $raw = trim($rawProduct);
        $upper = strtoupper($raw);

        if ($raw === '') {
            return $this->result('', 'Sin producto', 'unknown', null, false, false, false, false, false, false, false, true, 'Producto vacío');
        }

        // ── 1. Reestructura / no operativo ────────────────────────────────
        if (preg_match('/'.self::RESTRUCTURE_PATTERN.'/i', $upper)) {
            return $this->result($raw, $raw, 'restructura', null, false, false, false, false, true, false, false, true, 'Reestructura/unificación no es producto operativo');
        }

        // ── 2. Funding / línea ───────────────────────────────────────────────
        if (preg_match('/'.self::FUNDING_PATTERN.'/i', $upper)) {
            return $this->result($raw, $raw, 'fondeo', null, false, false, false, false, false, true, false, true, 'Fondeo/línea no es producto operativo, es fuente de capital');
        }

        // ── 3. Seguros ─────────────────────────────────────────────────────
        if (! str_contains($upper, 'CRECE') && preg_match('/'.self::INSURANCE_PATTERN.'/i', $upper)) {
            return $this->result($raw, $raw, 'seguro', null, false, false, false, false, false, false, true, true, 'Seguro/póliza no es producto de crédito operativo');
        }

        // ── 4. Familia CRECE ──────────────────────────────────────────────────
        if (str_contains($upper, 'CRECE')) {
            return $this->normalizeCrece($raw, $upper, $term, $periodicity);
        }

        // ── 5. DIARIO (crédito diario) ─────────────────────────────────────────
        if (str_contains($upper, 'DIARIO')) {
            return $this->normalizeDaily($raw, $upper, $term, $periodicity);
        }

        // ── 6. SEMANAL (crédito semanal) ───────────────────────────────────────
        if (str_contains($upper, 'SEMANAL')) {
            return $this->normalizeWeekly($raw, $upper, $term, $periodicity);
        }

        // ── 7. Códigos i/s sueltos ────────────────────────────────────────────────
        if (preg_match('/^[Ii](20|30|40|60)$/', $raw, $m)) {
            $t = (int) $m[1];

            return $this->result($raw, 'i'.$t, 'diario', $t, true, false, true, false, false, false, false, false, "Código diario explícito i{$t}");
        }
        if (preg_match('/^[Ss](12|16|20|24)$/', $raw, $m)) {
            $t = (int) $m[1];

            return $this->result($raw, 's'.$t, 'semanal', $t, true, false, false, true, false, false, false, false, "Código semanal explícito s{$t}");
        }

        // ── 8. Productos operativos conocidos (se aceptan tal cual) ──────────────────────────────
        foreach (self::PASSTHROUGH_OPERATIONAL as $pt) {
            if (str_contains($upper, strtoupper($pt))) {
                return $this->result($raw, $raw, 'operativo', null, true, false, false, false, false, false, false, false, "Producto operativo conocido: {$pt}");
            }
        }

        // ── 9. OFICINA MR LANA (oficina como producto) ──────────────────────────
        if (str_contains($upper, self::OFFICE_PRODUCT_PATTERN)) {
            return $this->result($raw, $raw, 'oficina_producto', null, true, false, false, false, false, false, false, false, 'Producto = nombre de oficina. Revisar si debe reclasificarse o excluirse.');
        }

        // ── 10. Desconocido: se asume operativo ───────────────────────────────
        return $this->result($raw, $raw, 'operativo', null, true, false, false, false, false, false, false, false, 'Sin regla específica, clasificado operativo por defecto');
    }

    /**
     * Normalización rápida para importadores (devuelve solo el nombre normalizado).
     * Equivale a BranchResolverService::normalizeProduct() pero con las reglas centrales.
     *
     * @param  string  $rawProduct  Producto crudo.
     * @param  int|null  $term  Plazo.
     * @param  string|null  $periodicity  Periodicidad.
     * @return string Nombre normalizado.
     */
    public function normalizeString(string $rawProduct, ?int $term = null, ?string $periodicity = null): string
    {
        return $this->normalize($rawProduct, $term, $periodicity)->normalizedProduct;
    }

    /**
     * Nombre de grupo para la UI: agrupa variantes en la etiqueta canónica de su
     * familia (CRECE12 SAC, CRECE24, i40, s12...).
     *
     * @param  string  $normalizedProduct  Producto ya normalizado.
     * @return string Etiqueta de grupo.
     */
    public function displayGroup(string $normalizedProduct): string
    {
        $upper = strtoupper($normalizedProduct);

        if (preg_match('/^CRECE12\s*SAC/', $upper)) {
            return 'CRECE12 SAC';
        }
        if (preg_match('/^CRECE24\s*SAC/', $upper)) {
            return 'CRECE24 SAC';
        }
        if (preg_match('/^CRECE12/', $upper)) {
            return 'CRECE12';
        }
        if (preg_match('/^CRECE24/', $upper)) {
            return 'CRECE24';
        }
        if (preg_match('/^CRECE/', $upper)) {
            return 'CRECE';
        }
        if (preg_match('/^i(20|30|40|60)$/i', $normalizedProduct, $m)) {
            return 'i'.$m[1];
        }
        if (preg_match('/^s(12|16|20|24)$/i', $normalizedProduct, $m)) {
            return 's'.$m[1];
        }

        return $normalizedProduct;
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Normaliza un producto CRECE: plazo desde el nombre (o el parámetro) y sufijo SAC si aplica.
     *
     * @param  string  $raw  Producto original.
     * @param  string  $upper  Producto en mayúsculas.
     * @param  int|null  $term  Plazo de respaldo.
     * @param  string|null  $periodicity  Periodicidad (no se usa).
     * @return ProductNormalizationResult Resultado familia 'crece'.
     */
    private function normalizeCrece(string $raw, string $upper, ?int $term, ?string $periodicity): ProductNormalizationResult
    {
        $hasSac = str_contains($upper, 'SAC');

        // Plazo: primero del nombre del producto, luego del parámetro $term
        $plazo = $this->extractCreceTerm($upper, $term);

        $normalized = $this->buildCreceNorm($plazo, $hasSac);
        $reason = $plazo !== null
            ? "CRECE con plazo {$plazo}".($hasSac ? ' SAC' : '')." → {$normalized}"
            : "CRECE sin plazo determinado → {$normalized}";

        return $this->result($raw, $normalized, 'crece', $plazo, true, true, false, false, false, false, false, false, $reason);
    }

    /**
     * Plazo CRECE: el número que sigue a "CRECE" en el nombre gana; si no hay, el parámetro $term.
     *
     * @param  string  $upper  Producto en mayúsculas.
     * @param  int|null  $term  Plazo de respaldo.
     * @return int|null Plazo o null.
     */
    private function extractCreceTerm(string $upper, ?int $term): ?int
    {
        // Gana el número explícito en el nombre
        if (preg_match('/CRECE\s*(\d+)/', $upper, $m)) {
            return (int) $m[1];
        }
        // "CRECE 12 ..." → 12
        if (preg_match('/CRECE\s+(\d+)/', $upper, $m)) {
            return (int) $m[1];
        }

        // Respaldo: parámetro term
        return $term;
    }

    /**
     * @param  int|null  $plazo  Plazo CRECE.
     * @param  bool  $hasSac  Si el producto es SAC.
     * @return string Nombre normalizado (CRECE12, CRECE24 SAC, CRECE...).
     */
    private function buildCreceNorm(?int $plazo, bool $hasSac): string
    {
        if ($plazo === 12) {
            return 'CRECE12'.($hasSac ? ' SAC' : '');
        }
        if ($plazo === 24) {
            return 'CRECE24'.($hasSac ? ' SAC' : '');
        }
        if ($plazo !== null) {
            return "CRECE{$plazo}".($hasSac ? ' SAC' : '');
        }

        return 'CRECE'.($hasSac ? ' SAC' : '');
    }

    /**
     * Normaliza crédito diario a i20/i30/i40/i60: código embebido, número después de
     * DIARIO o el plazo del parámetro. Sin plazo se conserva el nombre y se marca para revisión.
     *
     * @param  string  $raw  Producto original.
     * @param  string  $upper  Producto en mayúsculas.
     * @param  int|null  $term  Número de pagos.
     * @param  string|null  $periodicity  Periodicidad (no se usa).
     * @return ProductNormalizationResult Resultado familia 'diario'.
     */
    private function normalizeDaily(string $raw, string $upper, ?int $term, ?string $periodicity): ProductNormalizationResult
    {
        // Código embebido estilo "I20" o "DIARIO I20"
        if (preg_match('/[Ii](20|30|40|60)/', $upper, $m)) {
            $t = (int) $m[1];

            return $this->result($raw, 'i'.$t, 'diario', $t, true, false, true, false, false, false, false, false, "Código diario i{$t} embebido en nombre");
        }

        // "DIARIO 40" / "DIARIO 30" — número justo después de DIARIO (sin prefijo I)
        if (preg_match('/DIARIO\s+(20|30|40|60)\b/', $upper, $m)) {
            $t = (int) $m[1];

            return $this->result($raw, 'i'.$t, 'diario', $t, true, false, true, false, false, false, false, false, "DIARIO + plazo {$t} en nombre → i{$t}");
        }

        // parámetro term (p. ej. num_payments=40 con periodicidad=DIARIO)
        if ($term !== null && in_array($term, [20, 30, 40, 60], true)) {
            return $this->result($raw, 'i'.$term, 'diario', $term, true, false, true, false, false, false, false, false, "DIARIO + num_pagos={$term} → i{$term}");
        }

        // "CREDITO DIARIO" sin plazo — no se puede normalizar, se marca para revisión
        $reason = 'DIARIO sin plazo determinado — re-importar con columna Número de pagos para resolución correcta';

        return $this->result($raw, $raw, 'diario', null, true, false, true, false, false, false, false, false, $reason);
    }

    /**
     * Normaliza crédito semanal a s12/s16/s20/s24: número después de SEMANAL,
     * número suelto (si no es CRECE) o el plazo del parámetro.
     *
     * @param  string  $raw  Producto original.
     * @param  string  $upper  Producto en mayúsculas.
     * @param  int|null  $term  Número de pagos.
     * @param  string|null  $periodicity  Periodicidad (no se usa).
     * @return ProductNormalizationResult Resultado familia 'semanal'.
     */
    private function normalizeWeekly(string $raw, string $upper, ?int $term, ?string $periodicity): ProductNormalizationResult
    {
        // Número embebido en el nombre ("CREDITO SEMANAL 12")
        if (preg_match('/SEMANAL\s*(\d+)\b/', $upper, $m)) {
            $t = (int) $m[1];
            if (in_array($t, [12, 16, 20, 24], true)) {
                return $this->result($raw, 's'.$t, 'semanal', $t, true, false, false, true, false, false, false, false, "SEMANAL + plazo {$t} embebido → s{$t}");
            }
        }

        // "SEMANAL" genérico con el número en otra posición (notación "s12/s16")
        if (preg_match('/\b(12|16|20|24)\b/', $upper, $m)) {
            $t = (int) $m[1];
            $hasCrece = str_contains($upper, 'CRECE');
            if (! $hasCrece) {
                return $this->result($raw, 's'.$t, 'semanal', $t, true, false, false, true, false, false, false, false, "SEMANAL + plazo {$t} en nombre → s{$t}");
            }
        }

        // parámetro term
        if ($term !== null && in_array($term, [12, 16, 20, 24], true)) {
            return $this->result($raw, 's'.$term, 'semanal', $term, true, false, false, true, false, false, false, false, "SEMANAL + num_pagos={$term} → s{$term}");
        }

        return $this->result($raw, $raw, 'semanal', null, true, false, false, true, false, false, false, false, 'SEMANAL sin plazo determinado');
    }

    /**
     * Construye el objeto de resultado con argumentos nombrados.
     *
     * @return ProductNormalizationResult Resultado.
     */
    private function result(
        string $raw, string $normalized, string $family, ?int $term,
        bool $operational, bool $crece, bool $daily, bool $weekly,
        bool $restructure, bool $funding, bool $insurance, bool $excluded,
        string $reason
    ): ProductNormalizationResult {
        return new ProductNormalizationResult(
            rawProduct: $raw,
            normalizedProduct: $normalized,
            family: $family,
            term: $term,
            isOperational: $operational,
            isCrece: $crece,
            isDaily: $daily,
            isWeekly: $weekly,
            isRestructure: $restructure,
            isFunding: $funding,
            isInsurance: $insurance,
            isExcluded: $excluded,
            reason: $reason,
        );
    }
}
