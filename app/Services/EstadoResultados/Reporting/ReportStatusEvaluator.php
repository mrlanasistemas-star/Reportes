<?php

namespace App\Services\EstadoResultados\Reporting;

/**
 * Deriva el estado EN VIVO de un dataset (no consulta la BD: sólo lee el dataset
 * canónico, así web/Excel/PDF y la aprobación llegan siempre al mismo veredicto).
 *
 *   pendiente_conciliacion → hay movimientos pendientes, el procesamiento no está al día,
 *                            o hay diferencias de conciliación abiertas.
 *   borrador               → falla una validación, falta un dato obligatorio o el estado de
 *                            una fuente no es satisfactorio.
 *   listo_revision         → TODO lo anterior en verde.
 *
 * Aprobación por LISTA BLANCA (fase 2.1): sólo los estados explícitamente satisfactorios
 * permiten aprobar. Un estado nulo, desconocido, fallido, incompleto o desactualizado
 * BLOQUEA con un motivo exacto (nunca se aprueba "porque no se encontró un error").
 *
 *   fuente                        estado(s) satisfactorio(s)
 *   sources.radiografia.status    procesado (consolidado de Radiografía vigente)
 *   sources.erp.status            procesado
 *   sources.nomina.status         procesado
 *   sources.cuentas[*].status     procesado (las DOS cuentas, cada una con su carga)
 *   sources.procesamiento.status  procesado · last_run.status = ok · sin stale_erp / unprocessed
 *   validation.status             ok
 *   validation.integrity.status   ok
 *   validation.unresolved[*]      ninguna con blocking = true
 *   checks[*].ok                  true
 *   avisos de carga               ninguno bloqueante (sources.cuentas[*].upload.blocking_warnings)
 *   ajustes (completeness)        ninguno «sin información»
 */
class ReportStatusEvaluator
{
    public const SATISFACTORY_SOURCE = 'procesado';

    public const SATISFACTORY_RUN = 'ok';

    public const SATISFACTORY_VALIDATION = 'ok';

    private const SOURCE_LABELS = [
        'radiografia' => 'Radiografía del periodo',
        'erp' => 'ERP de gastos',
        'nomina' => 'Nómina (NOI)',
        'procesamiento' => 'Conciliación y clasificación',
    ];

