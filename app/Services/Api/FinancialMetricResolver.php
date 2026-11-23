<?php

namespace App\Services\Api;

use App\Models\Branch;
use App\Models\Period;
use App\Services\RadiografiaExportService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fase 1 — ÚNICO punto donde la API de reportería lee un valor financiero.
 * Nunca recalcula: siempre delega en
 * RadiografiaExportService::buildSnapshot() (misma fuente que Web/Excel/PDF/
 * OKR — ver docs/MANUAL-DESARROLLADOR.md). Si el periodo no tiene radiografía generada, o el
 * alcance no tiene datos, el valor es `null` con un `status` explícito —
 * JAMÁS 0 disfrazando "desconocido".
 */
class FinancialMetricResolver
{
    public const STATUS_OK = 'ok';

    public const STATUS_NOT_AVAILABLE = 'not_available';

    public const STATUS_NOT_ATTRIBUTABLE = 'not_attributable';

    public const SCOPE_GENERAL = 'general';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_EMPLOYEE = 'employee';

    /**
     * Fase 3.1 — memoización POR PETICIÓN. Un endpoint como /financial/summary
     * resuelve ~10 métricas con el MISMO periodo+alcance: sin esto, cada una
     * dispara su propio paso por RadiografiaExportService::buildSnapshot()
     * (que ya cachea 180s en el store configurado, pero eso sigue siendo una
     * lectura de caché por métrica). Esta instancia vive solo lo que dura la
     * inyección en el controlador (una petición) — nunca cruza peticiones.
     *
     * @var array<string, array|null>
     */
    private array $snapshotMemo = [];

    /**
     * @param  RadiografiaExportService  $exportService  Fuente única del snapshot de radiografía (Web/Excel/PDF/OKR/API).
     */
    public function __construct(
        private readonly RadiografiaExportService $exportService,
    ) {}

    /**
     * Resuelve el valor de una métrica pública para un periodo y alcance.
     *
     * Flujo: busca la definición en FinancialMetricRegistry; si no existe →
     * not_available; si el alcance no está permitido para la métrica →
     * not_attributable; si no hay radiografía o el alcance (sucursal/
     * colaborador) no tiene datos → not_available (nunca cae al general);
     * en otro caso extrae el valor según `source` y marca ok, o
     * not_available si el valor resultó null.
     *
     * Efectos: lee el snapshot (caché del RadiografiaExportService +
     * memoización por instancia) y puede registrar en el log la ausencia de
     * radiografía o inconsistencias de cartera.
     *
     * @param  string  $metricCode  Código público de la métrica (ver FinancialMetricRegistry).
     * @param  Period  $period  Periodo consultado.
     * @param  string  $scopeType  general|branch|employee (ya validado por ApiScopeResolver).
     * @param  int|null  $branchId  Sucursal cuando scope=branch.
     * @param  int|null  $employeeId  Colaborador cuando scope=employee.
     * @return array<string, mixed> entrada con la forma period_id/period_type/
     *                              period_label/start_date/end_date/
     *                              calculated_at/source/status/scope/value/
     *                              unit/definition_version (ver Fase 1.5)
     */
    public function resolve(string $metricCode, Period $period, string $scopeType, ?int $branchId = null, ?int $employeeId = null): array
    {
        $definition = FinancialMetricRegistry::get($metricCode);

        $envelope = [
            'metric' => $metricCode,
            'period_id' => $period->id,
            'period_type' => $period->type,
            'period_label' => $period->label,
            'start_date' => optional($period->start_date)->toDateString(),
            'end_date' => optional($period->end_date)->toDateString(),
            'calculated_at' => null,
            'source' => 'reporteria.radiography_snapshot',
            'status' => self::STATUS_NOT_AVAILABLE,
            'scope' => ['type' => $scopeType, 'branch_id' => $branchId, 'employee_id' => $employeeId],
            'value' => null,
            'unit' => $definition['unit'] ?? null,
            'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
        ];

        if ($definition === null) {
            $envelope['status'] = self::STATUS_NOT_AVAILABLE;

            return $envelope;
        }

        if (! in_array($scopeType, $definition['scopes'], true)) {
            $envelope['status'] = self::STATUS_NOT_ATTRIBUTABLE;

            return $envelope;
        }

        $snapshot = $this->buildSnapshotSafely($period, $this->configFor($scopeType, $branchId, $employeeId));
        if ($snapshot === null) {
            return $envelope;
        }

        $envelope['calculated_at'] = $snapshot['generated_at'] ?? null;

        if ($scopeType !== self::SCOPE_GENERAL && (($snapshot['scope']['available'] ?? false) === false)) {
            return $envelope; // sin datos de radiografía para ese alcance — nunca cae al general
        }

        $value = match ($definition['source']) {
            'summary' => $this->fromSummary($snapshot, $definition['summary_key']),
            'derived' => $this->fromDerived($snapshot, $definition['derived_from']),
            'special' => $this->fromSpecial($metricCode, $scopeType, $branchId, $snapshot),
            'legacy_section' => $this->fromLegacyEffectiveness($snapshot),
            default => null,
        };

        $envelope['value'] = $value;
        $envelope['status'] = $value === null ? self::STATUS_NOT_AVAILABLE : self::STATUS_OK;

        return $envelope;
    }

