<?php

namespace App\Services\EstadoResultados\Reconciliation;

use App\Services\EstadoResultados\Support\Money;
use Brick\Math\BigDecimal;

/**
 * Conciliación determinista de CARGOS bancarios complementarios contra el ERP.
 *
 * Es una función pura (sin BD): mismos datos de entrada ⇒ mismo resultado.
 * Nunca descarta un movimiento sólo por compartir folio. Sólo concilia con
 * EVIDENCIA VERIFICABLE: mismo folio normalizado + sumas exactas al centavo +
 * fechas dentro de la ventana configurada.
 *
 * Orden de evaluación por movimiento (cargos dentro del periodo):
 *   0. UUID: mismo CFDI + mismo importe + misma fecha que un cargo anterior → duplicado_uuid.
 *   1. Folio (REQ normalizado) → candidatos ERP con ese folio:
 *      a) 1:1 exacto: mismo importe en centavos y misma fecha → conciliado_erp_exacto.
 *      b) 1:1 con tolerancia explícita (config) → conciliado_erp_tolerancia (se registra Δ días).
 *      c) 1:N: varios pagos del mismo folio cuya suma EXACTA = total ERP y cuyas fechas
 *         caen en la ventana [captura − tol, pago + tol] → conciliado_erp_agregado.
 *      d) N:1: un pago cuya suma EXACTA = varias requisiciones ERP del folio → conciliado_erp_n1.
 *      e) N:M: TODOS los pagos y TODAS las requisiciones libres del folio suman lo mismo al
 *         centavo (dentro de ventana) → conciliado_erp_nm (asignación determinista por fecha).
 *         En c), d): si más de una combinación cuadra → folio_ambiguo (revisión, no se consume).
 *      f) Parcial (una sola requisición libre del folio, pagos dentro de ventana):
 *         - pagos < ERP y el ERP declara pago parcial (estatus configurado) → el pago se
 *           aplica completo y el ERP conserva SALDO PENDIENTE de pago;
 *         - un pago > ERP → se aplica sólo el importe del ERP; el EXCEDENTE bancario queda
 *           pendiente de revisión (no se incluye ni se excluye en automático).
 *      g) Sin conciliación: folio_fecha_diferente | folio_ya_conciliado | folio_monto_diferente.
 *      h) Folio inexistente en el ERP del periodo: folio_otro_periodo (si existe en otro) o folio_sin_erp.
 *   2. Sin folio → huella estricta (importe exacto + fecha ± tol + sucursal compatible):
 *      con candidatos → posible_duplicado_erp (revisión; NUNCA se descarta en automático);
 *      sin candidatos → sin_folio (pasa a las reglas de clasificación).
 *
 * Además de los estados, devuelve las APLICACIONES (vínculos movimiento ↔ ERP con importe
 * aplicado) y el saldo aplicado/pendiente de cada lado.
 */
class EstadoResultadosExpenseReconciler
{
    public const EXACTO = 'conciliado_erp_exacto';

    public const TOLERANCIA = 'conciliado_erp_tolerancia';

    public const AGREGADO = 'conciliado_erp_agregado';

    /** N:1 — un pago bancario liquida varias requisiciones ERP del mismo folio (suma exacta). */
    public const AGREGADO_ERP = 'conciliado_erp_n1';

    /** N:M — varios pagos y varias requisiciones del mismo folio cuyas sumas coinciden al centavo. */
    public const MULTIPLE = 'conciliado_erp_nm';

    /** Aplicación parcial: sólo el importe efectivamente conciliado se neutraliza; el resto queda visible. */
    public const PARCIAL = 'conciliado_erp_parcial';

    public const FOLIO_MONTO_DIFERENTE = 'folio_monto_diferente';

    public const FOLIO_FECHA_DIFERENTE = 'folio_fecha_diferente';

    public const FOLIO_YA_CONCILIADO = 'folio_ya_conciliado';

    public const FOLIO_AMBIGUO = 'folio_ambiguo';

    public const FOLIO_OTRO_PERIODO = 'folio_otro_periodo';

    public const FOLIO_SIN_ERP = 'folio_sin_erp';

    public const POSIBLE_DUPLICADO_ERP = 'posible_duplicado_erp';

    public const SIN_FOLIO = 'sin_folio';

    public const DUPLICADO_UUID = 'duplicado_uuid';

    public const CONCILIADOS = [self::EXACTO, self::TOLERANCIA, self::AGREGADO, self::AGREGADO_ERP, self::MULTIPLE, self::PARCIAL];

