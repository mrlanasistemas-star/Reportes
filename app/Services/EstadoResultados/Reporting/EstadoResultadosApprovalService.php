<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Models\EstadoResultados\EstadoResultadosAdjustment;
use App\Models\EstadoResultados\EstadoResultadosClassification;
use App\Models\EstadoResultados\EstadoResultadosRun;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\EstadoResultados\EstadoResultadosVersion;
use App\Models\EstadoResultados\EstadoResultadosVersionLog;
use App\Models\EstadoResultados\EstadoResultadosVersionSnapshot;
use App\Models\Period;
use App\Models\User;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\EstadoResultados\Support\RadiographySummary;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cierre (aprobación) y versionado del Estado de Resultados de un periodo.
 *
 *  approve():         congela el dataset de TODOS los alcances (consolidado + sucursales)
 *                     junto con sus insumos (archivos + sha256, firma ERP, corrida,
 *                     clasificaciones, ajustes, versión de reglas). Desde ese momento
 *                     el periodo queda bloqueado (HTTP 423) y web/Excel/PDF se sirven
 *                     del snapshot. La versión anterior (si la había) pasa a «sustituido».
 *  openNewVersion():  sólo con permiso «aprobar» y motivo obligatorio: desbloquea el
 *                     periodo para preparar la versión N+1 (queda en la bitácora).
 *
 * Consistencia (fase 2.2) — cada versión aprobada representa EXACTAMENTE el dataset validado:
 *   1. Candado de aplicación por periodo (Cache::lock con dueño): dos aprobaciones
 *      simultáneas → la segunda recibe 409. Antes de escribir se verifica que el candado
 *      SIGA siendo de este proceso (si expiró durante una corrida larga, se rechaza).
 *   2. Firma de insumos (ApprovalInputSignature) ANTES de construir los datasets y otra
 *      vez DENTRO de la transacción, con la fila del periodo bloqueada FOR UPDATE (todas
 *      las escrituras del ER toman ese mismo bloqueo y revisan el candado de versión):
 *      si cambió un movimiento, clasificación, ajuste, ERP, NOI, Radiografía o carga, 409.
 *   3. expected_hash (lo que el usuario revisó) contra el hash del consolidado construido.
 *   4. La última versión observada al empezar debe seguir siendo la última (nadie aprobó
 *      ni abrió versión en medio).
 *   5. BD: UNIQUE(period_id, version) y UNIQUE(current_period_id) (una sola vigente).
 *   6. Tras insertar, cada snapshot se relee y se verifica sha256(JSON) = payload_hash y
 *      dataset_hash = el validado; cualquier diferencia revierte la transacción.
 */
class EstadoResultadosApprovalService
{
    public const ACTION_LABELS = [
        'aprobado' => 'Versión aprobada',
        'nueva_version' => 'Nueva versión abierta',
        'sustituido' => 'Versión sustituida',
        'aprobacion_rechazada' => 'Aprobación rechazada (datos cambiaron o conflicto)',
    ];

    public function __construct(
        private readonly EstadoResultadosDatasetBuilder $builder,
        private readonly ReportStatusEvaluator $evaluator,
        private readonly PeriodLockGuard $guard,
        private readonly ErpExpenseRepository $erp,
        private readonly PeriodDataIds $dataIds,
        private readonly ApprovalInputSignature $signature,
    ) {}

    public static function lockKey(int $periodId): string
    {
        return 'estado_resultados:aprobacion:'.$periodId;
    }

