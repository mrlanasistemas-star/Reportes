<?php

namespace App\Services\EstadoResultados\Reconciliation;

use App\Services\EstadoResultados\Support\Money;

/**
 * Conciliación determinista de CARGOS bancarios complementarios contra el ERP.
 *
 * Es una función pura (sin BD): mismos datos de entrada ⇒ mismo resultado.
 * Nunca descarta un movimiento sólo por compartir folio.
 *
 * Orden de evaluación por movimiento (cargos dentro del periodo):
 *   0. UUID: mismo CFDI + mismo importe + misma fecha que un cargo anterior → duplicado_uuid.
 *   1. Folio (REQ normalizado) → candidatos ERP con ese folio:
 *      a) 1:1 exacto: mismo importe en centavos y misma fecha → conciliado_erp_exacto.
 *      b) 1:1 con tolerancia explícita (config) → conciliado_erp_tolerancia (se registra Δ días).
 *      c) 1:N: varios pagos del mismo folio cuya suma EXACTA = total ERP y cuyas fechas
 *         caen en la ventana [captura − tol, pago + tol] → conciliado_erp_agregado.
 *         Si más de una combinación cuadra → folio_ambiguo (revisión, no se consume).
 *      d) Sin conciliación: folio_fecha_diferente | folio_ya_conciliado | folio_monto_diferente.
 *      e) Folio inexistente en el ERP del periodo: folio_otro_periodo (si existe en otro) o folio_sin_erp.
 *   2. Sin folio → huella estricta (importe exacto + fecha ± tol + sucursal compatible):
 *      con candidatos → posible_duplicado_erp (revisión; NUNCA se descarta en automático);
 *      sin candidatos → sin_folio (pasa a las reglas de clasificación).
 */
class EstadoResultadosExpenseReconciler
{
    public const EXACTO = 'conciliado_erp_exacto';

    public const TOLERANCIA = 'conciliado_erp_tolerancia';

    public const AGREGADO = 'conciliado_erp_agregado';

    public const FOLIO_MONTO_DIFERENTE = 'folio_monto_diferente';

    public const FOLIO_FECHA_DIFERENTE = 'folio_fecha_diferente';

    public const FOLIO_YA_CONCILIADO = 'folio_ya_conciliado';

    public const FOLIO_AMBIGUO = 'folio_ambiguo';

    public const FOLIO_OTRO_PERIODO = 'folio_otro_periodo';

    public const FOLIO_SIN_ERP = 'folio_sin_erp';

    public const POSIBLE_DUPLICADO_ERP = 'posible_duplicado_erp';

    public const SIN_FOLIO = 'sin_folio';

    public const DUPLICADO_UUID = 'duplicado_uuid';

    public const CONCILIADOS = [self::EXACTO, self::TOLERANCIA, self::AGREGADO];

    public const REVISION = [
        self::FOLIO_MONTO_DIFERENTE, self::FOLIO_FECHA_DIFERENTE, self::FOLIO_YA_CONCILIADO,
        self::FOLIO_AMBIGUO, self::FOLIO_OTRO_PERIODO, self::POSIBLE_DUPLICADO_ERP,
    ];

    public const LABELS = [
        self::EXACTO => 'Conciliado con ERP (exacto)',
        self::TOLERANCIA => 'Conciliado con ERP (fecha con tolerancia)',
        self::AGREGADO => 'Conciliación 1:N (varios pagos)',
        self::FOLIO_MONTO_DIFERENTE => 'Mismo folio, monto diferente',
        self::FOLIO_FECHA_DIFERENTE => 'Mismo folio y monto, fecha diferente',
        self::FOLIO_YA_CONCILIADO => 'Folio ya conciliado con otro pago',
        self::FOLIO_AMBIGUO => 'Folio con varias combinaciones posibles',
        self::FOLIO_OTRO_PERIODO => 'Folio registrado en ERP de otro periodo',
        self::FOLIO_SIN_ERP => 'Folio no encontrado en ERP',
        self::POSIBLE_DUPLICADO_ERP => 'Posible duplicado de ERP (sin folio)',
        self::SIN_FOLIO => 'Sin folio, sin coincidencia ERP',
        self::DUPLICADO_UUID => 'Duplicado por UUID',
        'transferencia' => 'Transferencia entre cuentas',
        'abono' => 'Abono (no aplica conciliación de gastos)',
        'fuera_periodo' => 'Fuera del periodo',
    ];

