<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\Period;
use App\Services\Api\ApiCsv;
use App\Services\Api\ApiPeriodRange;
use App\Services\Api\ApiValidation;
use App\Services\Api\EmployeeBranchHistoryResolver;
use App\Services\Api\EmployeePeriodKpiService;
use App\Services\Api\FinancialMetricRegistry;
use App\Services\Api\PeriodBranchKpiService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Exportación de KPIs por periodo (API v1, solo lectura):
 *
 *   GET /periods/{id}/employees   colaboradores del periodo con sucursal, ruta,
 *                                 estado laboral y KPIs (paginado; format=csv)
 *   GET /periods/{id}/branches    sucursales + corporativo + consolidado (format=csv)
 *   GET /periods/{id}/export      todo lo anterior en una sola llamada
 *   GET /periods/export           lo mismo para un rango de periodos
 *   GET /employees/{id}/periods   un colaborador a lo largo de varios periodos
 *
 * Las cifras salen de las MISMAS fuentes que el reporte mensual/Excel (ver
 * EmployeePeriodKpiService y PeriodBranchKpiService). El alcance por sucursal
 * del consumidor se aplica SIEMPRE contra la sucursal histórica del periodo.
 */
class PeriodKpiController extends Controller
{
    /** Máximo de periodos por llamada en /periods/export. */
    private const MAX_RANGE_EXPORT = 12;

    /** Máximo de periodos por llamada en /employees/{id}/periods. */
    private const MAX_RANGE_EMPLOYEE = 24;

    /** KPIs por colaborador y periodo (mismas fuentes que el reporte mensual). */
    private EmployeePeriodKpiService $employeeKpis;

    /** KPIs por sucursal, corporativo y consolidado de un periodo. */
    private PeriodBranchKpiService $branchKpis;

    /** Sucursal histórica de un colaborador por periodo (autorización IDOR). */
    private EmployeeBranchHistoryResolver $branchHistory;

    /**
     * Los servicios llevan memo POR PETICIÓN; Laravel reutiliza la instancia del
     * controlador ligada a la ruta (y un worker de larga vida la conservaría),
     * así que se resuelven frescos al inicio de cada acción — nunca cruzan peticiones.
     */
    private function freshServices(): void
    {
        $this->employeeKpis = app(EmployeePeriodKpiService::class);
        $this->branchKpis = app(PeriodBranchKpiService::class);
        $this->branchHistory = app(EmployeeBranchHistoryResolver::class);
    }

    /**
     * GET /api/v1/periods/{periodId}/employees — colaboradores del periodo con
     * sucursal, ruta, estado laboral y KPIs; paginado en memoria o CSV completo.
     *
     * Filtra por la sucursal asignada en el periodo (alcance del consumidor y
     * filtros opcionales). `unidentified_count` solo para alcance global.
     *
     * @param  Request  $request  `per_page`, `page`, `branch_id`, `sucursal_id`, `format=csv` opcionales.
     * @param  int  $periodId  ID del periodo.
     * @return JsonResponse|Response JSON paginado o archivo CSV.
     *
     * @throws HttpException 403 si `branch_id` no está autorizado; 422 si `page` es inválido.
     */
    public function employees(Request $request, int $periodId): JsonResponse|Response
    {
        $this->freshServices();
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $period = Period::query()->findOrFail($periodId);
        $csv = ApiCsv::wantsCsv($request);
        $perPage = ApiValidation::perPage($request);
        $page = $this->page($request);
        [$branchId, $sucursalId] = $this->employeeFilters($request, $consumer);

        $result = $this->employeeKpis->forPeriod($period);
        $rows = $this->filterEmployeeRows($consumer, $result['rows'], $branchId, $sucursalId);

        if ($csv) {
            return ApiCsv::response(
                array_map(fn ($r) => ['period_id' => $period->id, 'period_label' => $period->label] + $this->forCsv($r), $rows),
                "colaboradores-periodo-{$period->id}.csv"
            );
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));

