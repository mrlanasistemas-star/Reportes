<?php

namespace App\Services;

/**
 * Auditoría 07-sep-2026 (frentes 3/4, cierre) — FUENTE ÚNICA de clasificación
 * financiera de un fact_expenses (categoría+concepto+fuente) → ¿es OPEX?,
 * ¿qué tipo?, ¿es elegible para atribuirse a un colaborador?
 *
 * Extraída LITERALMENTE de la regla que ya vivía duplicada en dos lugares:
 *   - BranchRadiographyCalculator::accumulateGastos() (la clasificación
 *     financiera REAL — branch/global gastos_operativos, gastos_empleados_
 *     nomina, excedentes, prestamos_fondea, seguros_lendus_puente).
 *   - ExpenseObservationAttributionService::isEligibleForAttribution() (una
 *     lista manual paralela, con el comentario explícito "espejo deliberado,
 *     no reutilizable" — ya no hace falta que lo sea).
 *
 * NO cambia ninguna regla financiera vigente — es una extracción, no una
 * reescritura. accumulateGastos() sigue haciendo exactamente la misma
 * acumulación (gastos_operativos/gastos_empleados_nomina/excedentes/etc.),
 * solo delega en este servicio la DECISIÓN de a qué grupo pertenece cada fila.
 *
 * Consumido por:
 *   - BranchRadiographyCalculator::accumulateGastos() (branch/global OPEX real)
 *   - ExpenseObservationAttributionService (elegibilidad de atribución a persona)
 *   - RadiographySnapshotBuilder::buildEmployeeExpenseDetail() (OPEX de colaborador)
 *   - Radiography\EmployeesHistoricoExportService (Excel masivo)
 *   - Console\Commands\ReportsAuditExpenseAttributionCommand (auditoría)
 */
class OpexClassificationService
{
    public const TYPE_OPEX                 = 'opex';
    public const TYPE_NOMINA_EMPLEADO      = 'nomina_empleado';
    public const TYPE_NOMINA_COVERED_BY_NOI = 'nomina_covered_by_noi';
    public const TYPE_EXCEDENTE            = 'excedente';
    public const TYPE_FONDEO               = 'fondeo';
    public const TYPE_POLIZAS              = 'polizas';
    public const TYPE_OTHER_EXCLUDED       = 'other_excluded';

    public const SOURCE_LENDUS = 'lendus';
    public const SOURCE_ERP    = 'erp';

    private const EXCEDENTES_CAT = 'Envío de utilidad a corporativo';
    private const FONDEO_CAT     = 'Préstamos Intersucursales';
    private const NOMINA_CAT     = 'Nómina y Capital Humano';

    /**
     * Conceptos dentro de 'Nómina y Capital Humano' (Lendus) que NUNCA cuentan
     * como OPEX: NOMINA, PAGO DE IMSS, DEDUCCIONES (generales), PAGO PRESTAMO Z,
     * ANTICIPO DE NOMINA — ya cubiertos por NOI y por el archivo IMSS oficial
     * (sumarlos aquí duplicaría el gasto). PAGO FINANCIAMIENTO MOTO y COMPRA DE
     * CASCOS también quedan fuera de OPEX aquí — son gasto real de empleado,
     * pero se cuentan vía el bloque dedicado de Motos (gastos_empleados_nomina),
     * no como OPEX genérico. Ver BranchRadiographyCalculator::accumulateGastos().
     */
    private const LENDUS_NOMINA_SKIP_CONCEPTS = [
        'NOMINA', 'PAGO DE IMSS', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO PRESTAMO Z',
        'PAGO FINANCIAMIENTO MOTO', 'COMPRA DE CASCOS', 'ANTICIPO DE NOMINA',
    ];

    /** Pólizas/seguros — excluidas de OPEX para ambas fuentes (Lendus y ERP). */
    private const SEGUROS_LENDUS_CATS = ['Pólizas'];

    /**
     * Etiquetas genéricas que el PDF de Gastos Lendus usa para Financiamiento de
     * Motos/Cascos/Enganche/Anticipo cuando no trae el concepto completo (solo
     * aparece bajo categoría "Gastos Operativos"). Ver accumulateGastos().
     */
    private const GENERIC_NOMINA_LABELS = ['PAGO', 'COMPRA DE', 'ENGANCHE DE', 'ANTICIPO DE'];