    /** Tipo de emparejamiento por estado conciliado. */
    public const MATCH_TYPES = [
        self::EXACTO => '1:1', self::TOLERANCIA => '1:1', self::AGREGADO => '1:N',
        self::AGREGADO_ERP => 'N:1', self::MULTIPLE => 'N:M', self::PARCIAL => 'parcial',
    ];

    public const REVISION = [
        self::FOLIO_MONTO_DIFERENTE, self::FOLIO_FECHA_DIFERENTE, self::FOLIO_YA_CONCILIADO,
        self::FOLIO_AMBIGUO, self::FOLIO_OTRO_PERIODO, self::POSIBLE_DUPLICADO_ERP,
    ];

    public const LABELS = [
        self::EXACTO => 'Conciliado con ERP (exacto)',
        self::TOLERANCIA => 'Conciliado con ERP (fecha con tolerancia)',
        self::AGREGADO => 'Conciliación 1:N (varios pagos)',
        self::AGREGADO_ERP => 'Conciliación N:1 (un pago, varias requisiciones)',
        self::MULTIPLE => 'Conciliación N:M (varios pagos y requisiciones)',
        self::PARCIAL => 'Conciliación parcial (sólo el importe aplicado)',
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
        'reverso' => 'Reverso / cancelación (cargo y abono se anulan)',
    ];