        return response()->json([
            'period' => ApiPeriodRange::describe($period),
            'status' => $result['status'],
            'message' => $result['message'],
            'source' => 'reporteria.employees_period_kpis',
            'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
            'calculated_at' => $result['calculated_at'],
            'radiography_version' => $result['radiography_version'],
            'data' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'unidentified_count' => $consumer->canAccessGeneralScope() ? count($result['unidentified']) : null,
            ],
        ]);
    }

    /**
     * GET /api/v1/periods/{periodId}/branches — KPIs por sucursal, más los
     * bloques corporativo y consolidado (solo alcance global).
     *
     * @param  Request  $request  `format=csv` opcional.
     * @param  int  $periodId  ID del periodo.
     * @return JsonResponse|Response JSON con period + bloque de sucursales, o CSV.
     *
     * @throws ModelNotFoundException 404 si el periodo no existe.
     */
    public function branches(Request $request, int $periodId): JsonResponse|Response
    {
        $this->freshServices();
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $period = Period::query()->findOrFail($periodId);
        $csv = ApiCsv::wantsCsv($request);

        $block = $this->branchBlock($consumer, $period);

        if ($csv) {
            return ApiCsv::response($this->branchCsvRows($period, $block), "sucursales-periodo-{$period->id}.csv");
        }

        return response()->json(['period' => ApiPeriodRange::describe($period)] + $block);
    }

    /**
     * GET /api/v1/periods/{periodId}/export — todo el periodo en una sola llamada.
     *
     * @param  Request  $request  `include` = branches,employees (por defecto ambos).
     * @param  int  $periodId  ID del periodo.
     * @return JsonResponse Exportación del periodo (ver periodExport()).
     *
     * @throws HttpException 403 si pide employees sin employees.read; 422 si `include` es inválido.
     */
    public function export(Request $request, int $periodId): JsonResponse
    {
        $this->freshServices();
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $period = Period::query()->findOrFail($periodId);
        $include = $this->includes($request);
        $this->assertEmployeesAbilityFor($consumer, $include);

        return response()->json($this->periodExport($consumer, $period, $include));
    }

    /**
     * GET /api/v1/periods/export?from_period_id=&to_period_id= (o period_ids=)
     *
     * Exporta hasta MAX_RANGE_EXPORT periodos. En JSON devuelve una exportación
     * por periodo; en CSV exige `dataset` (employees o branches) y concatena
     * las filas de todos los periodos.
     *
     * @param  Request  $request  Rango de periodos, `include`, `format`, `dataset`.
     * @return JsonResponse|Response JSON {periods, meta} o CSV.
     *
     * @throws HttpException 422 si CSV sin `dataset` válido o rango/include inválidos; 403 sin employees.read.
     */
    public function rangeExport(Request $request): JsonResponse|Response
    {
        $this->freshServices();
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $csv = ApiCsv::wantsCsv($request);
        $dataset = $csv ? ApiValidation::enumValue($request, 'dataset', ['employees', 'branches']) : null;
        if ($csv && $dataset === null) {
            throw new HttpException(422, "format=csv requiere 'dataset' = employees o branches.");
        }
        $include = $csv ? [$dataset] : $this->includes($request);
        $this->assertEmployeesAbilityFor($consumer, $include);
        $periods = ApiPeriodRange::resolve($request, self::MAX_RANGE_EXPORT);

        if ($csv) {
            $rows = [];
            foreach ($periods as $period) {
                if ($dataset === 'branches') {
                    array_push($rows, ...$this->branchCsvRows($period, $this->branchBlock($consumer, $period)));
                } else {
                    $result = $this->employeeKpis->forPeriod($period);
                    foreach ($this->filterEmployeeRows($consumer, $result['rows'], null, null) as $r) {
                        $rows[] = ['period_id' => $period->id, 'period_label' => $period->label] + $this->forCsv($r);
                    }
                }
            }

            return ApiCsv::response($rows, "{$dataset}-periodos.csv");
        }

        return response()->json([
            'periods' => $periods->map(fn (Period $p) => $this->periodExport($consumer, $p, $include))->values(),
            'meta' => [
                'periods_count' => $periods->count(),
                'include' => $include,
                'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
            ],
        ]);
    }

    /**
     * GET /api/v1/employees/{employeeId}/periods — un colaborador a lo largo de un rango.
     *
     * Por cada periodo (máx. MAX_RANGE_EMPLOYEE) devuelve status ok / forbidden
     * (fuera de alcance por sucursal histórica) / not_found (sin actividad) o el
     * estado del cálculo. Con `include_effectiveness` (por defecto 1) agrega
     * la efectividad de cobranza del colaborador.
     *
     * @param  Request  $request  Rango de periodos, `include_effectiveness`, `format=csv`.
     * @param  int  $employeeId  ID del colaborador.
     * @return JsonResponse|Response JSON {employee, source, definition_version, data, meta} o CSV.
     *
     * @throws ModelNotFoundException 404 si el colaborador no existe.
     * @throws HttpException 403 si un consumidor restringido no tiene acceso en ningún periodo del rango.
     */
    public function employeePeriods(Request $request, int $employeeId): JsonResponse|Response
    {
        $this->freshServices();
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $employee = Employee::query()->findOrFail($employeeId);
        $csv = ApiCsv::wantsCsv($request);
        $withEffectiveness = $request->query('include_effectiveness', '1') !== '0';
        $periods = ApiPeriodRange::resolve($request, self::MAX_RANGE_EMPLOYEE);

        $entries = [];
        $allowedAny = false;
        foreach ($periods as $period) {
            $entry = ['period' => ApiPeriodRange::describe($period)];

            // IDOR (Fase 2.2/2.3): sucursal HISTÓRICA del colaborador en ESE periodo.
            if (! $this->branchHistory->isEmployeeAllowedForConsumer($consumer, $employee->id, $period)) {
                $entries[] = $entry + ['status' => 'forbidden', 'message' => 'Fuera del alcance de sucursal de este consumidor para este periodo.'];

                continue;
            }
            $allowedAny = true;

            $result = $this->employeeKpis->forPeriod($period);
            if ($result['status'] !== EmployeePeriodKpiService::STATUS_OK) {
                $entries[] = $entry + ['status' => $result['status'], 'message' => $result['message']];

                continue;
            }

            $row = collect($result['rows'])->first(fn ($r) => in_array($employee->id, $r['employee']['employee_ids'], true));
            if ($row === null) {
                $entries[] = $entry + ['status' => 'not_found', 'message' => 'El colaborador no tiene actividad (nómina, colocación, cartera ni recuperación) en este periodo.'];

                continue;
            }

            if ($withEffectiveness) {
                $row['kpis']['collections_effectiveness'] = $this->employeeKpis->effectivenessFor($period, (string) ($row['employee']['report_name'] ?? $row['employee']['full_name']));
                $row['availability']['collections_effectiveness'] = null;
            }

            $entries[] = $entry + ['status' => 'ok', 'calculated_at' => $result['calculated_at'], 'radiography_version' => $result['radiography_version']] + $row;
        }

        if (! $allowedAny && ($consumer->isRestrictedToBranches() || $consumer->hasNoBranchAccess())) {
            throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado en ninguno de los periodos pedidos.');
        }

        if ($csv) {
            $rows = array_map(
                fn ($e) => ['period_id' => $e['period']['id'], 'period_label' => $e['period']['label'], 'status' => $e['status']]
                    + (isset($e['kpis']) ? $this->forCsv(array_diff_key($e, ['period' => 1, 'status' => 1])) : []),
                $entries
            );

            return ApiCsv::response($rows, "colaborador-{$employee->id}-periodos.csv");
        }

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'position' => $employee->position,
                'is_active' => (bool) $employee->is_active,
            ],
            'source' => 'reporteria.employees_period_kpis',
            'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
            'data' => $entries,
            'meta' => ['periods_count' => count($entries)],
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Arma la exportación de un periodo con los bloques pedidos.
     *
     * @param  ApiConsumer  $consumer  Consumidor (define alcance).
     * @param  Period  $period  Periodo a exportar.
     * @param  list<string>  $include  Bloques: branches y/o employees.
     * @return array<string, mixed> {period, branches?, employees?}.
     */
    private function periodExport(ApiConsumer $consumer, Period $period, array $include): array
    {
        $out = ['period' => ApiPeriodRange::describe($period)];

        if (in_array('branches', $include, true)) {
            $out['branches'] = $this->branchBlock($consumer, $period);
        }

        if (in_array('employees', $include, true)) {
            $result = $this->employeeKpis->forPeriod($period);
            $rows = $this->filterEmployeeRows($consumer, $result['rows'], null, null);
            $out['employees'] = [
                'status' => $result['status'],
                'message' => $result['message'],
                'calculated_at' => $result['calculated_at'],
                'radiography_version' => $result['radiography_version'],
                'total' => count($rows),
                'data' => array_values($rows),
                // Filas del reporte sin identidad de colaborador: solo alcance global
                // (no se pueden autorizar por sucursal histórica).
                'unidentified' => $consumer->canAccessGeneralScope() ? $result['unidentified'] : null,
            ];
        }

        return $out;
    }

    /**
     * Bloque de sucursales de un periodo filtrado al alcance del consumidor.
     *
     * Un consumidor restringido recibe `not_authorized` en corporativo y
     * consolidado, y `reconciliation` en null.
     *
     * @param  ApiConsumer  $consumer  Consumidor (define alcance).
     * @param  Period  $period  Periodo.
     * @return array<string, mixed> {status, message, source, definition_version, calculated_at, data, corporate, consolidated, reconciliation}.
     */
    private function branchBlock(ApiConsumer $consumer, Period $period): array
    {
        $result = $this->branchKpis->forPeriod($period);
        $global = $consumer->canAccessGeneralScope();

        $branches = array_values(array_filter(
            $result['branches'],
            fn ($b) => $global || ($b['branch_id'] !== null && $consumer->canAccessBranch($b['branch_id']))
        ));

        $notAuthorized = ['status' => 'not_authorized', 'message' => 'Este consumidor está restringido a sucursales específicas; los bloques corporativo y consolidado requieren alcance global.'];

        return [
            'status' => $result['status'],
            'message' => $result['message'],
            'source' => 'reporteria.radiography_snapshot',
            'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
            'calculated_at' => $result['calculated_at'],
            'data' => $branches,
            'corporate' => $result['corporate'] === null ? null : ($global ? $result['corporate'] : $notAuthorized),
            'consolidated' => $result['consolidated'] === null ? null : ($global ? $result['consolidated'] : $notAuthorized),
            'reconciliation' => $global ? $result['reconciliation'] : null,
        ];
    }

    /**
     * Convierte el bloque de sucursales en filas CSV (block = branch, corporate
     * o consolidated). Al corporativo se le quita la lista de empleados de nómina.
     *
     * @param  Period  $period  Periodo (para period_id/period_label).
     * @param  array<string, mixed>  $block  Resultado de branchBlock().
     * @return list<array<string, mixed>> Filas para ApiCsv.
     */
    private function branchCsvRows(Period $period, array $block): array
    {
        $base = ['period_id' => $period->id, 'period_label' => $period->label, 'status' => $block['status']];
        $rows = [];
        foreach ($block['data'] as $b) {
            $rows[] = $base + ['block' => 'branch'] + $this->forCsv($b);
        }
        if (isset($block['corporate']['payroll'])) {
            $rows[] = $base + ['block' => 'corporate'] + $this->forCsv(array_diff_key($block['corporate'], ['payroll' => 1]) + ['payroll' => array_diff_key($block['corporate']['payroll'], ['employees' => 1])]);
        }
        if (isset($block['consolidated']['kpis'])) {
            $rows[] = $base + ['block' => 'consolidated'] + $this->forCsv($block['consolidated']);
        }

        return $rows;
    }

    /**
     * CSV: sin listas de detalle voluminosas (siguen disponibles en JSON).
     *
     * Quita partidas de OPEX, colocación por producto y el bloque availability.
     *
     * @param  array<string, mixed>  $row  Fila JSON.
     * @return array<string, mixed> Fila reducida.
     */
    private function forCsv(array $row): array
    {
        unset($row['kpis']['opex']['items'], $row['kpis']['placement']['by_product'], $row['availability']);

        return $row;
    }

    /**
     * Filtra filas de colaboradores por alcance y filtros opcionales.
     *
     * Restringido: solo filas con sucursal asignada (en el periodo) autorizada.
     * `sucursal_id` solo coincide si la sucursal vino de una asignación real
     * (branch_source = assignment).
     *
     * @param  ApiConsumer  $consumer  Consumidor (define alcance).
     * @param  list<array>  $rows  Filas de EmployeePeriodKpiService.
     * @param  int|null  $branchId  Filtro por branch asignado.
     * @param  int|null  $sucursalId  Filtro por sucursal institucional.
     * @return list<array>
     */
    private function filterEmployeeRows(ApiConsumer $consumer, array $rows, ?int $branchId, ?int $sucursalId): array
    {
        if ($consumer->hasNoBranchAccess()) {
            return [];
        }

        $restricted = $consumer->isRestrictedToBranches();

        return array_values(array_filter($rows, function (array $r) use ($consumer, $restricted, $branchId, $sucursalId) {
            // Autorización y filtros SIEMPRE por la asignación del periodo (nunca por
            // el nombre de sucursal del reporte ni por la asignación más reciente).
            $assigned = $r['assigned_branch_id'];
            if ($restricted && ($assigned === null || ! $consumer->canAccessBranch($assigned))) {
                return false;
            }
            if ($branchId !== null && $assigned !== $branchId) {
                return false;
            }
            if ($sucursalId !== null && ($r['branch_source'] !== 'assignment' || ($r['sucursal']['id'] ?? null) !== $sucursalId)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Lee y valida los filtros `branch_id` y `sucursal_id`.
     *
     * @param  Request  $request  Petición.
     * @param  ApiConsumer  $consumer  Consumidor (valida acceso al branch).
     * @return array{0:?int, 1:?int} [branch_id, sucursal_id]
     *
     * @throws HttpException 403 si `branch_id` no está autorizado.
     */
    private function employeeFilters(Request $request, ApiConsumer $consumer): array
    {
        $branchId = ApiValidation::nullableId($request, 'branch_id');
        $sucursalId = ApiValidation::nullableId($request, 'sucursal_id');

        if ($branchId !== null && ! $consumer->canAccessBranch($branchId)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para la sucursal solicitada.');
        }

        return [$branchId, $sucursalId];
    }

    /**
     * El bloque de colaboradores exige ADEMÁS `employees.read` (token y consumidor,
     * misma doble verificación que EnsureApiAbility): `financial.read` por sí solo
     * nunca abre datos por colaborador.
     *
     * @param  ApiConsumer  $consumer  Consumidor.
     * @param  list<string>  $include  Bloques pedidos.
     *
     * @throws HttpException 403 si pide employees sin la ability.
     */
    private function assertEmployeesAbilityFor(ApiConsumer $consumer, array $include): void
    {
        if (in_array('employees', $include, true) && (! $consumer->tokenCan('employees.read') || ! $consumer->hasAbility('employees.read'))) {
            throw new HttpException(403, 'El token no tiene el permiso requerido: employees.read (necesario para el bloque de colaboradores; usa include=branches si solo necesitas sucursales).');
        }
    }

    /**
     * Interpreta `include` (por defecto "branches,employees").
     *
     * @param  Request  $request  Petición.
     * @return list<string> Bloques pedidos.
     *
     * @throws HttpException 422 si está vacío o trae valores desconocidos.
     */
    private function includes(Request $request): array
    {
        $raw = trim((string) $request->query('include', 'branches,employees'));
        $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
        $unknown = array_diff($parts, ['branches', 'employees']);
        if ($parts === [] || $unknown !== []) {
            throw new HttpException(422, "El parámetro 'include' admite: branches, employees (separados por coma).");
        }

        return $parts;
    }

    /**
     * Página solicitada (1 si no viene).
     *
     * @param  Request  $request  Petición con `page` opcional.
     * @return int Página >= 1.
     *
     * @throws HttpException 422 si no es un entero positivo.
     */
    private function page(Request $request): int
    {
        $raw = $request->query('page');
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            throw new HttpException(422, "El parámetro 'page' debe ser un entero positivo.");
        }

        return (int) $raw;
    }
}