    /**
     * Construye la configuración de alcance que espera
     * RadiografiaExportService::buildSnapshot().
     *
     * @param  string  $scopeType  general|branch|employee.
     * @param  int|null  $branchId  Se incluye solo cuando el alcance es branch.
     * @param  int|null  $employeeId  Se incluye solo cuando el alcance es employee.
     * @return array<string, mixed> Configuración con `scope` y el id correspondiente.
     */
    private function configFor(string $scopeType, ?int $branchId, ?int $employeeId): array
    {
        $config = ['scope' => $scopeType];
        if ($scopeType === self::SCOPE_BRANCH) {
            $config['branch_id'] = $branchId;
        }
        if ($scopeType === self::SCOPE_EMPLOYEE) {
            $config['employee_id'] = $employeeId;
        }

        return $config;
    }

    /**
     * buildSnapshot() lanza RuntimeException si el periodo no tiene
     * radiografía generada — estado real y esperable, no un error 500.
     *
     * Memoiza el resultado (incluido el null) por periodo + hash de la
     * configuración durante la vida de la instancia. Registra en el log
     * (nivel info) cuando no hay radiografía. Otras excepciones que no sean
     * RuntimeException se propagan.
     *
     * @param  Period  $period  Periodo del snapshot.
     * @param  array  $config  Configuración de alcance (ver configFor()).
     * @return array|null Snapshot completo, o null si el periodo no tiene radiografía.
     */
    private function buildSnapshotSafely(Period $period, array $config): ?array
    {
        $memoKey = $period->id.':'.md5(json_encode($config));
        if (array_key_exists($memoKey, $this->snapshotMemo)) {
            return $this->snapshotMemo[$memoKey];
        }

        try {
            return $this->snapshotMemo[$memoKey] = $this->exportService->buildSnapshot($period, $config);
        } catch (RuntimeException $e) {
            Log::info('Api\\FinancialMetricResolver: sin radiografía generada para el periodo.', [
                'period_id' => $period->id, 'config' => $config, 'error' => $e->getMessage(),
            ]);

            return $this->snapshotMemo[$memoKey] = null;
        }
    }

    /**
     * Lee un valor directo de `summary` del snapshot.
     *
     * @param  array  $snapshot  Snapshot de radiografía.
     * @param  string  $summaryKey  Clave interna en summary (p. ej. "ebitda_final").
     * @return float|null Valor como float, o null si la clave no existe o es null.
     */
    private function fromSummary(array $snapshot, string $summaryKey): ?float
    {
        return isset($snapshot['summary'][$summaryKey]) ? (float) $snapshot['summary'][$summaryKey] : null;
    }