    /**
     * @return array{is_opex:bool, type:string, eligible_for_attribution:bool, exclusion_reason:?string}
     */
    public function classify(?string $category, ?string $concept, string $sourceType = self::SOURCE_LENDUS): array
    {
        $catUpper     = mb_strtoupper(trim((string) $category));
        $conceptUpper = preg_replace('/\s+/u', ' ', mb_strtoupper(trim((string) $concept))) ?? mb_strtoupper(trim((string) $concept));

        if ($sourceType === self::SOURCE_ERP) {
            // ERP: regla vigente — se suma COMPLETO a OPEX, sin reclasificar; única
            // excepción es Pólizas (seguros vehiculares/oficina), igual que Lendus.
            if (in_array((string) $category, self::SEGUROS_LENDUS_CATS, true)) {
                return $this->result(false, self::TYPE_POLIZAS, false, 'Pólizas/seguros (ERP) — excluidas de OPEX.');
            }
            return $this->result(true, self::TYPE_OPEX, true, null);
        }

        // ── Fuente Lendus (PDF gastos_lendus y su gemelo gastos_lendus_excel —
        // mismas transacciones, mismas categorías/conceptos) ──────────────────

        if ($catUpper === 'GASTOS OPERATIVOS' && in_array($conceptUpper, self::GENERIC_NOMINA_LABELS, true)) {
            $eligible = $conceptUpper !== 'ANTICIPO DE'; // anticipo no se atribuye a nadie, no es gasto de empresa
            return $this->result(false, self::TYPE_NOMINA_EMPLEADO, $eligible, 'Etiqueta genérica PDF de Motos/Cascos/Enganche/Anticipo — no es OPEX.');
        }

        if ($catUpper === mb_strtoupper(self::EXCEDENTES_CAT) || str_contains($catUpper, 'EXCEDENTE')) {
            return $this->result(false, self::TYPE_EXCEDENTE, false, 'Excedente — envío de utilidad a corporativo.');
        }

        if ($catUpper === mb_strtoupper(self::FONDEO_CAT) || str_contains($catUpper, 'FONDEO') || str_contains($catUpper, 'INTERSUCURSAL')) {
            return $this->result(false, self::TYPE_FONDEO, false, 'Fondeo/préstamo intersucursal — no es gasto operativo.');
        }

        if (in_array((string) $category, self::SEGUROS_LENDUS_CATS, true)) {
            return $this->result(false, self::TYPE_POLIZAS, false, 'Pólizas/seguros — puente a aseguradora, no es OPEX.');
        }

        if ($catUpper === mb_strtoupper(self::NOMINA_CAT)) {
            $isFiniquitoMedico = str_contains($conceptUpper, 'FINIQUITO') || str_contains($conceptUpper, 'MEDICO') || str_contains($conceptUpper, 'MÉDICO');
            if ($isFiniquitoMedico) {
                return $this->result(false, self::TYPE_NOMINA_EMPLEADO, true, 'Finiquito/Gastos médicos — gasto real de nómina, no OPEX genérico.');
            }
            if (in_array($conceptUpper, self::LENDUS_NOMINA_SKIP_CONCEPTS, true)) {
                return $this->result(false, self::TYPE_NOMINA_COVERED_BY_NOI, false, 'Ya cubierto por NOI/IMSS — atribuirlo duplicaría el gasto.');
            }
            // Concepto de Nómina no listado explícitamente — mismo criterio conservador
            // que accumulateGastos(): cualquier cosa bajo esta categoría que no sea
            // Finiquito/Médico se trata como ya cubierta por NOI, nunca como OPEX.
            return $this->result(false, self::TYPE_NOMINA_COVERED_BY_NOI, false, 'Categoría Nómina y Capital Humano no reconocida como gasto puntual — se asume cubierta por NOI.');
        }

        if (str_contains($catUpper, 'NOMINA') || str_contains($catUpper, 'NÓMINA')) {
            return $this->result(false, self::TYPE_NOMINA_COVERED_BY_NOI, false, 'Categoría relacionada a nómina — ya cubierta por NOI/IMSS.');
        }

        return $this->result(true, self::TYPE_OPEX, true, null);
    }

    private function result(bool $isOpex, string $type, bool $eligible, ?string $reason): array
    {
        return [
            'is_opex'                  => $isOpex,
            'type'                     => $type,
            'eligible_for_attribution' => $eligible,
            'exclusion_reason'         => $reason,
        ];
    }
}
