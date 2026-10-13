<?php

namespace App\Services\Efectividad;

use App\Enums\EfectividadCobranzaStage;
use App\Models\EfectividadCobranzaRun;
use App\Models\User;
use App\Services\Efectividad\V4\EfectividadV4FingerprintService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Forma ÚNICA en que una ejecución llega a la UI (página, polling, historial,
 * indicador de la cabecera). Todo sale de la BD — nada depende de estado
 * guardado solo en el navegador.
 */
class EfectividadCobranzaRunPresenter
{
    public function __construct(
        private readonly EfectividadCobranzaRunHealthService $health,
        private readonly EfectividadCobranzaSourceFingerprintService $fingerprints,
        private readonly EfectividadV4FingerprintService $v4Fingerprints,
    ) {}

    /** Identidad de una ejecución: periodo + sucursal + motor + modo + corte (nunca se mezclan versiones). */
    public static function identityKey(object $run): string
    {
        $cutoff = $run->cutoff_date instanceof \DateTimeInterface ? $run->cutoff_date->format('Y-m-d') : ($run->cutoff_date ? substr((string) $run->cutoff_date, 0, 10) : '');

        return $run->period_id.'|'.($run->branch_id ?? 'general').'|'.($run->engine_version ?? '').'|'.($run->mode ?? '').'|'.$cutoff;
    }

    /**
     * Resumen ligero (historial, ejecuciones activas, indicador de cabecera).
     *
     * @param  array<int,int>  $latestSuccessByIdentity  "period|branch" => id del éxito más reciente
     */
    public function summary(EfectividadCobranzaRun $run, array $latestSuccessByIdentity = []): array
    {
        $assessment = $this->health->assess($run);
        $isSuccess = $run->status === EfectividadCobranzaRun::STATUS_SUCCESS;

        $identity = self::identityKey($run);
        $isCurrentEngine = $run->isCurrentEngine();
        $supersededBy = $isSuccess && isset($latestSuccessByIdentity[$identity]) && $latestSuccessByIdentity[$identity] > $run->id
            ? $latestSuccessByIdentity[$identity]
            : null;

        // Un resultado de la calculadora anterior nunca se presenta como vigente.
        $displayState = ($supersededBy || ($isSuccess && ! $isCurrentEngine)) ? 'obsolete' : $assessment['state'];

        // Cancelación solicitada pero el Job todavía no llegó a su punto de
        // cancelación cooperativa: se comunica la intención, no el estado de salud.
        if ($run->isActive() && $run->isCancelRequested()) {
            $displayState = 'cancelling';
        }

        $stage = $run->current_stage ? EfectividadCobranzaStage::tryFrom($run->current_stage) : null;

        $start = $run->started_at;
        $end = $run->finished_at ?? ($run->isActive() ? now() : null);

        return [
            'id' => $run->id,
            'report' => $isCurrentEngine ? 'Efectividad de Cobranza V4' : 'Efectividad Aprobada (versión anterior)',
            'engine_version' => $run->engine_version,
            'engine_label' => $isCurrentEngine ? 'Motor V4 (MR LANA, oct-2026)' : 'Calculadora anterior (aprobada 2026-10)',
            'is_current_engine' => $isCurrentEngine,
            'mode' => $run->mode,
            'base_date' => $run->base_date?->format('Y-m-d'),
            'cutoff_date' => $run->cutoff_date?->format('Y-m-d'),
            'incidents_count' => $run->incidents_count,
            'period_id' => $run->period_id,
            'period_label' => $run->period?->label,
            'branch_id' => $run->branch_id,
            'branch_label' => $run->branch?->name ?? 'Todas las sucursales',
            'status' => $run->status,
            'display_state' => $displayState,
            'display_label' => EfectividadCobranzaRunHealthService::label($displayState),
            'health_message' => ($isSuccess && ! $isCurrentEngine) ? 'Generado con la calculadora anterior; no es comparable con el motor V4. Genera el reporte de nuevo para obtener un resultado vigente.' : $assessment['message'],
            'queue_state' => $assessment['queue_state'],
            'is_active' => $run->isActive(),
            'current_stage' => $stage ? [
                'key' => $stage->value,
                'label' => $stage->label(),
                'description' => $stage->description(),
                'position' => $stage->position(),
                'measurable' => $stage->measurable(),
            ] : null,
            'stage_count' => count(EfectividadCobranzaStage::cases()),
            'progress' => $this->progress($run, $stage),
            'log' => $run->log,
            'queued_at' => ($run->queued_at ?? $run->created_at)?->toISOString(),
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'heartbeat_at' => $run->heartbeat_at?->toISOString(),
            'duration_seconds' => $start && $end ? max(0, (int) $start->diffInSeconds($end)) : null,
            'attempts' => (int) $run->attempts,
            'requested_by' => $run->createdBy ? ['id' => $run->createdBy->id, 'name' => $run->createdBy->name] : null,
            'has_excel' => $isSuccess && $this->resolveExcelAbsolutePath($run) !== null,
            'failure_kind' => $run->failure_kind,
            'error_message' => $run->error_message,
            'can_cancel' => $run->canBeCancelled(),
            'cancel_requested_at' => $run->cancel_requested_at?->toISOString(),
            'cancelled_at' => $run->cancelled_at?->toISOString(),
            'superseded_by_id' => $supersededBy,
            'issue_counts' => [
                'critical' => collect($run->issues ?? [])->where('severity', 'critical')->count(),
                'warning' => collect($run->issues ?? [])->where('severity', 'warning')->count(),
            ],
            'notification' => [
                'status' => $run->notification_status,
                'notified_at' => $run->notified_at?->toISOString(),
                'error' => $run->notification_error,
                'attempts' => (int) $run->notification_attempts,
            ],
        ];
    }

