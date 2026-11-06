<?php

namespace App\Services\EstadoResultados;

use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosRun;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Classification\MovementClassifier;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment;
use Illuminate\Support\Facades\DB;

/**
 * Procesamiento (re-ejecutable e idempotente) de un periodo:
 *   1. lee los movimientos complementarios ya importados,
 *   2. concilia los cargos contra el ERP (EstadoResultadosExpenseReconciler),
 *   3. aplica las reglas automáticas (MovementClassifier),
 *   4. empareja traspasos entre cuentas (para no contarlos dos veces ni como gasto),
 *   5. guarda el resultado automático en cada movimiento + bitácora de corrida.
 *
 * No inserta ni borra movimientos: reprocesar N veces deja exactamente el mismo
 * estado. Las clasificaciones manuales viven aparte y no se tocan.
 */
class EstadoResultadosProcessor
{
    public function __construct(
        private readonly ErpExpenseRepository $erp,
        private readonly MovementClassifier $classifier,
        private readonly PeriodDataIds $dataIds,
    ) {}

    public function process(Period $period, ?int $userId = null): EstadoResultadosRun
    {
        $run = EstadoResultadosRun::query()->create([
            'period_id' => $period->id,
            'user_id' => $userId,
            'status' => 'en_proceso',
            'rules_version' => (string) config('estado_resultados.rules_version'),
            'started_at' => now(),
        ]);

        try {
            $summary = $this->run($period, $run);
            $run->update(['status' => 'ok', 'summary' => $summary['summary'], 'erp_signature' => $summary['erp_signature'], 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            throw $e;
        }

        return $run->fresh();
    }

    private function run(Period $period, EstadoResultadosRun $run): array
    {
        $dataIds = $this->dataIds->for($period);
        $erpRows = $this->erp->forDataIds($dataIds);

        $movements = EstadoResultadosMovement::query()
            ->where('period_id', $period->id)
            ->orderBy('movement_date')->orderBy('account_slot')->orderBy('source_row')
            ->get();

        $cargos = [];
        $folios = [];
        foreach ($movements as $m) {
            if ($m->in_period && (float) $m->cargo > 0) {
                $cargos[] = $this->movementInput($m);
                if ($m->folio_normalized) {
                    $folios[] = $m->folio_normalized;
                }
            }
        }

        $reconciler = EstadoResultadosExpenseReconciler::fromConfig();
        $erpFolios = array_flip(array_filter(array_column($erpRows, 'folio_normalized')));
        $missingFolios = array_values(array_filter(array_unique($folios), fn ($f) => ! isset($erpFolios[$f])));
        $outside = $this->erp->foliosOutside($dataIds, $missingFolios);
        $rec = $reconciler->reconcile($cargos, $erpRows, $outside);

        $pairs = $this->pairTransfers($movements);
        $now = now();
        $counts = ['por_tratamiento' => [], 'por_conciliacion' => []];

        DB::transaction(function () use ($movements, $rec, $pairs, $now, &$counts) {
            foreach ($movements as $m) {
                $input = $this->movementInput($m) + [
                    'concept' => $m->concept, 'observations' => $m->observations, 'bank_reference' => $m->bank_reference,
                    'abono' => $m->abono, 'in_period' => $m->in_period, 'sucursal_raw' => $m->sucursal_raw,
                ];
                $r = $rec['results'][$m->fingerprint] ?? null;
                $c = $this->classifier->classify($input, $r);

                $recStatus = match (true) {
                    ! $m->in_period => 'fuera_periodo',
                    $c['treatment'] === Treatment::TRANSFERENCIA_INTERNA && $c['rule'] === 'traspaso_cuentas_propias' => 'transferencia',
                    (float) $m->cargo <= 0 => 'abono',
                    default => $r['status'] ?? EstadoResultadosExpenseReconciler::SIN_FOLIO,
                };

                $m->forceFill([
                    'reconciliation_status' => $recStatus,
                    'reconciliation_detail' => $r,
                    'auto_treatment' => $c['treatment'],
                    'suggested_treatment' => $c['suggested'],
                    'rule_code' => $c['rule'],
                    'reason' => $c['reason'],
                    'needs_review' => $c['review'],
                    'transfer_pair_key' => $pairs[$m->id] ?? null,
                    'processed_at' => $now,
                ])->save();

                $counts['por_tratamiento'][$c['treatment']] = ($counts['por_tratamiento'][$c['treatment']] ?? 0) + 1;
                $counts['por_conciliacion'][$recStatus] = ($counts['por_conciliacion'][$recStatus] ?? 0) + 1;
            }

            EstadoResultadosUpload::query()->active()->whereIn('id', $movements->pluck('upload_id')->unique()->all())
                ->update(['status' => EstadoResultadosUpload::STATUS_PROCESSED]);
        });

        ksort($counts['por_tratamiento']);
        ksort($counts['por_conciliacion']);

        return [
            'erp_signature' => ErpExpenseRepository::signature($erpRows),
            'summary' => [
                'movimientos' => $movements->count(),
                'erp_filas' => count($erpRows),
                'erp_vinculadas' => count($rec['erp_links']),
                'tolerancia_dias' => $reconciler->toleranceDays(),
                'pares_traspaso' => count(array_unique($pairs)),
            ] + $counts,
        ];
    }

    private function movementInput(EstadoResultadosMovement $m): array
    {
        return [
            'fingerprint' => $m->fingerprint,
            'folio_normalized' => $m->folio_normalized,
            'cargo' => (string) $m->cargo,
            'movement_date' => $m->movement_date->format('Y-m-d'),
            'branch_name' => $m->branch_name,
            'branch_kind' => $m->branch_kind,
            'uuid' => $m->uuid,
            'source_row' => (int) $m->source_row,
            'account_slot' => $m->account_slot,
        ];
    }

    /**
     * Empareja traspasos: salida (cargo) de una cuenta ↔ entrada (abono) en la OTRA cuenta,
     * mismo importe exacto y fecha con diferencia ≤ 1 día. Determinista (orden fijo).
     *
     * @return array<int, string> movement id => clave del par
     */
    private function pairTransfers($movements): array
    {
        $isTransfer = function (EstadoResultadosMovement $m): bool {
            $txt = mb_strtoupper(($m->concept ?? '').' '.($m->bank_reference ?? ''));

            return str_contains($txt, 'TRASPASO');
        };
        $outs = $movements->filter(fn ($m) => $m->in_period && (float) $m->cargo > 0 && $isTransfer($m))->values();
        $ins = $movements->filter(fn ($m) => $m->in_period && (float) $m->abono > 0 && $isTransfer($m))->values();

        $pairs = [];
        $used = [];
        $n = 0;
        foreach ($outs as $out) {
            foreach ($ins as $in) {
                if (isset($used[$in->id]) || $in->account_slot === $out->account_slot) {
                    continue;
                }
                if (Money::cents($in->abono) !== Money::cents($out->cargo)) {
                    continue;
                }
                if (abs($in->movement_date->diffInDays($out->movement_date)) > 1) {
                    continue;
                }
                $n++;
                $key = 'TR-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
                $pairs[$out->id] = $key;
                $pairs[$in->id] = $key;
                $used[$in->id] = true;
                break;
            }
        }

        return $pairs;
    }
}