    /** @param list<string> $partialStatuses estatus ERP (mayúsculas) que declaran un pago parcial */
    public function __construct(
        private readonly int $toleranceDays = 3,
        private readonly int $maxGroupSize = 8,
        private readonly array $partialStatuses = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            max(0, (int) config('estado_resultados.conciliacion.tolerancia_dias', 3)),
            max(2, (int) config('estado_resultados.conciliacion.max_pagos_agregados', 8)),
            array_values(array_map(fn ($v) => mb_strtoupper(trim((string) $v)), (array) config('estado_resultados.conciliacion.estatus_pago_parcial', []))),
        );
    }

    public function toleranceDays(): int
    {
        return $this->toleranceDays;
    }

    public function maxGroupSize(): int
    {
        return $this->maxGroupSize;
    }

    /**
     * @param  list<array{fingerprint:string, folio_normalized:?string, cargo:string, movement_date:string, branch_name:?string, branch_kind:string, uuid:?string, source_row:int, account_slot:string}>  $movements  cargos dentro del periodo
     * @param  list<array<string,mixed>>  $erpRows  ErpExpenseRepository::forDataIds()
     * @param  array<string, list<array<string,mixed>>>  $foliosOutside  ErpExpenseRepository::foliosOutside()
     * @return array{results: array<string, array<string,mixed>>, erp_links: array<string, list<string>>, allocations: list<array<string,mixed>>, erp_balances: array<string, array{amount:string, applied:string, pending:string}>}
     */
    public function reconcile(array $movements, array $erpRows, array $foliosOutside = []): array
    {
        usort($movements, fn ($a, $b) => [$a['movement_date'], $a['account_slot'], $a['source_row'], $a['fingerprint']] <=> [$b['movement_date'], $b['account_slot'], $b['source_row'], $b['fingerprint']]);

        $erpByFp = [];
        $erpByFolio = [];
        foreach ($erpRows as $erp) {
            $erpByFp[$erp['fingerprint']] = $erp;
            if ($erp['folio_normalized'] !== null) {
                $erpByFolio[$erp['folio_normalized']][] = $erp['fingerprint'];
            }
        }
        foreach ($erpByFolio as &$fps) {
            usort($fps, fn ($a, $b) => [$erpByFp[$a]['date'] ?? '', Money::cents($erpByFp[$a]['amount']), $a] <=> [$erpByFp[$b]['date'] ?? '', Money::cents($erpByFp[$b]['amount']), $b]);
        }
        unset($fps);

        $results = [];
        $consumedErp = [];   // erp fingerprint => list<movement fingerprint>
        $allocations = [];   // vínculos con importe aplicado
        $erpApplied = [];    // erp fingerprint => centavos aplicados
        $pending = [];

        $link = function (string $mfp, string $efp, int $cents, string $type, string $group, ?int $delta) use (&$allocations, &$erpApplied) {
            $allocations[] = ['movement' => $mfp, 'erp' => $efp, 'applied' => self::fromCents($cents), 'match_type' => $type, 'group_key' => $group, 'delta_days' => $delta];
            $erpApplied[$efp] = ($erpApplied[$efp] ?? 0) + $cents;
        };

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
        ksort($byFolio, SORT_STRING);

        foreach ($byFolio as $folio => $folioRows) {
            $folio = (string) $folio;
            $candidates = $erpByFolio[$folio] ?? [];
            if ($candidates === []) {
                foreach ($folioRows as $m) {
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

            $remaining = $folioRows;
            $free = fn () => array_values(array_filter($candidates, fn ($fp) => ! isset($consumedErp[$fp])));

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
                    $cents = Money::cents($m['cargo']);
                    $gk = $this->groupKey($folio, [$m['fingerprint']], [$fp]);
                    $link($m['fingerprint'], $fp, $cents, '1:1', $gk, $delta);
                    $results[$m['fingerprint']] = [
                        'status' => $delta === 0 ? self::EXACTO : self::TOLERANCIA,
                        'erp' => [$this->erpSummary($erpByFp[$fp])],
                        'delta_days' => $delta,
                        'match_type' => '1:1',
                        'group_key' => $gk,
                        'applied' => self::fromCents($cents),
                        'pending' => '0.00',
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
                $eligible = array_values(array_filter($remaining, fn ($m) => $this->inWindow($m['movement_date'], $window)));
                if (count($eligible) < 2 || count($eligible) > $this->maxGroupSize) {
                    continue;
                }
                $subsets = $this->exactSubsets($eligible, Money::cents($erp['amount']), 'cargo');
                if (count($subsets) === 1) {
                    $members = $subsets[0];
                    $memberFps = array_column($members, 'fingerprint');
                    $consumedErp[$fp] = $memberFps;
                    $gk = $this->groupKey($folio, $memberFps, [$fp]);
                    $parts = array_map(fn ($m) => Money::str2($m['cargo']), $members);
                    foreach ($members as $m) {
                        $cents = Money::cents($m['cargo']);
                        $link($m['fingerprint'], $fp, $cents, '1:N', $gk, $this->deltaDays($m['movement_date'], $erp['date']));
                        $results[$m['fingerprint']] = [
                            'status' => self::AGREGADO,
                            'erp' => [$this->erpSummary($erp)],
                            'group' => $memberFps,
                            'match_type' => '1:N',
                            'group_key' => $gk,
                            'applied' => self::fromCents($cents),
                            'pending' => '0.00',
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

            // d) N:1 — un pago = suma exacta de varias requisiciones del folio.
            $next = [];
            foreach ($remaining as $m) {
                $inWindow = array_values(array_filter($free(), fn ($fp) => $this->inWindow($m['movement_date'], $this->window($erpByFp[$fp]))));
                if (count($inWindow) < 2 || count($inWindow) > $this->maxGroupSize) {
                    $next[] = $m;

                    continue;
                }
                $rows = array_map(fn ($fp) => ['fingerprint' => $fp, 'amount' => $erpByFp[$fp]['amount']], $inWindow);
                $subsets = $this->exactSubsets($rows, Money::cents($m['cargo']), 'amount');
                if ($subsets === []) {
                    $next[] = $m;

                    continue;
                }
                if (count($subsets) > 1) {
                    $results[$m['fingerprint']] = [
                        'status' => self::FOLIO_AMBIGUO,
                        'erp' => array_map(fn ($fp) => $this->erpSummary($erpByFp[$fp]), $inWindow),
                        'message' => 'Hay '.count($subsets).' combinaciones de requisiciones ERP que suman este pago; no se elige una en automático.',
                    ];

                    continue;
                }
                $erpFps = array_column($subsets[0], 'fingerprint');
                $gk = $this->groupKey($folio, [$m['fingerprint']], $erpFps);
                foreach ($erpFps as $fp) {
                    $consumedErp[$fp] = [$m['fingerprint']];
                    $link($m['fingerprint'], $fp, Money::cents($erpByFp[$fp]['amount']), 'N:1', $gk, $this->deltaDays($m['movement_date'], $erpByFp[$fp]['date']));
                }
                $results[$m['fingerprint']] = [
                    'status' => self::AGREGADO_ERP,
                    'erp' => array_map(fn ($fp) => $this->erpSummary($erpByFp[$fp]), $erpFps),
                    'match_type' => 'N:1',
                    'group_key' => $gk,
                    'applied' => Money::str2($m['cargo']),
                    'pending' => '0.00',
                    'message' => 'Conciliación N:1: un pago de $'.number_format((float) $m['cargo'], 2).' = '
                        .implode(' + ', array_map(fn ($fp) => '$'.number_format((float) $erpByFp[$fp]['amount'], 2), $erpFps)).' ('.count($erpFps).' requisiciones ERP del folio).',
                ];
            }
            $remaining = $next;

            // e) N:M — todos los pagos y todas las requisiciones libres del folio cuadran al centavo.
            $freeNow = $free();
            if (count($remaining) >= 2 && count($freeNow) >= 2 && count($remaining) <= $this->maxGroupSize && count($freeNow) <= $this->maxGroupSize) {
                $windows = array_map(fn ($fp) => $this->window($erpByFp[$fp]), $freeNow);
                $allInWindow = array_reduce($remaining, fn ($ok, $m) => $ok && array_filter($windows, fn ($w) => $this->inWindow($m['movement_date'], $w)) !== [], true);
                $bankCents = array_sum(array_map(fn ($m) => Money::cents($m['cargo']), $remaining));
                $erpCents = array_sum(array_map(fn ($fp) => Money::cents($erpByFp[$fp]['amount']), $freeNow));
                if ($allInWindow && $bankCents === $erpCents) {
                    $bankFps = array_column($remaining, 'fingerprint');
                    $gk = $this->groupKey($folio, $bankFps, $freeNow);
                    $erpLeft = array_map(fn ($fp) => Money::cents($erpByFp[$fp]['amount']), $freeNow);
                    $j = 0;
                    $perBank = [];
                    foreach ($remaining as $m) {
                        $left = Money::cents($m['cargo']);
                        while ($left > 0 && $j < count($freeNow)) {
                            $take = min($left, $erpLeft[$j]);
                            if ($take > 0) {
                                $fp = $freeNow[$j];
                                $link($m['fingerprint'], $fp, $take, 'N:M', $gk, $this->deltaDays($m['movement_date'], $erpByFp[$fp]['date']));
                                $perBank[$m['fingerprint']][] = $fp;
                                $consumedErp[$fp] = array_values(array_unique([...($consumedErp[$fp] ?? []), $m['fingerprint']]));
                            }
                            $left -= $take;
                            $erpLeft[$j] -= $take;
                            if ($erpLeft[$j] === 0) {
                                $j++;
                            }
                        }
                    }
                    foreach ($remaining as $m) {
                        $results[$m['fingerprint']] = [
                            'status' => self::MULTIPLE,
                            'erp' => array_map(fn ($fp) => $this->erpSummary($erpByFp[$fp]), $perBank[$m['fingerprint']] ?? []),
                            'group' => $bankFps,
                            'match_type' => 'N:M',
                            'group_key' => $gk,
                            'applied' => Money::str2($m['cargo']),
                            'pending' => '0.00',
                            'message' => 'Conciliación N:M: '.count($bankFps).' pagos por $'.number_format($bankCents / 100, 2).' = '.count($freeNow).' requisiciones ERP del folio por $'.number_format($erpCents / 100, 2).' (suma exacta al centavo).',
                        ];
                    }
                    $remaining = [];
                }
            }

            // f) Parcial — una sola requisición libre del folio y pagos dentro de su ventana.
            $freeNow = $free();
            if ($remaining !== [] && count($freeNow) === 1) {
                $fp = $freeNow[0];
                $erp = $erpByFp[$fp];
                $window = $this->window($erp);
                $eligible = array_values(array_filter($remaining, fn ($m) => $this->inWindow($m['movement_date'], $window)));
                $bankCents = array_sum(array_map(fn ($m) => Money::cents($m['cargo']), $eligible));
                $erpCents = Money::cents($erp['amount']);
                $declaredPartial = in_array(mb_strtoupper(trim((string) ($erp['status_raw'] ?? ''))), $this->partialStatuses, true);

                if ($eligible !== [] && $bankCents < $erpCents && $declaredPartial && count($eligible) <= $this->maxGroupSize) {
                    $memberFps = array_column($eligible, 'fingerprint');
                    $gk = $this->groupKey($folio, $memberFps, [$fp]);
                    $consumedErp[$fp] = $memberFps;
                    foreach ($eligible as $m) {
                        $cents = Money::cents($m['cargo']);
                        $link($m['fingerprint'], $fp, $cents, 'parcial', $gk, $this->deltaDays($m['movement_date'], $erp['date']));
                        $results[$m['fingerprint']] = [
                            'status' => self::PARCIAL,
                            'erp' => [$this->erpSummary($erp)],
                            'group' => $memberFps,
                            'match_type' => 'parcial',
                            'group_key' => $gk,
                            'applied' => self::fromCents($cents),
                            'pending' => '0.00',
                            'message' => 'Pago parcial de la requisición (estatus ERP "'.$erp['status_raw'].'"): se aplica $'.number_format($bankCents / 100, 2).' de $'.number_format($erpCents / 100, 2)
                                .'. El saldo ERP de $'.number_format(($erpCents - $bankCents) / 100, 2).' queda pendiente de pago (visible, no se compensa).',
                        ];
                    }
                    $remaining = array_values(array_filter($remaining, fn ($m) => ! in_array($m['fingerprint'], $memberFps, true)));
                } elseif (count($eligible) === 1 && $bankCents > $erpCents) {
                    $m = $eligible[0];
                    $gk = $this->groupKey($folio, [$m['fingerprint']], [$fp]);
                    $consumedErp[$fp] = [$m['fingerprint']];
                    $link($m['fingerprint'], $fp, $erpCents, 'parcial', $gk, $this->deltaDays($m['movement_date'], $erp['date']));
                    $results[$m['fingerprint']] = [
                        'status' => self::PARCIAL,
                        'erp' => [$this->erpSummary($erp)],
                        'match_type' => 'parcial',
                        'group_key' => $gk,
                        'applied' => self::fromCents($erpCents),
                        'pending' => self::fromCents($bankCents - $erpCents),
                        'message' => 'El pago ($'.number_format($bankCents / 100, 2).') cubre la requisición ERP del folio ($'.number_format($erpCents / 100, 2).'): sólo ese importe se cuenta desde el ERP. '
                            .'El excedente de $'.number_format(($bankCents - $erpCents) / 100, 2).' queda pendiente de revisión (no se suma ni se excluye en automático).',
                    ];
                    $remaining = array_values(array_filter($remaining, fn ($r) => $r['fingerprint'] !== $m['fingerprint']));
                }
            }

            // g) Lo que no concilió: explicar por qué.
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
        ksort($links, SORT_STRING);

        usort($allocations, fn ($a, $b) => [$a['group_key'], $a['movement'], $a['erp']] <=> [$b['group_key'], $b['movement'], $b['erp']]);

        $balances = [];
        foreach ($erpApplied as $fp => $cents) {
            $total = Money::cents($erpByFp[$fp]['amount']);
            $balances[$fp] = ['amount' => self::fromCents($total), 'applied' => self::fromCents($cents), 'pending' => self::fromCents($total - $cents)];
        }
        ksort($balances, SORT_STRING);

        return ['results' => $results, 'erp_links' => $links, 'allocations' => $allocations, 'erp_balances' => $balances];
    }

    public static function fromCents(int $cents): string
    {
        return (string) BigDecimal::ofUnscaledValue($cents, 2);
    }

    private function groupKey(string $folio, array $movementFps, array $erpFps): string
    {
        sort($movementFps, SORT_STRING);
        sort($erpFps, SORT_STRING);

        return 'G-'.substr(sha1($folio.'|'.implode(',', $movementFps).'|'.implode(',', $erpFps)), 0, 12);
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
            'fecha_pago' => $erp['fecha_pago'] ?? null,
            'fecha_captura' => $erp['fecha_captura'] ?? null,
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

    /** Ventana de fechas válida para los pagos de un registro ERP. */
    private function window(array $erp): ?array
    {
        $end = $erp['date'];
        if (! $end) {
            return null;
        }
        $startBase = ($erp['fecha_captura'] ?? null) && $erp['fecha_captura'] < $end ? $erp['fecha_captura'] : $end;
        $start = (new \DateTimeImmutable($startBase))->modify("-{$this->toleranceDays} days")->format('Y-m-d');
        $endD = (new \DateTimeImmutable($end))->modify("+{$this->toleranceDays} days")->format('Y-m-d');

        return [$start, $endD];
    }

    private function inWindow(string $date, ?array $window): bool
    {
        return $window !== null && $date >= $window[0] && $date <= $window[1];
    }

    /**
     * Todas las combinaciones (tamaño ≥ 2) cuya suma en centavos es exactamente $target.
     * Se detiene al encontrar 2 (basta para declarar ambigüedad).
     */
    private function exactSubsets(array $rows, int $target, string $field): array
    {
        $found = [];
        $n = count($rows);
        $cents = array_map(fn ($m) => Money::cents($m[$field]), $rows);
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