    /**
     * Evalúa si el periodo puede aprobarse HOY (consolidado completo + validaciones
     * de todos los alcances en $0.00). No escribe nada. Todos los alcances salen de la
     * MISMA foto de datos (EstadoResultadosDatasetBuilder::buildEach) y no se guardan
     * los arreglos completos: sólo el JSON que se congelaría y sus hashes.
     *
     * @return array{approvable:bool, blockers:list<array<string,string>>, evaluations:array<string,array>,
     *               scopes:list<array{key:string,label:string}>, hashes:array<string,string>,
     *               snapshots:array<string,array{scope_key:string,scope_label:string,dataset:string,dataset_hash:string,payload_hash:string}>,
     *               consolidated:array{dataset_hash:string, criterion:?string}}
     */
    public function preview(Period $period): array
    {
        $evaluations = [];
        $blockers = [];
        $snapshots = [];
        $consolidated = null;

        // OJO: closures normales (no fn) para que las referencias lleguen al callback.
        $collect = function (string $key, array $d, array $s) use (&$evaluations, &$blockers, &$snapshots, &$consolidated) {
            $e = $this->evaluator->evaluate($d);
            $evaluations[$key] = $e;
            if ($key === EstadoResultadosDatasetBuilder::CONSOLIDADO) {
                foreach ($e['blockers'] as $b) {
                    $blockers[] = $b + ['scope' => $s['label']];
                }
                $consolidated = ['dataset_hash' => (string) $d['meta']['dataset_hash'], 'criterion' => $d['meta']['criterion'] ?? null];
            } else {
                // En sucursales sólo bloquean las validaciones matemáticas (sus ajustes por debajo
                // del EBITDA pueden no capturarse; su exportación se marca PRELIMINAR).
                foreach ($e['failed_checks'] as $f) {
                    $blockers[] = ['code' => 'validaciones', 'scope' => $s['label'], 'message' => $s['label'].': validación no cuadra — '.$f['label'].' (diferencia $'.$f['difference'].').'];
                }
            }
            $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $snapshots[$key] = [
                'scope_key' => $key,
                'scope_label' => mb_substr($s['label'], 0, 120),
                'dataset' => $json,
                'dataset_hash' => (string) $d['meta']['dataset_hash'],
                'payload_hash' => hash('sha256', $json),
            ];
        };
        $scopes = $this->builder->session(function () use ($period, $collect) {
            return $this->builder->buildEach($period, null, $collect);
        });

        return [
            'approvable' => $blockers === [],
            'blockers' => $blockers,
            'evaluations' => $evaluations,
            'scopes' => $scopes,
            'hashes' => array_map(fn ($s) => $s['dataset_hash'], $snapshots),
            'snapshots' => $snapshots,
            'consolidated' => $consolidated ?? ['dataset_hash' => '', 'criterion' => null],
        ];
    }