    public function __construct(
        private readonly int $toleranceDays = 3,
        private readonly int $maxGroupSize = 8,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            max(0, (int) config('estado_resultados.conciliacion.tolerancia_dias', 3)),
            max(2, (int) config('estado_resultados.conciliacion.max_pagos_agregados', 8)),
        );
    }

    public function toleranceDays(): int
    {
        return $this->toleranceDays;
    }

    /**
     * @param  list<array{fingerprint:string, folio_normalized:?string, cargo:string, movement_date:string, branch_name:?string, branch_kind:string, uuid:?string, source_row:int, account_slot:string}>  $movements  cargos dentro del periodo
     * @param  list<array<string,mixed>>  $erpRows  ErpExpenseRepository::forDataIds()
     * @param  array<string, list<array<string,mixed>>>  $foliosOutside  ErpExpenseRepository::foliosOutside()
     * @return array{results: array<string, array<string,mixed>>, erp_links: array<string, list<string>>}
     */
    public function reconcile(array $movements, array $erpRows, array $foliosOutside = []): array
    {
        usort($movements, fn ($a, $b) => [$a['movement_date'], $a['account_slot'], $a['source_row']] <=> [$b['movement_date'], $b['account_slot'], $b['source_row']]);

        $erpByFp = [];
        $erpByFolio = [];
        foreach ($erpRows as $erp) {
            $erpByFp[$erp['fingerprint']] = $erp;
            if ($erp['folio_normalized'] !== null) {
                $erpByFolio[$erp['folio_normalized']][] = $erp['fingerprint'];
            }
        }
        foreach ($erpByFolio as &$fps) {
            usort($fps, fn ($a, $b) => [$erpByFp[$a]['date'] ?? '', $erpByFp[$a]['amount'], $a] <=> [$erpByFp[$b]['date'] ?? '', $erpByFp[$b]['amount'], $b]);
        }
        unset($fps);

        $results = [];
        $consumedErp = [];   // erp fingerprint => list<movement fingerprint>
        $pending = [];

        // ── 0. UUID ───────────────────────────────────────────────────────
        $seenUuid = [];
        foreach ($movements as $m) {
            $uuid = $m['uuid'] ?? null;
            if ($uuid) {
                $key = $uuid.'|'.Money::cents($m['cargo']).'|'.$m['movement_date'];
                if (isset($seenUuid[$key])) {
                    $results[$m['fingerprint']] = [
                        'status' => self::DUPLICADO_UUID,
                        'duplicate_of' => $seenUuid[$key],
                        'message' => 'Mismo UUID, importe y fecha que otro movimiento ya considerado: no se cuenta dos veces.',
                    ];

                    continue;
                }
                $seenUuid[$key] = $m['fingerprint'];
            }
            $pending[] = $m;
        }

        // ── 1. Por folio ─────────────────────────────────────────────────
        $byFolio = [];
        $withoutFolio = [];
        foreach ($pending as $m) {
            if (! empty($m['folio_normalized'])) {
                $byFolio[$m['folio_normalized']][] = $m;
            } else {
                $withoutFolio[] = $m;
            }
        }
        ksort($byFolio);

        foreach ($byFolio as $folio => $group) {
            $candidates = $erpByFolio[$folio] ?? [];
            if ($candidates === []) {
                foreach ($group as $m) {
                    $results[$m['fingerprint']] = isset($foliosOutside[$folio])
                        ? [
                            'status' => self::FOLIO_OTRO_PERIODO,
                            'erp_other_periods' => $foliosOutside[$folio],
                            'message' => 'El folio existe en el ERP de otro periodo ('.implode(', ', array_unique(array_column($foliosOutside[$folio], 'period_code'))).'). Revise antes de sumarlo para no duplicar.',
                        ]
                        : [
                            'status' => self::FOLIO_SIN_ERP,
                            'message' => 'El folio no existe en el ERP cargado.',
                        ];
                }

                continue;
            }

            $remaining = $group;

            // a) y b) 1:1 — primero fecha exacta, luego con tolerancia.
            foreach ([0, $this->toleranceDays] as $pass => $maxDelta) {
                if ($pass === 1 && $this->toleranceDays === 0) {
                    break;
                }
                $next = [];
                foreach ($remaining as $m) {
                    $match = null;
                    foreach ($candidates as $fp) {
                        if (isset($consumedErp[$fp])) {
                            continue;
                        }
                        $erp = $erpByFp[$fp];
                        $delta = $this->deltaDays($m['movement_date'], $erp['date']);
                        if (Money::equalsCents($m['cargo'], $erp['amount']) && $delta !== null && $delta <= $maxDelta) {
                            $match = [$fp, $delta];
                            break;
                        }
                    }
                    if ($match === null) {
                        $next[] = $m;

                        continue;
                    }
                    [$fp, $delta] = $match;
                    $consumedErp[$fp] = [$m['fingerprint']];
                    $results[$m['fingerprint']] = [
                        'status' => $delta === 0 ? self::EXACTO : self::TOLERANCIA,
                        'erp' => [$this->erpSummary($erpByFp[$fp])],
                        'delta_days' => $delta,
                        'message' => $delta === 0
                            ? 'Mismo folio, importe y fecha que el ERP. Se cuenta una sola vez (desde el ERP).'
                            : "Mismo folio e importe; la fecha difiere {$delta} día(s) (tolerancia configurada: {$this->toleranceDays}). Se cuenta una sola vez (desde el ERP).",
                    ];
                }
                $remaining = $next;
            }

            // c) 1:N — suma exacta de varios pagos del mismo folio.
            foreach ($candidates as $fp) {
                if (isset($consumedErp[$fp]) || count($remaining) < 2) {
                    continue;
                }
                $erp = $erpByFp[$fp];
                $window = $this->window($erp);
                $eligible = array_values(array_filter($remaining, fn ($m) => $window !== null && $m['movement_date'] >= $window[0] && $m['movement_date'] <= $window[1]));
                if (count($eligible) < 2 || count($eligible) > $this->maxGroupSize) {
                    continue;
                }
                $subsets = $this->exactSubsets($eligible, Money::cents($erp['amount']));
                if (count($subsets) === 1) {
                    $members = $subsets[0];
                    $memberFps = array_column($members, 'fingerprint');
                    $consumedErp[$fp] = $memberFps;
                    $parts = array_map(fn ($m) => Money::str2($m['cargo']), $members);
                    foreach ($members as $m) {
                        $results[$m['fingerprint']] = [
                            'status' => self::AGREGADO,
                            'erp' => [$this->erpSummary($erp)],
                            'group' => $memberFps,
                            'message' => 'Conciliación 1:N: '.implode(' + ', array_map(fn ($p) => '$'.number_format((float) $p, 2), $parts)).' = $'.number_format((float) $erp['amount'], 2).' (total ERP del folio).',
                        ];
                    }
                    $remaining = array_values(array_filter($remaining, fn ($m) => ! in_array($m['fingerprint'], $memberFps, true)));
                } elseif (count($subsets) > 1) {
                    foreach ($eligible as $m) {
                        $results[$m['fingerprint']] ??= [
                            'status' => self::FOLIO_AMBIGUO,
                            'erp' => [$this->erpSummary($erp)],
                            'message' => 'Hay '.count($subsets).' combinaciones de pagos que suman el total ERP; no se elige una en automático.',
                        ];
                    }
                    $remaining = array_values(array_filter($remaining, fn ($m) => ! isset($results[$m['fingerprint']])));
                }
            }

            // d) Lo que no concilió: explicar por qué.
            foreach ($remaining as $m) {
                $sameAmount = array_values(array_filter($candidates, fn ($fp) => Money::equalsCents($m['cargo'], $erpByFp[$fp]['amount'])));
                $erpList = array_map(fn ($fp) => $this->erpSummary($erpByFp[$fp]), $candidates);
                $freeSameAmount = array_values(array_filter($sameAmount, fn ($fp) => ! isset($consumedErp[$fp])));
                if ($freeSameAmount !== []) {
                    $erp = $erpByFp[$freeSameAmount[0]];
                    $delta = $this->deltaDays($m['movement_date'], $erp['date']);
                    $results[$m['fingerprint']] = [
                        'status' => self::FOLIO_FECHA_DIFERENTE,
                        'erp' => $erpList,
                        'delta_days' => $delta,
                        'message' => 'Mismo folio e importe, pero la fecha difiere '.($delta ?? '¿?')." día(s) (tolerancia: {$this->toleranceDays}). Requiere revisión.",
                    ];
                } elseif ($sameAmount !== []) {
                    $results[$m['fingerprint']] = [
                        'status' => self::FOLIO_YA_CONCILIADO,
                        'erp' => $erpList,
                        'message' => 'El registro ERP de este folio ya se concilió con otro pago del mismo importe. ¿Pago duplicado? Requiere revisión.',
                    ];
                } else {
                    $results[$m['fingerprint']] = [
                        'status' => self::FOLIO_MONTO_DIFERENTE,
                        'erp' => $erpList,
                        'message' => 'Mismo folio, monto diferente: banco $'.number_format((float) $m['cargo'], 2).' vs ERP '
                            .implode(', ', array_map(fn ($e) => '$'.number_format((float) $e['amount'], 2), $erpList)).'. Requiere revisión.',
                    ];
                }
            }
        }

        // ── 2. Sin folio: huella estricta, nunca descarte automático ─────
        foreach ($withoutFolio as $m) {
            $cands = [];
            foreach ($erpByFp as $fp => $erp) {
                if (isset($consumedErp[$fp]) || ! Money::equalsCents($m['cargo'], $erp['amount'])) {
                    continue;
                }
                $delta = $this->deltaDays($m['movement_date'], $erp['date']);
                if ($delta === null || $delta > $this->toleranceDays || ! $this->branchCompatible($m, $erp)) {
                    continue;
                }
                $cands[] = $this->erpSummary($erp) + ['delta_days' => $delta];
            }
            $results[$m['fingerprint']] = $cands !== []
                ? [
                    'status' => self::POSIBLE_DUPLICADO_ERP,
                    'erp' => $cands,
                    'message' => 'Sin folio, pero coincide en importe, fecha y sucursal con '.count($cands).' registro(s) del ERP. Requiere revisión (no se descarta en automático).',
                ]
                : ['status' => self::SIN_FOLIO, 'message' => 'Sin folio y sin coincidencia en el ERP.'];
        }

        $links = [];
        foreach ($consumedErp as $fp => $movementFps) {
            $links[$fp] = $movementFps;
        }

        return ['results' => $results, 'erp_links' => $links];
    }

    private function erpSummary(array $erp): array
    {
        return [
            'fingerprint' => $erp['fingerprint'],
            'id' => $erp['id'],
            'folio' => $erp['folio'],
            'amount' => $erp['amount'],
            'date' => $erp['date'],
            'date_source' => $erp['date_source'],
            'branch_name' => $erp['branch_name'],
            'universe' => $erp['universe'],
            'concept' => $erp['concept'],
            'category' => $erp['category'],
        ];
    }

    private function deltaDays(?string $a, ?string $b): ?int
    {
        if (! $a || ! $b) {
            return null;
        }
        $da = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($a, 0, 10));
        $db = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($b, 0, 10));
        if (! $da || ! $db) {
            return null;
        }

        return (int) abs($da->diff($db)->days);
    }

    /** Ventana de fechas válida para los pagos 1:N de un registro ERP. */
    private function window(array $erp): ?array
    {
        $end = $erp['date'];
        if (! $end) {
            return null;
        }
        $startBase = $erp['fecha_captura'] && $erp['fecha_captura'] < $end ? $erp['fecha_captura'] : $end;
        $start = (new \DateTimeImmutable($startBase))->modify("-{$this->toleranceDays} days")->format('Y-m-d');
        $endD = (new \DateTimeImmutable($end))->modify("+{$this->toleranceDays} days")->format('Y-m-d');

        return [$start, $endD];
    }

    /**
     * Todas las combinaciones (tamaño ≥ 2) cuya suma en centavos es exactamente $target.
     * Se detiene al encontrar 2 (basta para declarar ambigüedad).
     */
    private function exactSubsets(array $rows, int $target): array
    {
        $found = [];
        $n = count($rows);
        $cents = array_map(fn ($m) => Money::cents($m['cargo']), $rows);
        $walk = function (int $start, int $sum, array $picked) use (&$walk, &$found, $n, $cents, $rows, $target) {
            if (count($found) >= 2) {
                return;
            }
            if ($sum === $target && count($picked) >= 2) {
                $found[] = array_map(fn ($i) => $rows[$i], $picked);

                return;
            }
            if ($sum >= $target) {
                return;
            }
            for ($i = $start; $i < $n; $i++) {
                $walk($i + 1, $sum + $cents[$i], [...$picked, $i]);
            }
        };
        $walk(0, 0, []);

        return $found;
    }

    private function branchCompatible(array $m, array $erp): bool
    {
        $kind = $m['branch_kind'] ?? 'vacia';
        if (in_array($kind, ['vacia', 'desconocida', 'agrupado'], true)) {
            return true; // sin sucursal confiable: no se usa para descartar candidatos
        }
        if ($kind === 'corporativo') {
            return $erp['universe'] === 'corporativo';
        }

        return $erp['universe'] === 'radiografia'
            && mb_strtoupper((string) $erp['branch_name']) === mb_strtoupper((string) $m['branch_name']);
    }
}
