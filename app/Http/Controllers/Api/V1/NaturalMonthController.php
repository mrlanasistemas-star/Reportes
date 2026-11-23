<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Services\Api\FinancialMetricRegistry;
use App\Services\Api\NaturalMonthActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Meses naturales (23-nov-2026) — cifras del 1 al último día del mes, tomadas por la
 * FECHA de cada movimiento. No sustituye a los periodos de 4 semanas (que siguen siendo
 * la base del Estado de Resultados y del reporte): son dos agrupaciones de los mismos
 * registros. Ver NaturalMonthActivityService y docs/MANUAL-DESARROLLADOR.md §17.
 *
 *   GET /api/v1/calendar-months             meses con datos y su cobertura
 *   GET /api/v1/calendar-months/{YYYY-MM}   colocación, recuperación, gastos ERP y
 *                                           movimientos bancarios del mes natural
 *
 * financial.read para entrar; el bloque de colaboradores exige además employees.read.
 */
class NaturalMonthController extends Controller
{
    /** Bloques opcionales que se pueden pedir con `include`. */
    private const INCLUDES = ['branches', 'employees', 'daily', 'bank_movements'];

    /**
     * GET /api/v1/calendar-months — meses naturales con datos y su cobertura.
     *
     * Solo lectura; delega en NaturalMonthActivityService::availableMonths().
     *
     * @return JsonResponse {source, data: [mes...]}.
     */
    public function index(): JsonResponse
    {
        $service = app(NaturalMonthActivityService::class);

        return response()->json([
            'source' => 'reporteria.natural_months',
            'data' => $service->availableMonths(),
        ]);
    }

