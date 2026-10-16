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
use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4FingerprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 *
 * Motor V4 (2026-10-13): `approved` SOLO sirve ejecuciones del motor vigente
 * (`engine_version` = mrlana-v4-2026-10, modo SEMANAL sobre un periodo semanal).
 * Un resultado de la calculadora anterior jamás se entrega como versión actual:
 * si solo existe ese, la respuesta es `not_available` e indica que debe
 * regenerarse. `legacy` (efectividad histórica de la radiografía) no cambia.
 */
class CollectionsController extends Controller
{
    public function effectiveness(
        Request $request,
        FinancialMetricResolver $resolver,
        ApiScopeResolver $scopeResolver,
        EmployeeBranchHistoryResolver $employeeBranchHistoryResolver,
        EfectividadV4FingerprintService $fingerprintService,
        EfectividadV4Calculator $calculator,
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
     * GET /collections/effectiveness/export — descarga el Excel de Efectividad de
     * Cobranza V4 YA generado desde el módulo web (misma fuente que la web, el
     * mismo archivo). NUNCA recalcula en el GET. Requiere la ability PROPIA
     * `collections.export` (leer no basta) y respeta el alcance por sucursal del
     * consumidor. Solo entrega ejecuciones del motor Y la metodología vigentes
     * (`latestV4Success` → `forV4Identity`): un snapshot V6 o de la calculadora
     * anterior nunca se exporta como vigente. El archivo vive en el disco privado
     * `local` (nunca público); la descarga queda auditada por `log.api-query`.
     */
    public function exportEffectiveness(Request $request, ApiScopeResolver $scopeResolver): StreamedResponse|JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        $periodId = ApiValidation::requiredId($request, 'period_id');
        $period = Period::query()->findOrFail($periodId);
        $scope = $scopeResolver->resolve($request, $consumer, $period);

        // La exportación es del snapshot completo: general o de UNA sucursal.
        if ($scope['type'] === 'employee') {
            return response()->json([
                'message' => 'La exportación de Efectividad de Cobranza es por alcance general o por sucursal, no por colaborador.',
            ], 422);
        }

        if ($period->type !== 'weekly') {
            return response()->json([
                'message' => 'La Efectividad de Cobranza (motor V4) solo existe por semana operativa; elige un period_id semanal.',
                'methodology' => EfectividadCobranzaRun::currentMethodology(),
            ], 422);
        }

        $branchId = $scope['branch_id'];
        $run = $this->latestV4Success($period->id, $branchId);

        if (! $run) {
            return response()->json([
                'message' => 'Aún no se ha generado la Efectividad de Cobranza (motor V4, metodología '
                    .EfectividadCobranzaRun::currentMethodology().') para este periodo/alcance. Genérala desde el módulo web antes de exportarla por API.',
                'engine_version' => EfectividadCobranzaRun::currentEngineVersion(),
                'methodology' => EfectividadCobranzaRun::currentMethodology(),
            ], 404);
        }

        $path = $run->output_excel_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json([
                'message' => 'El cálculo existe pero su archivo Excel no está disponible en el servidor. Regenera la Efectividad de Cobranza desde el módulo web.',
                'run_id' => $run->id,
            ], 409);
        }

