<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Services\Api\ApiScopeResolver;
use App\Services\Api\ApiValidation;
use App\Services\Api\EmployeeBranchHistoryResolver;
use App\Services\Api\FinancialMetricResolver;
use App\Services\Efectividad\EfectividadCobranzaAprobadaCalculator;
use App\Services\Efectividad\EfectividadCobranzaSourceFingerprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/collections/effectiveness — Fase 1.3 exige "distinguiendo
 * versiones". Fase 11 (sesión anterior): la versión "approved" se conectó al
 * adaptador real (fact_portfolios + fact_recoveries → calculador aprobado).
 *
 * Auditoría 2026-10-12 (Fase 1/2/9) — rediseño completo de `resolveApproved()`:
 *
 *  1. (Fase 2) YA NO recalcula en vivo en cada GET. Antes invocaba
 *     `EfectividadCobranzaAprobadaReportService::generate()` por cada
 *     request — cartera + histórico completo de ingresos + el calculador
 *     entero, cada vez. Ahora LEE el último `EfectividadCobranzaRun` exitoso
 *     ya generado (vía el módulo web/Job en cola) para ese period_id+branch_id
 *     exacto. Si no existe, responde `not_available` — nunca recalcula desde
 *     un GET, nunca devuelve cero fingiendo que es el resultado real.
 *  2. (Fase 9) Detecta snapshots obsoletos comparando el fingerprint
 *     guardado contra el estado actual de las fuentes
 *     (EfectividadCobranzaSourceFingerprintService) — `status=stale` en vez
 *     de presentarlo silenciosamente como vigente.
 *  3. (Fase 1) BUG CRÍTICO corregido: antes, para scope=employee, se buscaba
 *     la primera fila cuyo PROMOTOR coincidiera por nombre normalizado — sin
 *     verificar que esa fila perteneciera realmente a la sucursal de ese
 *     colaborador en ese periodo. Dos colaboradores homónimos en sucursales
 *     distintas, o un colaborador trasladado, podían recibir el resultado de
 *     otra persona. Ahora:
 *       a. Se resuelve la sucursal VERIFICADA del colaborador para ESE
 *          periodo exacto vía `EmployeeBranchHistoryResolver::resolveForPeriod()`
 *          (employee_branch_assignments — la misma fuente que ya protege
 *          contra IDOR en `ApiScopeResolver`, nunca "la asignación más
 *          reciente"). Si no hay asignación conocida → `not_attributable`.
 *       b. Se lee el snapshot generado EXACTAMENTE para esa sucursal (nunca
 *          el snapshot general, que mezclaría oficinas) — si no existe,
 *          `not_available` (nunca se cae a un snapshot "parecido").
 *       c. Solo entonces se busca la fila por nombre normalizado DENTRO de
 *          ese snapshot ya acotado a la sucursal verificada — la coincidencia
 *          de nombre deja de ser la única defensa contra homónimos, es la
 *          última verificación sobre un universo ya correcto.
 */
class CollectionsController extends Controller
{
    public function effectiveness(
        Request $request,
        FinancialMetricResolver $resolver,
        ApiScopeResolver $scopeResolver,
        EmployeeBranchHistoryResolver $employeeBranchHistoryResolver,
        EfectividadCobranzaSourceFingerprintService $fingerprintService,
        EfectividadCobranzaAprobadaCalculator $calculator,
    ): JsonResponse {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        $periodId = ApiValidation::requiredId($request, 'period_id');
        $period = Period::query()->findOrFail($periodId);
        $scope  = $scopeResolver->resolve($request, $consumer, $period);

        $legacy = $resolver->resolve('collections_effectiveness_legacy', $period, $scope['type'], $scope['branch_id'], $scope['employee_id']);

        $approved = $scope['type'] === 'employee'
            ? $this->resolveApprovedForEmployee($period, $scope, $employeeBranchHistoryResolver, $fingerprintService, $calculator)
            : $this->resolveApprovedSnapshot($period, $scope['branch_id'], $fingerprintService);

        return response()->json([
            'period_id' => $period->id,
            'scope'     => $scope,
            'versions'  => [
                'legacy'   => $legacy + ['version' => 'legacy_vigente_atrasado_vencido'],
                'approved' => $approved,
            ],
        ]);
    }

    /**
     * Lee (nunca recalcula) el último snapshot exitoso para period_id+branchId
     * exactos. `branchId=null` = alcance general (snapshot generado sin
     * sucursal específica) — nunca se suman snapshots de sucursales para
     * simular el general, nunca se parte un snapshot general para simular uno
     * de sucursal.
     */
    private function resolveApprovedSnapshot(Period $period, ?int $branchId, EfectividadCobranzaSourceFingerprintService $fingerprintService): array
    {
        $run = EfectividadCobranzaRun::latestSuccessFor($period->id, $branchId);

        if (!$run) {
            return [
                'available' => false,
                'status'    => 'not_available',
                'reason'    => 'Aún no se ha generado Efectividad de Cobranza Aprobada para este periodo/alcance. Generarlo desde el módulo web "Efectividad de Cobranza" (o vía el Job autorizado) antes de consultarlo por API.',
                'version'   => 'approved_snapshot_v1',
            ];
        }

        $isStale = $fingerprintService->isStale($period, $branchId, $run->source_fingerprint);
        $rows = $run->result_rows ?? [];

        return [
            'available'     => true,
            // Fase 2 — un snapshot obsoleto SIGUE siendo datos reales (no inventados),
            // pero jamás se presenta como vigente: status=stale, nunca "ok".
            'status'        => $isStale ? 'stale' : 'ok',
            'is_stale'      => $isStale,
            'run_id'        => $run->id,
            'generated_at'  => $run->finished_at?->toISOString(),
            'summary'       => collect($rows)->firstWhere('promotor', 'GRAN TOTAL'),
            'rows'          => $rows,
            'coverage'      => $run->coverage,
            'version'       => 'approved_snapshot_v1',
        ];
    }

    private function resolveApprovedForEmployee(
        Period $period,
        array $scope,
        EmployeeBranchHistoryResolver $employeeBranchHistoryResolver,
        EfectividadCobranzaSourceFingerprintService $fingerprintService,
        EfectividadCobranzaAprobadaCalculator $calculator,
    ): array {
        $employeeId = $scope['employee_id'];

        // Fase 1 — identidad verificable, nunca "la asignación más reciente" ni una
        // coincidencia de nombre a ciegas.
        $historical = $employeeBranchHistoryResolver->resolveForPeriod($employeeId, $period);

        if ($historical['status'] !== 'known') {
            return [
                'available' => false,
                'status'    => 'not_attributable',
                'reason'    => 'No existe una asignación de sucursal verificada (employee_branch_assignments) para este colaborador en el periodo exacto consultado. Sin esa identidad verificada, nunca se atribuye un resultado financiero solo por coincidencia de nombre.',
                'version'   => 'approved_snapshot_v1',
            ];
        }

        $branchId = $historical['branch_id'];
        $run = EfectividadCobranzaRun::latestSuccessFor($period->id, $branchId);

        if (!$run) {
            return [
                'available' => false,
                'status'    => 'not_available',
                'reason'    => "Aún no se ha generado Efectividad de Cobranza Aprobada para la sucursal verificada de este colaborador en este periodo (branch_id={$branchId}). Generarlo desde el módulo web antes de consultarlo por API.",
                'version'   => 'approved_snapshot_v1',
            ];
        }

        $employee = Employee::query()->find($employeeId);
        $normalizedTarget = $employee ? $calculator->normalizar($employee->normalized_name ?: $employee->full_name) : null;

        $row = $normalizedTarget
            ? collect($run->result_rows ?? [])->first(fn (array $r) => empty($r['es_total']) && $calculator->normalizar($r['promotor']) === $normalizedTarget)
            : null;

        if (!$row) {
            return [
                'available' => false,
                'status'    => 'not_attributable',
                'reason'    => 'El cálculo ya existe para la sucursal verificada de este colaborador en este periodo, pero ninguna fila de promotor coincide con su nombre normalizado — no se atribuye por aproximación.',
                'coverage'  => $run->coverage,
                'version'   => 'approved_snapshot_v1',
            ];
        }

        $isStale = $fingerprintService->isStale($period, $branchId, $run->source_fingerprint);

        return [
            'available'    => true,
            'status'       => $isStale ? 'stale' : 'ok',
            'is_stale'     => $isStale,
            'run_id'       => $run->id,
            'branch_id'    => $branchId,
            'generated_at' => $run->finished_at?->toISOString(),
            'row'          => $row,
            'coverage'     => $run->coverage,
            'version'      => 'approved_snapshot_v1',
        ];
    }
}