    public function approve(Period $period, User $user, ?string $notes = null, ?string $expectedHash = null): EstadoResultadosVersion
    {
        $lock = Cache::lock(self::lockKey($period->id), max(60, (int) config('estado_resultados.aprobacion.lock_segundos', 900)));
        if (! $lock->get()) {
            throw new EstadoResultadosGovernanceException('Otro usuario está aprobando este periodo en este momento. Intenta de nuevo en unos segundos.', 409, ['conflict' => 'aprobacion_en_curso']);
        }

        try {
            $this->guard->assertUnlocked($period);
            $startLatest = $this->latestState($period);
            $before = $this->signature->compute($period);
            $preview = $this->preview($period);
            if (! $preview['approvable']) {
                $more = count($preview['blockers']) > 1 ? ' (y '.(count($preview['blockers']) - 1).' motivo(s) más)' : '';
                throw new EstadoResultadosGovernanceException(
                    'No se puede aprobar el Estado de Resultados de '.$period->label.': '.$preview['blockers'][0]['message'].$more,
                    422,
                    ['blockers' => $preview['blockers'], 'status' => $preview['evaluations'][EstadoResultadosDatasetBuilder::CONSOLIDADO]['status'] ?? null],
                );
            }
            $currentHash = $preview['consolidated']['dataset_hash'];
            if ($expectedHash !== null && $expectedHash !== '' && ! hash_equals($currentHash, $expectedHash)) {
                throw new EstadoResultadosGovernanceException(
                    'Las cifras cambiaron desde que las revisaste. Vuelve a cargar el reporte, revísalo y aprueba de nuevo.',
                    409,
                    ['current_hash' => $currentHash, 'conflict' => 'hash_revisado'],
                );
            }
            $this->assertLockOwned($lock);

            try {
                return DB::transaction(function () use ($period, $user, $notes, $preview, $before, $startLatest, $lock) {
                    // Mismo bloqueo de fila que toman cargas, procesamiento, clasificación y ajustes.
                    DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();
                    $after = $this->signature->compute($period);
                    if (! hash_equals($before['hash'], $after['hash'])) {
                        throw new EstadoResultadosGovernanceException(
                            'Los datos del periodo cambiaron mientras se aprobaba ('.implode(', ', $this->humanDiff(ApprovalInputSignature::diff($before, $after))).'). '
                            .'No se aprobó nada: vuelve a cargar el reporte, revísalo y aprueba de nuevo.',
                            409,
                            ['conflict' => 'datos_cambiaron', 'changed' => ApprovalInputSignature::diff($before, $after)],
                        );
                    }
                    if ($this->latestState($period) !== $startLatest) {
                        throw new EstadoResultadosGovernanceException(
                            'Otro usuario aprobó o abrió una versión de '.$period->label.' mientras se aprobaba. Vuelve a cargar el reporte.',
                            409,
                            ['conflict' => 'version_cambio'],
                        );
                    }
                    $this->assertLockOwned($lock);

                    return $this->persist($period, $user, $notes, $preview, $after['hash']);
                });
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    throw new EstadoResultadosGovernanceException('Otro usuario aprobó '.$period->label.' al mismo tiempo; se conservó su versión. Vuelve a cargar el reporte.', 409, ['conflict' => 'version_duplicada']);
                }

                throw $e;
            }
        } catch (EstadoResultadosGovernanceException $e) {
            if ($e->status() === 409) {
                $this->logRejection($period, $user, $e);
            }

            throw $e;
        } finally {
            $lock->release(); // sólo libera si el dueño sigue siendo este proceso
        }
    }

    public function openNewVersion(Period $period, User $user, string $reason): EstadoResultadosVersion
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new EstadoResultadosGovernanceException('Escribe el motivo para abrir una nueva versión (mínimo 10 caracteres).', 422);
        }

        return DB::transaction(function () use ($period, $user, $reason) {
            DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();
            $current = EstadoResultadosVersion::query()->where('period_id', $period->id)->orderByDesc('version')->lockForUpdate()->first();
            if (! $current || ! $current->isLocked()) {
                throw new EstadoResultadosGovernanceException(
                    $current
                        ? 'Ya hay una nueva versión abierta (versión '.($current->version + 1).') para '.$period->label.'.'
                        : 'El periodo '.$period->label.' no tiene una versión aprobada.',
                    422,
                );
            }
            $current->update(['reopened_at' => now(), 'reopened_by' => $user->id, 'reopen_reason' => mb_substr($reason, 0, 2000)]);
            EstadoResultadosVersionLog::query()->create([
                'period_id' => $period->id, 'version_id' => $current->id, 'version_number' => $current->version + 1,
                'action' => 'nueva_version', 'reason' => mb_substr($reason, 0, 2000), 'user_id' => $user->id,
                'details' => ['previous_version' => $current->version, 'previous_snapshot_hash' => $current->snapshot_hash],
            ]);

            return $current->fresh();
        });
    }

    /** @return array{locked:bool, current:?array, draft_version:?int, versions:list<array>, logs:list<array>} */
    public function history(Period $period): array
    {
        $versions = EstadoResultadosVersion::query()->with(['approver:id,name', 'reopener:id,name'])
            ->where('period_id', $period->id)->orderByDesc('version')->get();
        $latest = $versions->first();
        $logs = EstadoResultadosVersionLog::query()->with('user:id,name')->where('period_id', $period->id)->orderByDesc('id')->limit(100)->get();

        return [
            'locked' => (bool) $latest?->isLocked(),
            'current' => $latest ? self::summary($latest) : null,
            'draft_version' => $latest && $latest->isLocked() ? null : ($latest?->version ?? 0) + 1,
            'versions' => $versions->map(fn ($v) => self::summary($v))->values()->all(),
            'logs' => $logs->map(fn ($l) => [
                'id' => $l->id,
                'action' => $l->action,
                'action_label' => self::ACTION_LABELS[$l->action] ?? $l->action,
                'version' => $l->version_number,
                'reason' => $l->reason,
                'user' => $l->user?->name,
                'at' => $l->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
            ])->values()->all(),
        ];
    }

    public static function summary(EstadoResultadosVersion $v): array
    {
        $tz = 'America/Mexico_City';
        $status = $v->status === EstadoResultadosVersion::SUSTITUIDO ? ReportStatus::SUSTITUIDO : ReportStatus::APROBADO;

        return [
            'id' => $v->id,
            'version' => $v->version,
            'status' => $status,
            'status_label' => ReportStatus::label($status),
            'locked' => $v->isLocked(),
            'rules_version' => $v->rules_version,
            'dataset_hash' => $v->dataset_hash,
            'snapshot_hash' => $v->snapshot_hash,
            'scopes' => array_column((array) $v->scopes, 'key'),
            'approved_by' => $v->approver?->name,
            'approved_at' => $v->approved_at?->timezone($tz)->format('d/m/Y H:i'),
            'approval_notes' => $v->approval_notes,
            'reopened' => $v->reopened_at ? [
                'at' => $v->reopened_at->timezone($tz)->format('d/m/Y H:i'),
                'by' => $v->reopener?->name,
                'reason' => $v->reopen_reason,
            ] : null,
            'superseded_at' => $v->superseded_at?->timezone($tz)->format('d/m/Y H:i'),
            'superseded_by_version_id' => $v->superseded_by_version_id,
        ];
    }

    private function persist(Period $period, User $user, ?string $notes, array $preview, string $inputSignature): EstadoResultadosVersion
    {
        $previous = EstadoResultadosVersion::query()->where('period_id', $period->id)->orderByDesc('version')->lockForUpdate()->first();
        if ($previous && $previous->isLocked()) {
            throw new EstadoResultadosLockedException($previous, $period->label);
        }
        $number = ($previous?->version ?? 0) + 1;
        $guarantees = EstadoResultadosVersion::hasGuaranteeColumns();

        $snapshots = array_values($preview['snapshots']);
        $sources = $this->sources($period);
        $classifications = EstadoResultadosClassification::query()->where('period_id', $period->id)->orderBy('id')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'fingerprint' => $c->item_fingerprint, 'source' => $c->item_source, 'treatment' => $c->treatment,
                'previous' => $c->previous_treatment, 'notes' => $c->notes, 'user_id' => $c->user_id, 'at' => $c->created_at?->toIso8601String(),
            ])->all();
        $adjustments = EstadoResultadosAdjustment::query()->where('period_id', $period->id)->orderBy('scope_key')->orderBy('concept')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'scope' => $a->scope_key, 'concept' => $a->concept, 'state' => $a->state, 'amount' => $a->amount,
                'notes' => $a->notes, 'updated_by' => $a->updated_by, 'updated_at' => $a->updated_at?->toIso8601String(),
            ])->all();
        $rulesVersion = (string) config('estado_resultados.rules_version');
        $criterion = (string) ($preview['consolidated']['criterion'] ?? ReportStatus::DEFAULT_CRITERION);
        $snapshotHash = hash('sha256', json_encode([
            'period' => $period->id, 'version' => $number, 'rules_version' => $rulesVersion,
            'snapshots' => array_column($snapshots, 'payload_hash', 'scope_key'),
            'sources' => $sources, 'classifications' => $classifications, 'adjustments' => $adjustments,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        // La anterior deja de ser la vigente ANTES de insertar la nueva (UNIQUE current_period_id).
        if ($previous) {
            $previous->update(['status' => EstadoResultadosVersion::SUSTITUIDO, 'superseded_at' => now()]
                + ($guarantees ? ['current_period_id' => null] : []));
        }

        $version = EstadoResultadosVersion::query()->create([
            'period_id' => $period->id,
            'version' => $number,
            'status' => EstadoResultadosVersion::APROBADO,
            'rules_version' => $rulesVersion,
            'criterion' => $criterion,
            'dataset_hash' => $preview['consolidated']['dataset_hash'],
            'snapshot_hash' => $snapshotHash,
            'scopes' => $preview['scopes'],
            'sources' => $sources,
            'classifications' => $classifications,
            'adjustments' => $adjustments,
            'evaluation' => ['scopes' => array_map(
                fn ($e) => array_intersect_key($e, array_flip(['status', 'preliminary', 'preliminary_reasons', 'missing'])),
                $preview['evaluations'],
            )],
            'approved_by' => $user->id,
            'approved_at' => now(),
            'approval_notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 2000) : null,
        ] + ($guarantees ? ['current_period_id' => $period->id, 'input_signature' => $inputSignature] : []));
        foreach ($snapshots as $s) {
            EstadoResultadosVersionSnapshot::query()->create($s + ['version_id' => $version->id]);
        }
        $this->verifyStoredSnapshots($version, $snapshots);

        if ($previous) {
            $previous->update(['superseded_by_version_id' => $version->id]);
            EstadoResultadosVersionLog::query()->create([
                'period_id' => $period->id, 'version_id' => $previous->id, 'version_number' => $previous->version,
                'action' => 'sustituido', 'reason' => 'Sustituida por la versión '.$number.'.', 'user_id' => $user->id,
            ]);
        }
        EstadoResultadosVersionLog::query()->create([
            'period_id' => $period->id, 'version_id' => $version->id, 'version_number' => $number,
            'action' => 'aprobado', 'reason' => $version->approval_notes, 'user_id' => $user->id,
            'details' => ['dataset_hash' => $version->dataset_hash, 'snapshot_hash' => $snapshotHash, 'scopes' => count($snapshots), 'input_signature' => $inputSignature],
        ]);

        return $version->fresh();
    }

    /** Relee lo guardado: cada snapshot debe ser byte a byte el dataset validado. */
    private function verifyStoredSnapshots(EstadoResultadosVersion $version, array $expected): void
    {
        $stored = EstadoResultadosVersionSnapshot::query()->where('version_id', $version->id)->get()->keyBy('scope_key');
        if ($stored->count() !== count($expected)) {
            throw new \RuntimeException('La versión '.$version->version.' no guardó todos los alcances: se revirtió la aprobación.');
        }
        foreach ($expected as $s) {
            $row = $stored->get($s['scope_key']);
            if (! $row || ! hash_equals($s['payload_hash'], hash('sha256', (string) $row->dataset)) || ! hash_equals($s['dataset_hash'], (string) $row->dataset_hash)) {
                throw new \RuntimeException('El snapshot «'.$s['scope_key'].'» no coincide con el dataset validado: se revirtió la aprobación.');
            }
        }
    }

    /** Estado de la última versión (número, estado y reapertura) para detectar cambios en medio. */
    private function latestState(Period $period): ?string
    {
        $v = EstadoResultadosVersion::query()->where('period_id', $period->id)->orderByDesc('version')->first(['id', 'version', 'status', 'reopened_at']);

        return $v ? implode('|', [$v->id, $v->version, $v->status, $v->reopened_at?->toIso8601String()]) : null;
    }

    private function assertLockOwned(Lock $lock): void
    {
        if (method_exists($lock, 'isOwnedByCurrentProcess') && ! $lock->isOwnedByCurrentProcess()) {
            throw new EstadoResultadosGovernanceException(
                'La aprobación tardó más que el tiempo máximo reservado y otro proceso pudo tomar el periodo. No se aprobó nada: inténtalo de nuevo.',
                409,
                ['conflict' => 'candado_expirado'],
            );
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driver = (int) ($e->errorInfo[1] ?? 0);

        return $state === '23000' || $state === '23505' || $driver === 1062 || $driver === 19 || str_contains(mb_strtolower($e->getMessage()), 'unique');
    }

    private const SIGNATURE_LABELS = [
        'uploads' => 'cargas de estados de cuenta', 'movements' => 'movimientos bancarios', 'links' => 'conciliación',
        'runs' => 'procesamiento', 'classifications' => 'clasificaciones', 'adjustments' => 'ajustes contables',
        'erp_signature' => 'ERP de gastos', 'radiografia_summary' => 'consolidado de Radiografía', 'branches' => 'sucursales',
        'rotacion' => 'rotación', 'data_ids' => 'semanas del periodo', 'rules' => 'versión de reglas', 'period' => 'fechas del periodo',
        'fact.report_uploads' => 'cargas de Radiografía', 'fact.fact_expenses' => 'gastos (ERP/Lendus)', 'fact.fact_noi_movements' => 'nómina NOI',
        'fact.fact_recoveries' => 'recuperación', 'fact.fact_portfolios' => 'cartera', 'fact.fact_placements' => 'colocación',
        'fact.fact_imss' => 'IMSS', 'fact.employee_branch_assignments' => 'asignación de sucursales',
    ];

    /** @param list<string> $keys */
    private function humanDiff(array $keys): array
    {
        return array_values(array_unique(array_map(fn ($k) => self::SIGNATURE_LABELS[$k] ?? $k, $keys))) ?: ['insumos'];
    }

    private function logRejection(Period $period, User $user, EstadoResultadosGovernanceException $e): void
    {
        try {
            EstadoResultadosVersionLog::query()->create([
                'period_id' => $period->id, 'version_id' => null, 'version_number' => null,
                'action' => 'aprobacion_rechazada', 'reason' => mb_substr($e->getMessage(), 0, 2000), 'user_id' => $user->id,
                'details' => array_intersect_key($e->payload(), array_flip(['conflict', 'changed', 'current_hash'])),
            ]);
        } catch (\Throwable) {
            // La bitácora nunca debe ocultar el motivo real del rechazo.
        }
    }

    private function sources(Period $period): array
    {
        $dataIds = $this->dataIds->for($period);
        $lastRun = EstadoResultadosRun::query()->where('period_id', $period->id)->latest('id')->first();

        return [
            'uploads' => EstadoResultadosUpload::query()->active()->where('period_id', $period->id)->orderBy('id')->get()
                ->map(fn ($u) => [
                    'id' => $u->id, 'slot' => $u->account_slot, 'name' => $u->original_name, 'file_hash' => $u->file_hash,
                    'file_size' => (int) $u->file_size, 'stored_path' => $u->stored_path, 'movements' => (int) $u->movements_count,
                    'total_cargos' => (string) $u->total_cargos, 'total_abonos' => (string) $u->total_abonos,
                    'control_ok' => $u->control_ok, 'uploaded_by' => $u->uploaded_by, 'uploaded_at' => $u->created_at?->toIso8601String(),
                ])->all(),
            'erp_signature' => ErpExpenseRepository::signature($this->erp->forDataIds($dataIds)),
            'data_period_ids' => array_values($dataIds),
            'last_run' => $lastRun ? [
                'id' => $lastRun->id, 'status' => $lastRun->status, 'rules_version' => $lastRun->rules_version,
                'erp_signature' => $lastRun->erp_signature, 'finished_at' => $lastRun->finished_at?->toIso8601String(),
            ] : null,
            // Mismo criterio que Radiografía (vigente, no invalidado, id más reciente) — ver RadiographySummary.
            'radiografia_summary_id' => RadiographySummary::currentId($period->id),
        ];
    }
}
