<?php

namespace App\Services\EstadoResultados\Reporting;

/**
 * Deriva el estado EN VIVO de un dataset (no consulta la BD: sólo lee el dataset
 * canónico, así web/Excel/PDF y la aprobación llegan siempre al mismo veredicto).
 *
 *   pendiente_conciliacion → hay movimientos pendientes de clasificar o el procesamiento no está al día.
 *   borrador               → no hay pendientes, pero falla una validación o falta un dato obligatorio.
 *   listo_revision         → sin pendientes, todas las validaciones en $0.00 y sin datos obligatorios faltantes.
 *
 * Datos obligatorios: ERP de gastos, nómina (NOI), los dos estados de cuenta y los
 * ajustes que afectan el resultado (un "sin información" nunca se asume $0).
 */
class ReportStatusEvaluator
{
    /**
     * @return array{status:string, status_label:string, preliminary:bool, preliminary_reasons:list<string>,
     *               blockers:list<array{code:string,message:string}>, pending:array{count:int,amount:string},
     *               failed_checks:list<array{key:string,label:string,difference:string}>, missing:list<string>}
     */
    public function evaluate(array $d): array
    {
        $blockers = [];
        $pending = [
            'count' => (int) ($d['conciliation']['pendientes']['count'] ?? 0),
            'amount' => (string) ($d['conciliation']['pendientes']['amount'] ?? '0.00'),
        ];
        if ($pending['count'] > 0) {
            $blockers[] = ['code' => 'pendientes', 'message' => $pending['count'].' movimiento(s) por $'.number_format((float) $pending['amount'], 2).' pendientes de clasificar: no suman al resultado hasta revisarlos.'];
        }

        $proc = (array) ($d['sources']['procesamiento'] ?? []);
        if (! empty($proc['unprocessed'])) {
            $blockers[] = ['code' => 'procesamiento', 'message' => 'Hay movimientos cargados sin procesar: presiona «Procesar conciliación».'];
        }
        if (! empty($proc['stale_erp'])) {
            $blockers[] = ['code' => 'procesamiento', 'message' => 'El ERP de gastos cambió después del último procesamiento: vuelve a procesar la conciliación.'];
        }
        if (($proc['last_run']['status'] ?? null) === 'error') {
            $blockers[] = ['code' => 'procesamiento', 'message' => 'El último procesamiento terminó con error: vuelve a procesar.'];
        }
        $reconciliationOpen = $blockers !== [];

        $failed = [];
        foreach ((array) ($d['checks'] ?? []) as $c) {
            if (! ($c['ok'] ?? false)) {
                $failed[] = ['key' => (string) $c['key'], 'label' => (string) $c['label'], 'difference' => (string) ($c['difference'] ?? '')];
            }
        }
        foreach ($failed as $f) {
            $blockers[] = ['code' => 'validaciones', 'message' => 'Validación no cuadra: '.$f['label'].' (diferencia $'.$f['difference'].').'];
        }

        $missing = [];
        $sources = (array) ($d['sources'] ?? []);
        if (($sources['erp']['status'] ?? null) === 'pendiente') {
            $missing[] = 'Falta el ERP de gastos del periodo.';
        }
        if (($sources['nomina']['status'] ?? null) === 'pendiente') {
            $missing[] = 'Falta la nómina (NOI) del periodo.';
        }
        foreach ((array) ($sources['cuentas'] ?? []) as $cta) {
            if (($cta['status'] ?? null) === 'pendiente') {
                $missing[] = 'Falta el estado de cuenta: '.$cta['label'].'.';
            }
        }
        $adjMissing = (array) ($d['completeness']['utilidad_neta']['missing'] ?? []);
        if ($adjMissing !== []) {
            $missing[] = 'Ajustes sin información (no se asumen $0): '.implode(', ', $adjMissing).'.';
        }
        foreach ($missing as $msg) {
            $blockers[] = ['code' => 'datos_faltantes', 'message' => $msg];
        }

        $status = match (true) {
            $reconciliationOpen => ReportStatus::PENDIENTE_CONCILIACION,
            $failed !== [] || $missing !== [] => ReportStatus::BORRADOR,
            default => ReportStatus::LISTO_REVISION,
        };

        return [
            'status' => $status,
            'status_label' => ReportStatus::label($status),
            'preliminary' => $blockers !== [],
            'preliminary_reasons' => array_values(array_unique(array_column($blockers, 'message'))),
            'blockers' => $blockers,
            'pending' => $pending,
            'failed_checks' => $failed,
            'missing' => $missing,
        ];
    }
}