    /** Detalle completo: etapas, fuentes, columnas, incidencias, resultados. */
    public function detail(EfectividadCobranzaRun $run, ?User $viewer = null): array
    {
        $run->loadMissing(['period', 'branch', 'createdBy']);

        $latestSuccess = EfectividadCobranzaRun::query()
            ->forIdentity($run->period_id, $run->branch_id)
            ->where('engine_version', $run->engine_version)
            ->when($run->mode === null, fn ($q) => $q->whereNull('mode'), fn ($q) => $q->where('mode', $run->mode))
            ->when($run->cutoff_date, fn ($q) => $q->whereDate('cutoff_date', $run->cutoff_date->format('Y-m-d')))
            ->where('status', EfectividadCobranzaRun::STATUS_SUCCESS)
            ->max('id');

        $summary = $this->summary($run, $latestSuccess ? [self::identityKey($run) => (int) $latestSuccess] : []);
        $isSuccess = $run->status === EfectividadCobranzaRun::STATUS_SUCCESS;

        // Vigencia: ¿cambiaron las fuentes desde que se generó? (solo en detalle — consulta agregada)
        $isStale = null;
        if ($isSuccess && $run->period && $run->source_fingerprint) {
            $isStale = $run->isCurrentEngine()
                ? $this->v4Fingerprints->isStale($run)
                : $this->fingerprints->isStale($run->period, $run->branch_id, $run->source_fingerprint);
        }

        $issues = collect($run->issues ?? [])->map(fn (array $i) => collect($i)->except('field')->all())->values()->all();

        if ($isStale) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-VIG-FUENTES-CAMBIARON',
                EfectividadCobranzaIssue::SEVERITY_INFO,
                EfectividadCobranzaIssue::CATEGORY_STALE,
                'Los datos de origen cambiaron después de generar este reporte (se cargó o reprocesó un archivo). Este resultado puede no reflejar la información actual.',
                'Genera el reporte nuevamente para obtener una versión vigente.',
                ['period' => $run->period?->label],
            );
        }

        return [
            ...$summary,
            'stages' => $this->timeline($run),
            'sources' => $run->sources,
            'columns' => $run->metadata['columns'] ?? null,
            'issues' => $issues,
            'coverage' => $run->coverage,
            'reconciliation' => $run->metadata['reconciliation'] ?? null,
            'rows' => $isSuccess ? ($run->result_rows ?? []) : [],
            'is_stale' => $isStale,
            'parameters' => $run->metadata['parametros'] ?? null,
            'incidents_summary' => array_values($run->metadata['incidencias_resumen'] ?? []),
            'technical_reference' => $viewer?->isAdmin() ? ($run->metadata['last_error_reference'] ?? null) : null,
        ];
    }

    /**
     * @param  Collection<int,EfectividadCobranzaRun>  $runs
     * @return array<string,int>
     */
    public function latestSuccessMap(Collection $runs): array
    {
        if ($runs->isEmpty()) {
            return [];
        }

        return EfectividadCobranzaRun::query()
            ->where('status', EfectividadCobranzaRun::STATUS_SUCCESS)
            ->whereIn('period_id', $runs->pluck('period_id')->unique()->values())
            ->selectRaw('period_id, branch_id, engine_version, mode, cutoff_date, MAX(id) as max_id')
            ->groupBy('period_id', 'branch_id', 'engine_version', 'mode', 'cutoff_date')
            ->get()
            ->mapWithKeys(fn ($r) => [self::identityKey($r) => (int) $r->max_id])
            ->all();
    }

    /**
     * Ruta absoluta del Excel. `output_excel_path` es RELATIVA al disco privado
     * `local`; las filas anteriores a esa corrección guardaron una ruta ABSOLUTA
     * y se siguen resolviendo tal cual (sin migrar ni borrar históricos).
     */
    public function resolveExcelAbsolutePath(EfectividadCobranzaRun $run): ?string
    {
        $stored = $run->output_excel_path;
        if (! $stored) {
            return null;
        }

        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $stored) === 1) {
            return file_exists($stored) ? $stored : null;
        }

        return Storage::disk('local')->exists($stored) ? Storage::disk('local')->path($stored) : null;
    }

    /** Porcentaje SOLO si la etapa actual es medible y tiene total conocido. */
    private function progress(EfectividadCobranzaRun $run, ?EfectividadCobranzaStage $stage): array
    {
        $total = $run->progress_total;
        $current = $run->progress_current;
        $determinate = $run->isActive() && $stage?->measurable() && $total !== null && $total > 0 && $current !== null;

        return [
            'current' => $current,
            'total' => $total,
            'percent' => $determinate ? (int) min(100, floor(($current / $total) * 100)) : null,
            'determinate' => (bool) $determinate,
        ];
    }

    /** Las etapas en orden, con el estado real registrado (o pendiente). */
    private function timeline(EfectividadCobranzaRun $run): array
    {
        $recorded = $run->stages ?? [];

        return array_map(function (array $stage) use ($recorded, $run) {
            $entry = $recorded[$stage['key']] ?? null;
            $status = $entry['status'] ?? 'pending';

            // Ejecuciones previas a este cambio no tienen línea de tiempo: no se inventa.
            if (! $entry && $run->isTerminal() && empty($recorded)) {
                $status = 'unknown';
            }

            return [
                ...$stage,
                'status' => $status,
                'started_at' => $entry['started_at'] ?? null,
                'finished_at' => $entry['finished_at'] ?? null,
                'processed' => $entry['processed'] ?? null,
                'total' => $entry['total'] ?? null,
                'summary' => $entry['summary'] ?? null,
                'details' => $entry['details'] ?? null,
            ];
        }, EfectividadCobranzaStage::catalog());
    }
}
