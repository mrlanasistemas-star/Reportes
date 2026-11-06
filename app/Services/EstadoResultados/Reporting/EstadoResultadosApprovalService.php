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
 */
class EstadoResultadosApprovalService
{
    public const ACTION_LABELS = [
        'aprobado' => 'Versión aprobada',
        'nueva_version' => 'Nueva versión abierta',
        'sustituido' => 'Versión sustituida',
    ];

    public function __construct(
        private readonly EstadoResultadosDatasetBuilder $builder,
        private readonly ReportStatusEvaluator $evaluator,
        private readonly PeriodLockGuard $guard,
        private readonly ErpExpenseRepository $erp,
        private readonly PeriodDataIds $dataIds,
    ) {}

    /**
     * Evalúa si el periodo puede aprobarse HOY (consolidado completo + validaciones
     * de todos los alcances en $0.00). No escribe nada.
     *
     * @return array{approvable:bool, blockers:list<array<string,string>>, datasets:array<string,array>, evaluations:array<string,array>, scopes:list<array{key:string,label:string}>}
     */
    public function preview(Period $period): array
    {
        $scopes = $this->builder->scopes($period);
        $datasets = [];
        $evaluations = [];
        $blockers = [];
        foreach ($scopes as $s) {
            $d = $this->builder->build($period, $s['key']);
            $e = $this->evaluator->evaluate($d);
            $datasets[$s['key']] = $d;
            $evaluations[$s['key']] = $e;
            if ($s['key'] === EstadoResultadosDatasetBuilder::CONSOLIDADO) {
                foreach ($e['blockers'] as $b) {
                    $blockers[] = $b + ['scope' => $s['label']];
                }

                continue;
            }
            // En sucursales sólo bloquean las validaciones matemáticas (sus ajustes por debajo
            // del EBITDA pueden no capturarse; su exportación se marca PRELIMINAR).
            foreach ($e['failed_checks'] as $f) {
                $blockers[] = ['code' => 'validaciones', 'scope' => $s['label'], 'message' => $s['label'].': validación no cuadra — '.$f['label'].' (diferencia $'.$f['difference'].').'];
            }
        }

        return ['approvable' => $blockers === [], 'blockers' => $blockers, 'datasets' => $datasets, 'evaluations' => $evaluations, 'scopes' => $scopes];
    }

    public function approve(Period $period, User $user, ?string $notes = null, ?string $expectedHash = null): EstadoResultadosVersion
    {
        $lock = Cache::lock('estado_resultados:aprobacion:'.$period->id, 120);
        if (! $lock->get()) {
            throw new EstadoResultadosGovernanceException('Otro usuario está aprobando este periodo en este momento. Intenta de nuevo en unos segundos.', 409);
        }

        try {
            $this->guard->assertUnlocked($period);
            $preview = $this->preview($period);
            if (! $preview['approvable']) {
                $more = count($preview['blockers']) > 1 ? ' (y '.(count($preview['blockers']) - 1).' motivo(s) más)' : '';
                throw new EstadoResultadosGovernanceException(
                    'No se puede aprobar el Estado de Resultados de '.$period->label.': '.$preview['blockers'][0]['message'].$more,
                    422,
                    ['blockers' => $preview['blockers'], 'status' => $preview['evaluations'][EstadoResultadosDatasetBuilder::CONSOLIDADO]['status'] ?? null],
                );
            }
            $consolidated = $preview['datasets'][EstadoResultadosDatasetBuilder::CONSOLIDADO];
            if ($expectedHash !== null && $expectedHash !== '' && ! hash_equals($consolidated['meta']['dataset_hash'], $expectedHash)) {
                throw new EstadoResultadosGovernanceException(
                    'Las cifras cambiaron desde que las revisaste. Vuelve a cargar el reporte, revísalo y aprueba de nuevo.',
                    409,
                    ['current_hash' => $consolidated['meta']['dataset_hash']],
                );
            }

            return DB::transaction(fn () => $this->persist($period, $user, $notes, $preview));
        } finally {
            $lock->release();
        }
    }

    public function openNewVersion(Period $period, User $user, string $reason): EstadoResultadosVersion
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new EstadoResultadosGovernanceException('Escribe el motivo para abrir una nueva versión (mínimo 10 caracteres).', 422);
        }

        return DB::transaction(function () use ($period, $user, $reason) {
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

    private function persist(Period $period, User $user, ?string $notes, array $preview): EstadoResultadosVersion
    {
        $previous = EstadoResultadosVersion::query()->where('period_id', $period->id)->orderByDesc('version')->lockForUpdate()->first();
        if ($previous && $previous->isLocked()) {
            throw new EstadoResultadosLockedException($previous, $period->label);
        }
        $number = ($previous?->version ?? 0) + 1;

        $snapshots = [];
        foreach ($preview['scopes'] as $s) {
            $json = json_encode($preview['datasets'][$s['key']], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $snapshots[] = [
                'scope_key' => $s['key'],
                'scope_label' => mb_substr($s['label'], 0, 120),
                'dataset' => $json,
                'dataset_hash' => $preview['datasets'][$s['key']]['meta']['dataset_hash'],
                'payload_hash' => hash('sha256', $json),
            ];
        }
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
        $consolidated = $preview['datasets'][EstadoResultadosDatasetBuilder::CONSOLIDADO];
        $criterion = (string) ($consolidated['meta']['criterion'] ?? ReportStatus::DEFAULT_CRITERION);
        $snapshotHash = hash('sha256', json_encode([
            'period' => $period->id, 'version' => $number, 'rules_version' => $rulesVersion,
            'snapshots' => array_column($snapshots, 'payload_hash', 'scope_key'),
            'sources' => $sources, 'classifications' => $classifications, 'adjustments' => $adjustments,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        $version = EstadoResultadosVersion::query()->create([
            'period_id' => $period->id,
            'version' => $number,
            'status' => EstadoResultadosVersion::APROBADO,
            'rules_version' => $rulesVersion,
            'criterion' => $criterion,
            'dataset_hash' => $consolidated['meta']['dataset_hash'],
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
        ]);
        foreach ($snapshots as $s) {
            EstadoResultadosVersionSnapshot::query()->create($s + ['version_id' => $version->id]);
        }

        if ($previous) {
            $previous->update(['status' => EstadoResultadosVersion::SUSTITUIDO, 'superseded_at' => now(), 'superseded_by_version_id' => $version->id]);
            EstadoResultadosVersionLog::query()->create([
                'period_id' => $period->id, 'version_id' => $previous->id, 'version_number' => $previous->version,
                'action' => 'sustituido', 'reason' => 'Sustituida por la versión '.$number.'.', 'user_id' => $user->id,
            ]);
        }
        EstadoResultadosVersionLog::query()->create([
            'period_id' => $period->id, 'version_id' => $version->id, 'version_number' => $number,
            'action' => 'aprobado', 'reason' => $version->approval_notes, 'user_id' => $user->id,
            'details' => ['dataset_hash' => $version->dataset_hash, 'snapshot_hash' => $snapshotHash, 'scopes' => count($snapshots)],
        ]);

        return $version->fresh();
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
            'radiografia_summary_id' => DB::table('period_summaries')->where('period_id', $period->id)
                ->where('status', 'generated')->whereNull('invalidated_at')->value('id'),
        ];
    }
}
