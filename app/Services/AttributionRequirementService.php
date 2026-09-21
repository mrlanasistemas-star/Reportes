<?php

namespace App\Services;

/**
 * Punto ÚNICO de decisión: ¿una falla de atribución de sucursal/colaborador (Excel de
 * gastos Lendus sin par en el PDF, Financiamiento de Motos/Cascos sin identidad) debe
 * DETENER la generación de un reporte, según su report_type/scope — en vez de duplicar
 * ese criterio con ifs sueltos en cada controlador/job (retoma 21-sep-2026, Parte A,
 * punto 10).
 *
 * EVIDENCIA (auditoría de código, retoma 21-sep-2026): tanto GastosExcelBranchResolverService
 * como FinanciamientoMotosAssignmentService resuelven branch_id/employee_id SOLO sobre
 * gastos_lendus_excel (el Excel complementario). Pero BranchRadiographyCalculator —
 * fuente ÚNICA de OPEX y de "Nómina y Capital Humano", tanto GENERAL como POR SUCURSAL —
 * lee esos mismos gastos EXCLUSIVAMENTE desde gastos_lendus (el PDF): ver
 * resolveLendusIds(), que nunca incluye el Excel, y accumulateGastos()/accumulateNomina(),
 * que solo consultan ese origen. El PDF siempre trae branch_id resuelto al 100% desde su
 * propia columna "Sucursal" (verificado: whereNotNull('branch_id') en el propio resolver
 * nunca excluye filas del PDF). En otras palabras: un gasto del Excel sin contraparte en
 * el PDF NUNCA deja de contar en OPEX/Nómina — para NINGÚN alcance — porque esos totales
 * ni siquiera leen el Excel. Lo único que queda incompleto es el desglose INFORMATIVO
 * derivado del Excel (breakdown por sucursal/gestor en buildExpensesDetail(), atribución
 * de colaborador vía Observación/Justificación — ya no bloqueante desde antes de esta
 * sesión).
 *
 * Por eso requiresBranchAttribution() no bloquea HOY para ningún scope. Se deja como
 * servicio central — en vez de la excepción incondicional que había antes — para que un
 * futuro resolver cuya salida SÍ alimente un total financiero pueda declarar lo
 * contrario sin volver a duplicar el criterio por controlador.
 */
class AttributionRequirementService
{
    /**
     * @param string $reportType 'simple'|'month_vs_month'|'bimester_vs_bimester'|'quarter_vs_quarter'
     * @param string $scope 'general'|'branch'|'employee'
     */
    public function requiresBranchAttribution(
        string $reportType,
        string $scope,
        ?int $branchId = null,
        ?int $employeeId = null,
    ): bool {
        // Ver docblock de la clase — ningún alcance depende hoy de la atribución
        // Excel↔PDF de gastos Lendus / Financiamiento de Motos-Cascos para sus totales
        // financieros (OPEX, Nómina y Capital Humano, EBITDA): esos siempre se calculan
        // desde el PDF, ya resuelto al 100%.
        return false;
    }
}