    /**
     * GET /api/v1/calendar-months/{YYYY-MM} — actividad de un mes natural.
     *
     * Para consumidores restringidos: sucursales y colaboradores filtrados a
     * su alcance, `totals` recalculados como suma de SUS sucursales, y los
     * bloques daily, unassigned, unidentified, bank_movements, audit y
     * reconciliation en null (solo alcance global). Solo lectura.
     *
     * @param  Request  $request  `include` opcional (ver INCLUDES).
     * @param  string  $month  Mes en formato AAAA-MM (años 2020–2100).
     * @return JsonResponse Cifras del mes con cobertura, periodos origen y bloques pedidos.
     *
     * @throws HttpException 422 si el mes o `include` son inválidos; 403 si pide employees sin employees.read.
     */
    public function show(Request $request, string $month): JsonResponse
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) || (int) $m[1] < 2020 || (int) $m[1] > 2100) {
            throw new HttpException(422, 'El mes debe tener el formato AAAA-MM (por ejemplo 2026-04).');
        }

        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $canEmployees = $consumer->tokenCan('employees.read') && $consumer->hasAbility('employees.read');
        $include = $this->includes($request, $canEmployees);

        if (in_array('employees', $include, true) && ! $canEmployees) {
            throw new HttpException(403, 'El token no tiene el permiso requerido: employees.read (necesario para el bloque de colaboradores; quita "employees" de include).');
        }

        $result = app(NaturalMonthActivityService::class)->forMonth((int) $m[1], (int) $m[2]);
        $global = $consumer->canAccessGeneralScope();
        $none = $consumer->hasNoBranchAccess();

        $branches = $none ? [] : array_values(array_filter(
            $result['branches'],
            fn ($b) => $global || $consumer->canAccessBranch($b['branch_id'])
        ));

        $out = [
            'month' => $result['coverage']['month'],
            'date_from' => $result['coverage']['date_from'],
            'date_to' => $result['coverage']['date_to'],
            'status' => $result['status'],
            'source' => 'reporteria.natural_month',
            'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
            'calculated_at' => $result['calculated_at'],
            'granularity' => [
                'base' => 'calendar_month',
                'data_tagging' => 'monthly_period',
                'note' => 'Del 1 al último día del mes por la fecha de cada movimiento (colocación: operation_date; recuperación: payment_date con la regla única del reporte; gastos ERP: expense_date; banco: movement_date). Los registros siguen ligados a su periodo de 4 semanas; aquí solo se filtran por fecha, sin duplicar.',
            ],
            'coverage' => $result['coverage'],
            'source_periods' => $result['source_periods'],
            // Restringidos: los totales son la suma de SUS sucursales (sin movimientos sin sucursal).
            'totals' => $global ? $result['totals'] : $this->sumBranches($branches),
        ];

        if (in_array('daily', $include, true)) {
            $out['daily'] = $global ? $result['daily'] : null;
        }

        if (in_array('branches', $include, true)) {
            $out['branches'] = [
                'attribution' => 'fact_branch_id',
                'data' => $branches,
                'unassigned' => $global ? $result['unassigned'] : null,
            ];
        }

        if (in_array('employees', $include, true)) {
            $out['employees'] = [
                'data' => $none ? [] : array_values(array_filter(
                    $result['employees'],
                    fn ($e) => $global || ($e['assigned_branch_id'] !== null && $consumer->canAccessBranch($e['assigned_branch_id']))
                )),
                'unidentified' => $global ? $result['unidentified'] : null,
                'missing_periods' => $result['employees_missing_periods'],
            ];
        }

        if (in_array('bank_movements', $include, true)) {
            // El banco se atribuye por nombre de sucursal, no por branch_id: solo alcance global.
            $out['bank_movements'] = $global ? $result['bank_movements'] : null;
        }

        $out['audit'] = $global ? $result['audit'] : null;
        $out['reconciliation'] = $global ? $result['reconciliation'] : null;

        return response()->json($out);
    }

    /**
     * Interpreta `include`. Sin valor, devuelve todos los bloques (sin
     * `employees` si el consumidor no tiene employees.read).
     *
     * @param  Request  $request  Petición con `include` opcional.
     * @param  bool  $canEmployees  Si token y consumidor tienen employees.read.
     * @return list<string> Bloques pedidos.
     *
     * @throws HttpException 422 si el valor está vacío tras limpiar o trae bloques desconocidos.
     */
    private function includes(Request $request, bool $canEmployees): array
    {
        $raw = $request->query('include');
        if ($raw === null || trim((string) $raw) === '') {
            return $canEmployees ? self::INCLUDES : array_values(array_diff(self::INCLUDES, ['employees']));
        }

        $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $raw)))));
        $unknown = array_diff($parts, self::INCLUDES);
        if ($parts === [] || $unknown !== []) {
            throw new HttpException(422, "El parámetro 'include' admite: ".implode(', ', self::INCLUDES).' (separados por coma).');
        }

        return $parts;
    }

    /**
     * Suma los totales de las sucursales visibles (para consumidores restringidos).
     *
     * Excluye por diseño los movimientos sin sucursal; redondea a 2 decimales.
     *
     * @param  array<int, array<string, mixed>>  $branches  Sucursales ya filtradas al alcance del consumidor.
     * @return array{placement: array{amount: float, operations: int}, recovery: array{total: float}, erp_expenses: array{amount: float, paid_amount: float, rows: int}}
     */
    private function sumBranches(array $branches): array
    {
        $amount = 0.0;
        $ops = 0;
        $recovery = 0.0;
        $erp = 0.0;
        $paid = 0.0;
        $rows = 0;
        foreach ($branches as $b) {
            $amount += $b['placement']['amount'];
            $ops += $b['placement']['operations'];
            $recovery += $b['recovery']['total'];
            $erp += $b['erp_expenses']['amount'];
            $paid += $b['erp_expenses']['paid_amount'];
            $rows += $b['erp_expenses']['rows'];
        }

        return [
            'placement' => ['amount' => round($amount, 2), 'operations' => $ops],
            'recovery' => ['total' => round($recovery, 2)],
            'erp_expenses' => ['amount' => round($erp, 2), 'paid_amount' => round($paid, 2), 'rows' => $rows],
        ];
    }
}