    /**
     * Fase 4.1 (revisión financiera 2026-10-11) — se auditó el repo buscando una
     * "cartera vigente" ya calculada en algún lado (BranchRadiographyCalculator,
     * RadiographyWorkbookBuilder, Preview.vue): NO existe — todo lo que se llama
     * "vigente" en el código es la clasificación DPD de Efectividad de Cobranza
     * (cobros, no saldo de cartera), un concepto distinto. La única definición
     * matemáticamente forzada de "vigente" es el complemento de "vencida" sobre
     * el total (vigente = total − vencida) — no es una fórmula nueva inventada,
     * es la identidad que define qué significa "vencida" en primer lugar.
     *
     * Guardia defensiva: si el resultado fuera negativo (total < vencida), eso
     * indicaría una inconsistencia de datos entre ambos campos de origen — se
     * devuelve null (nunca un "vigente" negativo presentado como válido).
     *
     * Asume que `$derivedFrom` trae exactamente dos códigos (minuendo,
     * sustraendo); si alguno no tiene valor, devuelve null.
     *
     * @param  array  $snapshot  Snapshot de radiografía.
     * @param  array  $derivedFrom  Códigos de métrica de origen, en orden [total, vencida].
     * @return float|null Resultado de la resta, o null si falta un dato o sale negativo (se registra warning).
     */
    private function fromDerived(array $snapshot, array $derivedFrom): ?float
    {
        $values = [];
        foreach ($derivedFrom as $code) {
            $def = FinancialMetricRegistry::get($code);
            $values[] = $def ? $this->fromSummary($snapshot, $def['summary_key']) : null;
        }

        if (in_array(null, $values, true)) {
            return null;
        }

        // Única combinación soportada hoy: portfolio_current = portfolio_total - portfolio_overdue.
        $result = $values[0] - $values[1];

        if ($result < 0) {
            Log::warning('Api\\FinancialMetricResolver: portfolio_current salió negativo — inconsistencia entre portfolio_total y portfolio_overdue, se devuelve null en vez de un valor inválido.', [
                'portfolio_total' => $values[0], 'portfolio_overdue' => $values[1],
            ]);

            return null;
        }

        return $result;
    }

    /**
     * Rotación — branch/general únicamente (ver OkrKpiValueResolver::resolveSpecial, misma lógica).
     *
     * General: toma `sections.rotation.indice`. Sucursal: busca el nombre de
     * la sucursal en BD y lo compara (mayúsculas, sin espacios extremos) con
     * `sections.rotation.por_sucursal[].sucursal` para devolver su
     * `indice_rotacion`.
     *
     * @param  string  $metricCode  Solo se soporta "turnover"; otro código → null.
     * @param  string  $scopeType  general|branch.
     * @param  int|null  $branchId  Sucursal cuando scope=branch.
     * @param  array  $snapshot  Snapshot de radiografía.
     * @return float|null Índice de rotación (%), o null si no está disponible.
     */
    private function fromSpecial(string $metricCode, string $scopeType, ?int $branchId, array $snapshot): ?float
    {
        if ($metricCode !== 'turnover') {
            return null;
        }

        $rotation = $snapshot['sections']['rotation'] ?? null;
        if (! is_array($rotation)) {
            return null;
        }

        if ($scopeType === self::SCOPE_GENERAL) {
            return isset($rotation['indice']) ? (float) $rotation['indice'] : null;
        }

        $branchName = $branchId ? Branch::find($branchId)?->name : null;
        if (! $branchName) {
            return null;
        }
        $branchNameUpper = mb_strtoupper(trim($branchName));
        foreach ($rotation['por_sucursal'] ?? [] as $row) {
            if (mb_strtoupper(trim((string) ($row['sucursal'] ?? ''))) === $branchNameUpper) {
                return isset($row['indice_rotacion']) ? (float) $row['indice_rotacion'] : null;
            }
        }

        return null;
    }

    /**
     * Lee el % de efectividad de cobranza de la versión histórica (sección
     * `efectividad_cobranza` del snapshot), nunca la calculadora "Aprobada".
     *
     * @param  array  $snapshot  Snapshot de radiografía.
     * @return float|null Porcentaje de efectividad, o null si no existe.
     */
    private function fromLegacyEffectiveness(array $snapshot): ?float
    {
        $value = $snapshot['sections']['efectividad_cobranza']['efectividad']['efectividad_pct'] ?? null;

        return $value === null ? null : (float) $value;
    }
}