    /**
     * @return array{status:string, status_label:string, preliminary:bool, preliminary_reasons:list<string>,
     *               blockers:list<array{code:string,message:string}>, pending:array{count:int,amount:string},
     *               failed_checks:list<array{key:string,label:string,difference:string}>, missing:list<string>}
     */
    public function evaluate(array $d): array
    {
        $reconciliation = [];   // → pendiente_conciliacion
        $other = [];            // → borrador
        $add = function (array &$list, string $code, string $message) {
            $list[] = ['code' => $code, 'message' => $message];
        };

        // ── Pendientes de clasificar ──────────────────────────────────────
        $pending = [
            'count' => (int) ($d['conciliation']['pendientes']['count'] ?? 0),
            'amount' => (string) ($d['conciliation']['pendientes']['amount'] ?? '0.00'),
        ];
        if ($pending['count'] > 0) {
            $add($reconciliation, 'pendientes', $pending['count'].' movimiento(s) por $'.number_format((float) $pending['amount'], 2).' pendientes de clasificar: no suman al resultado hasta revisarlos.');
        }

        // ── Procesamiento (lista blanca) ──────────────────────────────────
        $sources = is_array($d['sources'] ?? null) ? $d['sources'] : [];
        $proc = is_array($sources['procesamiento'] ?? null) ? $sources['procesamiento'] : null;
        if ($proc === null) {
            $add($reconciliation, 'procesamiento', 'No hay información del procesamiento de la conciliación: no se puede aprobar.');
        } else {
            if (! empty($proc['unprocessed'])) {
                $add($reconciliation, 'procesamiento', 'Hay movimientos cargados sin procesar: presiona «Procesar conciliación».');
            }
            if (! empty($proc['stale_erp'])) {
                $add($reconciliation, 'procesamiento', 'El ERP de gastos cambió después del último procesamiento: vuelve a procesar la conciliación.');
            }
            $runStatus = $proc['last_run']['status'] ?? null;
            $noBank = ($proc['status'] ?? null) === 'pendiente'; // sin estados de cuenta: lo reporta «datos faltantes»
            if ($runStatus === null && $noBank) {
                // nada que procesar todavía
            } elseif ($runStatus === null) {
                $add($reconciliation, 'procesamiento', 'La conciliación nunca se ha procesado para este periodo: presiona «Procesar conciliación».');
            } elseif ($runStatus === 'error') {
                $add($reconciliation, 'procesamiento', 'El último procesamiento terminó con error: vuelve a procesar.');
            } elseif ($runStatus === 'en_proceso') {
                $add($reconciliation, 'procesamiento', 'Hay un procesamiento en curso: espera a que termine.');
            } elseif ($runStatus !== self::SATISFACTORY_RUN) {
                $add($reconciliation, 'procesamiento', 'El último procesamiento tiene un estado no reconocido («'.$this->str($runStatus).'»): vuelve a procesar.');
            }
            $procStatus = $proc['status'] ?? null;
            // Estado no satisfactorio sin un motivo más específico ya registrado (p. ej. nulo o desconocido).
            if (! $noBank && $procStatus !== self::SATISFACTORY_SOURCE && ($reconciliation === [] || ! in_array($procStatus, ['cargado', 'con_incidencias', 'pendiente'], true))) {
                $add($reconciliation, 'procesamiento', $this->sourceMessage('procesamiento', $procStatus));
            }
        }

        // ── Diferencias de conciliación abiertas (validation.unresolved) ──
        foreach ((array) ($d['validation']['unresolved'] ?? []) as $u) {
            if (! is_array($u) || in_array($u['key'] ?? null, ['pendientes'], true) || str_starts_with((string) ($u['key'] ?? ''), 'control_')) {
                continue; // pendientes y validaciones fallidas se reportan en su propio bloqueo
            }
            // Sin el campo `blocking` (datasets anteriores) se asume que BLOQUEA.
            if (($u['blocking'] ?? true) !== false) {
                $count = (int) ($u['open_count'] ?? $u['count'] ?? 0);
                $amount = (string) ($u['open_amount'] ?? $u['amount'] ?? '0.00');
                $add($reconciliation, 'diferencias', 'Diferencia de conciliación sin resolver: '.$this->str($u['label'] ?? $u['key'] ?? 'sin descripción').' ('.$count.' partida(s), $'.number_format((float) $amount, 2).').');
            }
        }
        $reconciliationOpen = $reconciliation !== [];

        // ── Validaciones matemáticas ───────────────────────────────────────
        $failed = [];
        foreach ((array) ($d['checks'] ?? []) as $c) {
            if (($c['ok'] ?? null) !== true) {
                $failed[] = ['key' => (string) ($c['key'] ?? '?'), 'label' => (string) ($c['label'] ?? $c['key'] ?? 'Validación'), 'difference' => (string) ($c['difference'] ?? '')];
            }
        }
        foreach ($failed as $f) {
            $add($other, 'validaciones', 'Validación no cuadra: '.$f['label'].' (diferencia $'.$f['difference'].').');
        }
        if (! isset($d['checks']) || ! is_array($d['checks']) || $d['checks'] === []) {
            $add($other, 'validaciones', 'El dataset no trae validaciones: no se puede aprobar.');
        }
        $vStatus = $d['validation']['status'] ?? null;
        if ($vStatus !== self::SATISFACTORY_VALIDATION && ! ($vStatus === 'con_pendientes' && $reconciliationOpen)) {
            $add($other, 'validaciones', match ($vStatus) {
                null => 'El dataset no trae el resultado de la validación financiera: no se puede aprobar.',
                'con_diferencias' => 'La validación financiera tiene diferencias (partición, importación o controles): revísalas.',
                'con_pendientes' => 'La validación financiera tiene partidas sin resolver.',
                default => 'La validación financiera tiene un estado no reconocido («'.$this->str($vStatus).'»).',
            });
        }
        $integrity = $d['validation']['integrity']['status'] ?? null;
        if ($integrity !== self::SATISFACTORY_VALIDATION) {
            $add($other, 'integridad', $integrity === null
                ? 'No hay información de integridad de las fuentes: no se puede aprobar.'
                : ($integrity === 'con_incidencias'
                    ? 'La integridad de las fuentes tiene incidencias (control de archivo, importación o ERP desactualizado).'
                    : 'La integridad de las fuentes tiene un estado no reconocido («'.$this->str($integrity).'»).'));
        }

        // ── Fuentes obligatorias (lista blanca) ────────────────────────────
        $missing = [];
        // Lista de fuentes (SourceChecklist): cuando existe, cada motivo dice EXACTAMENTE qué falta y
        // dónde se resuelve («Falta el ERP de gastos de Octubre 2026: … → Carga de archivos …»).
        $check = [];
        foreach ((array) ($sources['checklist']['items'] ?? []) as $ci) {
            if (is_array($ci) && isset($ci['key'])) {
                $check[$ci['key']] = $ci;
            }
        }
        $exact = fn (string $key) => isset($check[$key]) && ($check[$key]['state'] ?? 'lista') !== 'lista' && ! empty($check[$key]['reason']) ? (string) $check[$key]['reason'] : null;
        foreach (['radiografia', 'erp', 'nomina'] as $key) {
            $status = $sources[$key]['status'] ?? null;
            if ($status === 'pendiente' && ($m = $exact($key)) !== null) {
                $missing[] = $m;
            } elseif ($status === 'pendiente') {
                $missing[] = match ($key) {
                    'erp' => 'Falta el ERP de gastos del periodo.',
                    'nomina' => 'Falta la nómina (NOI) del periodo.',
                    default => 'Falta la Radiografía del periodo.',
                };
            } elseif ($status !== self::SATISFACTORY_SOURCE) {
                $add($other, 'fuentes', $exact($key) ?? $this->sourceMessage($key, $status));
            }
        }
        // NOI de corporativo: sólo bloquea si la configuración lo hace obligatorio.
        if (! empty($check['noi_corporativo']['required']) && ($m = $exact('noi_corporativo')) !== null) {
            $missing[] = $m;
        }
        $cuentas = $sources['cuentas'] ?? null;
        if (! is_array($cuentas) || $cuentas === []) {
            $missing[] = 'No hay información de los estados de cuenta del periodo.';
        } else {
            foreach ($cuentas as $cta) {
                $label = $this->str($cta['label'] ?? $cta['key'] ?? 'cuenta');
                $status = $cta['status'] ?? null;
                if ($status === 'pendiente') {
                    $missing[] = $exact((string) ($cta['key'] ?? '')) ?? 'Falta el estado de cuenta: '.$label.'.';
                } elseif ($status === 'cargado') {
                    $add($reconciliation, 'procesamiento', 'El estado de cuenta «'.$label.'» está cargado pero sin procesar.');
                } elseif ($status === 'con_incidencias') {
                    $add($other, 'fuentes', 'El estado de cuenta «'.$label.'» tiene incidencias (control que no cuadra o movimientos por revisar).');
                } elseif ($status !== self::SATISFACTORY_SOURCE) {
                    $add($other, 'fuentes', 'El estado de cuenta «'.$label.'» tiene un estado no reconocido («'.$this->str($status).'»).');
                }
                foreach ((array) ($cta['upload']['blocking_warnings'] ?? []) as $w) {
                    $add($other, 'avisos_carga', 'Aviso bloqueante en «'.$label.'»: '.$this->str($w['message'] ?? $w['code'] ?? 'aviso').'');
                }
            }
        }
        $adjMissing = (array) ($d['completeness']['utilidad_neta']['missing'] ?? []);
        if ($adjMissing !== []) {
            $missing[] = 'Ajustes sin información (no se asumen $0): '.implode(', ', $adjMissing).'.'
                .(isset($check['ajustes']) ? ' → Captura el importe o confirma $0 en la pestaña «Ajustes».' : '');
        } elseif (! isset($d['completeness']['utilidad_neta'])) {
            $missing[] = 'El dataset no trae la completitud de los ajustes contables.';
        }
        foreach ($missing as $msg) {
            $add($other, 'datos_faltantes', $msg);
        }

        $status = match (true) {
            $reconciliation !== [] => ReportStatus::PENDIENTE_CONCILIACION,
            $other !== [] => ReportStatus::BORRADOR,
            default => ReportStatus::LISTO_REVISION,
        };
        $blockers = array_merge($reconciliation, $other);

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

    private function sourceMessage(string $key, mixed $status): string
    {
        $label = self::SOURCE_LABELS[$key] ?? $key;

        return match (true) {
            $status === null => $label.': sin estado (dato faltante o desconocido): no se puede aprobar.',
            $status === 'con_incidencias' => $label.': tiene incidencias'.($key === 'radiografia' ? ' (no hay un consolidado de Radiografía vigente para el periodo)' : ($key === 'erp' ? ' (cambió después del último procesamiento)' : '')).'.',
            $status === 'cargado' => $label.': cargado pero sin procesar.',
            $status === 'pendiente' => $label.': pendiente.',
            default => $label.': estado no reconocido («'.$this->str($status).'»): no se puede aprobar.',
        };
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