        $filename = sprintf(
            'efectividad-cobranza-%s-%s.xlsx',
            $period->code ?: ('periodo-'.$period->id),
            $branchId ? ('sucursal-'.$branchId) : 'general',
        );

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Lee (nunca recalcula) el último snapshot exitoso para period_id+branchId
     * exactos. `branchId=null` = alcance general (snapshot generado sin
     * sucursal específica) — nunca se suman snapshots de sucursales para
     * simular el general, nunca se parte un snapshot general para simular uno
     * de sucursal.
     */
    private function resolveApprovedSnapshot(Period $period, ?int $branchId, EfectividadV4FingerprintService $fingerprintService): array
    {
        if ($unsupported = $this->unsupportedPeriod($period)) {
            return $unsupported;
        }
        $run = $this->latestV4Success($period->id, $branchId);

        if (!$run) {
            return $this->notAvailable($period->id, $branchId, 'Aún no se ha generado la Efectividad de Cobranza (motor V4) para este periodo/alcance. Generarla desde el módulo web "Efectividad de Cobranza" antes de consultarla por API.');
        }

        $isStale = $fingerprintService->isStale($run);
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
            'parameters'    => $run->metadata['parametros'] ?? null,
            'incidents'     => ['count' => (int) $run->incidents_count, 'by_code' => array_values($run->metadata['incidencias_resumen'] ?? [])],
            'engine_version' => $run->engine_version,
            'methodology'   => $run->metodologia,
            'version'       => self::VERSION,
        ];
    }

    private function resolveApprovedForEmployee(
        Period $period,
        array $scope,
        EmployeeBranchHistoryResolver $employeeBranchHistoryResolver,
        EfectividadV4FingerprintService $fingerprintService,
        EfectividadV4Calculator $calculator,
    ): array {
        if ($unsupported = $this->unsupportedPeriod($period)) {
            return $unsupported;
        }
        $employeeId = $scope['employee_id'];

        // Fase 1 — identidad verificable, nunca "la asignación más reciente" ni una
        // coincidencia de nombre a ciegas.
        $historical = $employeeBranchHistoryResolver->resolveForPeriod($employeeId, $period);

        if ($historical['status'] !== 'known') {
            return [
                'available' => false,
                'status'    => 'not_attributable',
                'reason'    => 'No existe una asignación de sucursal verificada (employee_branch_assignments) para este colaborador en el periodo exacto consultado. Sin esa identidad verificada, nunca se atribuye un resultado financiero solo por coincidencia de nombre.',
                'version'   => self::VERSION,
            ];
        }

        $branchId = $historical['branch_id'];
        $run = $this->latestV4Success($period->id, $branchId);

        if (!$run) {
            return $this->notAvailable($period->id, $branchId, "Aún no se ha generado la Efectividad de Cobranza (motor V4) para la sucursal verificada de este colaborador en este periodo (branch_id={$branchId}). Generarla desde el módulo web antes de consultarla por API.");
        }

        $employee = Employee::query()->find($employeeId);
        $normalizedTarget = $employee ? $calculator->normalizar($employee->normalized_name ?: $employee->full_name) : null;

        $matches = $normalizedTarget
            ? collect($run->result_rows ?? [])->filter(fn (array $r) => empty($r['es_total']) && $calculator->normalizar($r['promotor']) === $normalizedTarget)->values()
            : collect();

        // Homonimia: el mismo nombre en dos oficinas de la sucursal es ambiguo — nunca se elige "la primera".
        if ($matches->count() > 1) {
            return [
                'available' => false,
                'status'    => 'not_attributable',
                'reason'    => 'Hay '.$matches->count().' filas de promotor con el mismo nombre normalizado en la sucursal verificada de este colaborador (oficinas: '.$matches->pluck('oficina')->implode(', ').'). No se atribuye un resultado por homonimia.',
                'coverage'  => $run->coverage,
                'version'   => self::VERSION,
            ];
        }
        $row = $matches->first();

        if (!$row) {
            return [
                'available' => false,
                'status'    => 'not_attributable',
                'reason'    => 'El cálculo ya existe para la sucursal verificada de este colaborador en este periodo, pero ninguna fila de promotor coincide con su nombre normalizado — no se atribuye por aproximación.',
                'coverage'  => $run->coverage,
                'version'   => self::VERSION,
            ];
        }

        $isStale = $fingerprintService->isStale($run);

        return [
            'available'    => true,
            'status'       => $isStale ? 'stale' : 'ok',
            'is_stale'     => $isStale,
            'run_id'       => $run->id,
            'branch_id'    => $branchId,
            'generated_at' => $run->finished_at?->toISOString(),
            'row'          => $row,
            'coverage'     => $run->coverage,
            'engine_version' => $run->engine_version,
            'methodology'  => $run->metodologia,
            'version'      => self::VERSION,
        ];
    }

    public const VERSION = 'approved_snapshot_v4';

    private function latestV4Success(int $periodId, ?int $branchId): ?EfectividadCobranzaRun
    {
        return EfectividadCobranzaRun::query()
            ->forV4Identity($periodId, $branchId, EfectividadCobranzaRun::MODE_SEMANAL, null)
            ->where('status', EfectividadCobranzaRun::STATUS_SUCCESS)
            ->latest('id')
            ->first();
    }

    /** La macro V4 es semanal: un mes/bimestre no tiene resultado verificable. */
    private function unsupportedPeriod(Period $period): ?array
    {
        if ($period->type === 'weekly') {
            return null;
        }

        return [
            'available' => false,
            'status' => 'not_supported_period',
            'reason' => 'La Efectividad de Cobranza (motor V4) se calcula por semana operativa lunes-domingo. Consulta un period_id semanal; no se agregan semanas para simular meses.',
            'engine_version' => EfectividadCobranzaRun::currentEngineVersion(),
            'methodology' => EfectividadCobranzaRun::currentMethodology(),
            'version' => self::VERSION,
        ];
    }

    private function notAvailable(int $periodId, ?int $branchId, string $reason): array
    {
        $previous = EfectividadCobranzaRun::latestSuccessFor($periodId, $branchId);

        // Se distingue explícitamente POR QUÉ no hay un resultado vigente:
        //  - motor anterior (otra macro aprobada), o
        //  - mismo motor pero metodología anterior / sin sellar (p. ej. V6): hay
        //    un resultado real pero NO se entrega como V7; debe regenerarse.
        if ($previous && ! $previous->isCurrentEngine()) {
            $reason .= ' Existe un resultado de la calculadora anterior (run '.$previous->id.'), pero no se entrega como versión vigente.';
        } elseif ($previous && ! $previous->isCurrentMethodology()) {
            $reason .= ' Existe un resultado del motor vigente pero con una metodología anterior ('
                .($previous->metodologia ?? 'sin registrar').'); no se entrega como '
                .EfectividadCobranzaRun::currentMethodology().'. Regenera la Efectividad de Cobranza desde el módulo web.';
        }

        return [
            'available' => false,
            'status' => 'not_available',
            'reason' => $reason,
            'engine_version' => EfectividadCobranzaRun::currentEngineVersion(),
            'methodology' => EfectividadCobranzaRun::currentMethodology(),
            'version' => self::VERSION,
        ];
    }
}
