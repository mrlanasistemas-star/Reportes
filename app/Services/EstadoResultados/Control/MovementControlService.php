<?php

namespace App\Services\EstadoResultados\Control;

use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Import\BankStatementParser;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Import\MovementPeriodAllocator as A;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Reporting\EstadoResultadosRedactor;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment as T;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * CONTROL TOTAL de los estados de cuenta de un periodo (20-nov-2026).
 *
 * Desde el reparto por fecha (MovementPeriodAllocator) un archivo subido en un periodo puede
 * aportar movimientos a otro. La conciliación archivo ↔ importado se hace POR MOVIMIENTO
 * REPARTIDO, nunca por el periodo donde se subió la carga:
 *
 *   Lado archivo del periodo P = Σ por cada carga que aporta a P de
 *       (total leído del archivo − lo que el reparto guardó en OTROS periodos)
 *   Importado en P            = Σ movimientos guardados con period_id = P
 *   Diferencia                = Σ (total leído − total guardado) = filas leídas NO guardadas
 *
 * Así cuadra a $0.00 cuando no se perdió nada y una fila no guardada SIGUE apareciendo
 * (nunca se compensa ni se esconde). Por cada archivo se presenta su partición completa:
 * entra aquí + va a otros periodos + repetido + sin periodo + periodo cerrado/existente + no guardado.
 *
 * Además clasifica el estado de control de cada item del dataset (completado, pendiente,
 * repetido, fuera de este periodo) y las señales de «posible concepto mal asignado».
 */
class MovementControlService
{
    /** Estados de control (filtros de la pestaña Validación). */
    public const COMPLETADO = 'completado';

    public const PENDIENTE = 'pendiente';

    public const DUPLICADO = 'duplicado';

    public const FUERA = 'fuera';

    public const NO_GUARDADO = 'no_guardado';

    public const STATUS_LABELS = [
        self::COMPLETADO => 'Guardado y completado',
        self::PENDIENTE => 'Pendiente de clasificar',
        self::DUPLICADO => 'Repetido / duplicado',
        self::FUERA => 'Fuera de este periodo',
        self::NO_GUARDADO => 'Leído del archivo y no guardado',
    ];

    public const PLACEMENT_LABELS = [
        A::PERIODO => 'Entra en el periodo de su fecha',
        A::DUPLICADO => 'Repetido: otro archivo ya trae el mismo movimiento',
        A::SIN_PERIODO => 'Sin periodo: su fecha no pertenece a ningún periodo mensual creado',
        A::PERIODO_CERRADO => 'Periodo cerrado: su fecha cae en un periodo aprobado',
        A::PERIODO_EXISTENTE => 'Periodo existente: su fecha cae en un periodo cargado antes del reparto por fecha',
        A::LEGADO => 'Carga anterior al reparto por fecha (se queda donde se subió)',
    ];

    /** Reglas que clasifican SÓLO por palabras del concepto (sin folio/ERP como evidencia). */
    private const KEYWORD_RULES = [
        'nomina_corporativa', 'excedente_sucursal', 'provision_sucursal', 'fondeo_servicio_deuda', 'descuento_recuperado',
        'poliza_puente', 'retenciones_terceros', 'servicio_deuda', 'nomina_sucursal_noi',
    ];

    private const MAX_REFS = 5000;

    public function __construct(private readonly ClassificationService $classifications) {}

    // ───────────────────────────────────────────────────────────────────
    // Partición por archivo (lado archivo del periodo)
    // ───────────────────────────────────────────────────────────────────

    /**
     * Cargas vigentes que aportan al periodo (subidas en él o con movimientos repartidos a él)
     * y la partición completa de cada una.
     *
     * @return array{uploads: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function fileControl(Period $period): array
    {
        $ids = $this->contributingUploadIds($period);
        if ($ids === []) {
            return ['uploads' => [], 'totals' => $this->emptyTotals()];
        }
        $uploads = EstadoResultadosUpload::query()->whereIn('id', $ids)->orderBy('id')->get();
        $hasPlacement = A::available();
        $rows = DB::table('estado_resultados_movements')->whereIn('upload_id', $ids)
            ->get(array_merge(['id', 'upload_id', 'period_id', 'in_period', 'cargo', 'abono', 'fingerprint'], $hasPlacement ? ['placement'] : []));

        $dupRows = $hasPlacement ? $rows->where('placement', A::DUPLICADO) : collect();
        $winners = $this->winnersFor($dupRows->pluck('id')->all());

        $periodIds = $rows->pluck('period_id')->merge($uploads->pluck('period_id'))->merge(array_column($winners, 'period_id'))->unique()->filter()->all();
        $labels = Period::query()->whereIn('id', $periodIds ?: [0])->get()->mapWithKeys(fn (Period $p) => [(int) $p->id => (string) $p->label])->all();

        $out = [];
        $byUpload = $rows->groupBy('upload_id');
        foreach ($uploads as $u) {
            $list = $byUpload->get($u->id, collect());
            $parts = [];
            $saved = ['count' => 0, 'cargos' => BigDecimal::zero(), 'abonos' => BigDecimal::zero()];
            $elsewhere = ['count' => 0, 'cargos' => BigDecimal::zero(), 'abonos' => BigDecimal::zero()];
            $here = ['count' => 0, 'cargos' => BigDecimal::zero(), 'abonos' => BigDecimal::zero()];
            foreach ($list as $r) {
                $pid = (int) $r->period_id;
                $in = (bool) $r->in_period;
                $placement = $hasPlacement ? (string) $r->placement : A::PERIODO;
                $isHere = $pid === (int) $period->id;
                $kind = $this->partKind($placement, $in, $isHere);
                $key = $kind.'|'.$pid;
                $parts[$key] ??= ['key' => $kind, 'period_id' => $pid, 'period_label' => $labels[$pid] ?? null, 'here' => $isHere,
                    'count' => 0, 'cargos' => BigDecimal::zero(), 'abonos' => BigDecimal::zero(), 'against' => []];
                $parts[$key]['count']++;
                $parts[$key]['cargos'] = $parts[$key]['cargos']->plus(Money::of($r->cargo));
                $parts[$key]['abonos'] = $parts[$key]['abonos']->plus(Money::of($r->abono));
                if ($placement === A::DUPLICADO && isset($winners[$r->id])) {
                    $w = $winners[$r->id];
                    $wk = $w['upload_id'];
                    $parts[$key]['against'][$wk] ??= ['upload_id' => $wk, 'file' => $w['file'], 'period_label' => $labels[$w['period_id']] ?? null, 'count' => 0, 'total' => BigDecimal::zero()];
                    $parts[$key]['against'][$wk]['count']++;
                    $parts[$key]['against'][$wk]['total'] = $parts[$key]['against'][$wk]['total']->plus(Money::of($r->cargo))->plus(Money::of($r->abono));
                }
                $add = function (array &$t) use ($r) {
                    $t['count']++;
                    $t['cargos'] = $t['cargos']->plus(Money::of($r->cargo));
                    $t['abonos'] = $t['abonos']->plus(Money::of($r->abono));
                };
                $add($saved);
                if ($isHere) {
                    $add($here);
                } else {
                    $add($elsewhere);
                }
            }

            $readC = Money::of($u->total_cargos);
            $readA = Money::of($u->total_abonos);
            $read = (int) $u->movements_count;
            $notSaved = ['count' => $read - $saved['count'], 'cargos' => $readC->minus($saved['cargos']), 'abonos' => $readA->minus($saved['abonos'])];
            $share = ['count' => $read - $elsewhere['count'], 'cargos' => $readC->minus($elsewhere['cargos']), 'abonos' => $readA->minus($elsewhere['abonos'])];
            $controlTotal = $u->control_total_cargos !== null && $u->control_total_abonos !== null
                ? Money::of($u->control_total_cargos)->plus(Money::of($u->control_total_abonos)) : null;
            $notSavedOk = $notSaved['count'] === 0 && Money::isZeroCents($notSaved['cargos']) && Money::isZeroCents($notSaved['abonos']);

            $partList = array_values(array_map(fn ($p) => [
                'key' => $p['key'], 'label' => $this->partLabel($p['key'], $p['period_label']),
                'period_id' => $p['period_id'], 'period_label' => $p['period_label'], 'here' => $p['here'],
                'count' => $p['count'], 'cargos' => Money::str2($p['cargos']), 'abonos' => Money::str2($p['abonos']),
                'total' => Money::str2($p['cargos']->plus($p['abonos'])),
                'against' => array_values(array_map(fn ($a) => ['upload_id' => $a['upload_id'], 'file' => $a['file'], 'period_label' => $a['period_label'], 'count' => $a['count'], 'total' => Money::str2($a['total'])], $p['against'])),
            ], $parts));
            usort($partList, fn ($a, $b) => [$a['here'] ? 0 : 1, $a['key'] === 'entra' ? 0 : 1, (string) $a['period_label']] <=> [$b['here'] ? 0 : 1, $b['key'] === 'entra' ? 0 : 1, (string) $b['period_label']]);
            if (! $notSavedOk) {
                $partList[] = ['key' => self::NO_GUARDADO, 'label' => 'Leído del archivo y NO guardado', 'period_id' => null, 'period_label' => null, 'here' => false,
                    'count' => $notSaved['count'], 'cargos' => Money::str2($notSaved['cargos']), 'abonos' => Money::str2($notSaved['abonos']),
                    'total' => Money::str2($notSaved['cargos']->plus($notSaved['abonos'])), 'against' => []];
            }

            $out[] = [
                // Compatibilidad con validation.integrity.uploads (antes sólo cargas del periodo):
                'upload_id' => (int) $u->id,
                'slot' => $u->account_slot, 'file' => $u->original_name, 'hash' => (string) $u->file_hash, 'control_ok' => $u->control_ok,
                'control_cargos' => $u->control_total_cargos !== null ? Money::str2($u->control_total_cargos) : null,
                'control_abonos' => $u->control_total_abonos !== null ? Money::str2($u->control_total_abonos) : null,
                'read_cargos' => Money::str2($readC), 'read_abonos' => Money::str2($readA),
                'movements' => $read,
                // Partición completa del archivo:
                'account_label' => $u->account_label,
                'file_part' => $u->getAttribute('file_part'),
                'upload_period' => ['id' => (int) $u->period_id, 'label' => $labels[(int) $u->period_id] ?? null],
                'own' => (int) $u->period_id === (int) $period->id,
                'read_total' => Money::str2($readC->plus($readA)),
                'control_total' => $controlTotal !== null ? Money::str2($controlTotal) : null,
                'control_difference' => $controlTotal !== null ? Money::str2($readC->plus($readA)->minus($controlTotal)) : null,
                'saved' => $this->triple($saved),
                'here' => $this->triple($here),
                'elsewhere' => $this->triple($elsewhere),
                'period_share' => $this->triple($share),
                'not_saved' => $this->triple($notSaved),
                'saved_ok' => $notSavedOk,
                'parts' => $partList,
            ];
        }

        $tot = fn (string $k, string $f) => Money::sum(array_map(fn ($u) => $u[$k][$f], $out));
        $notSavedUploads = array_values(array_filter($out, fn ($u) => ! $u['saved_ok']));

        return [
            'uploads' => $out,
            'totals' => [
                'files' => count($out),
                'own_files' => count(array_filter($out, fn ($u) => $u['own'])),
                'period_share' => ['count' => array_sum(array_map(fn ($u) => $u['period_share']['count'], $out)), 'cargos' => Money::str2($tot('period_share', 'cargos')), 'abonos' => Money::str2($tot('period_share', 'abonos')), 'total' => Money::str2($tot('period_share', 'total'))],
                'not_saved' => ['count' => array_sum(array_map(fn ($u) => $u['not_saved']['count'], $out)), 'total' => Money::str2($tot('not_saved', 'total')), 'files' => count($notSavedUploads)],
                'rule' => 'Lado archivo del periodo = Σ por archivo (total leído − lo que el reparto por fecha guardó en otros periodos). Si difiere de lo importado, hay filas leídas que no se guardaron.',
            ],
        ];
    }

    /** @return list<int> */
    public function contributingUploadIds(Period $period): array
    {
        $active = EstadoResultadosUpload::query()->active();
        $own = (clone $active)->where('period_id', $period->id)->pluck('id')->all();
        $foreign = DB::table('estado_resultados_movements')->where('period_id', $period->id)
            ->whereIn('upload_id', (clone $active)->select('id'))->distinct()->pluck('upload_id')->all();
        $ids = array_values(array_unique(array_map('intval', array_merge($own, $foreign))));
        sort($ids);

        return $ids;
    }

    /**
     * Para cada movimiento «duplicado», el movimiento vigente (ganador) con la misma huella.
     *
     * @param  list<int>  $movementIds
     * @return array<int, array{upload_id:int, file:?string, source_row:int, period_id:int, account_slot:?string, date:?string}>
     */
    public function winnersFor(array $movementIds): array
    {
        if ($movementIds === [] || ! A::available()) {
            return [];
        }
        // Sin índice por huella: se consulta por lotes de huellas (no un JOIN por cada copia).
        $dups = DB::table('estado_resultados_movements')->whereIn('id', $movementIds)->get(['id', 'fingerprint']);
        $winners = [];
        foreach (array_chunk($dups->pluck('fingerprint')->unique()->values()->all(), 500) as $fps) {
            $rows = DB::table('estado_resultados_movements as w')
                ->join('estado_resultados_uploads as wu', 'wu.id', '=', 'w.upload_id')
                ->where('wu.status', '!=', EstadoResultadosUpload::STATUS_REPLACED)
                ->whereIn('w.fingerprint', $fps)->where('w.in_period', true)->where('w.dedup_key', 0)
                ->orderBy('w.id')
                ->get(['w.id', 'w.fingerprint', 'w.upload_id', 'w.source_row', 'w.period_id', 'w.account_slot', 'w.movement_date', 'wu.original_name']);
            foreach ($rows as $r) {
                $winners[$r->fingerprint] ??= ['id' => (int) $r->id, 'upload_id' => (int) $r->upload_id, 'file' => $r->original_name, 'source_row' => (int) $r->source_row,
                    'period_id' => (int) $r->period_id, 'account_slot' => $r->account_slot, 'date' => substr((string) $r->movement_date, 0, 10)];
            }
        }
        $out = [];
        foreach ($dups as $d) {
            $w = $winners[$d->fingerprint] ?? null;
            if ($w !== null && $w['id'] !== (int) $d->id) {
                unset($w['id']);
                $out[(int) $d->id] = $w;
            }
        }

        return $out;
    }

    /** Etiqueta del periodo de cada id (para los datos del ganador de un duplicado). */
    public function periodLabels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : Period::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Period $p) => [(int) $p->id => (string) $p->label])->all();
    }

    // ───────────────────────────────────────────────────────────────────
    // Estado de control de cada item del dataset
    // ───────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $i  item interno del dataset (con impact/amounts)
     * @return array{status:string, label:string, sub:?string, explain:string, flags:list<array{key:string,label:string,severity:string}>}
     */
    public function itemControl(array $i): array
    {
        $placement = $i['placement'] ?? null;
        $flags = $this->flags($i);
        $groupLabel = fn () => match (true) {
            $i['treatment'] === T::CONCILIADO_ERP => 'Conciliado con ERP',
            T::affectsStatement($i['treatment']) => 'Reconocido en el ER',
            default => 'Excluido con justificación',
        };

        if ($placement === A::DUPLICADO || $i['reconciliation_status'] === R::DUPLICADO_UUID) {
            $w = $i['duplicate_of'] ?? null;
            $explain = $placement === A::DUPLICADO
                ? ($w ? 'Otro archivo ya trae este movimiento y ése es el que cuenta: '.($w['file'] ?? '—').', fila '.$w['source_row'].($w['period_label'] ? ' ('.$w['period_label'].')' : '').'.' : 'Otro archivo ya trae este movimiento: se cuenta una sola vez.')
                : 'Mismo UUID, importe y fecha que otro movimiento ya considerado: no se cuenta dos veces.';
            $status = self::DUPLICADO;
            $sub = $placement === A::DUPLICADO ? 'duplicado_archivo' : 'duplicado_uuid';
        } elseif (! ($i['in_period'] ?? true)) {
            $status = self::FUERA;
            $sub = $placement ?? 'fuera_periodo';
            $explain = $placement && isset(self::PLACEMENT_LABELS[$placement]) && $placement !== A::PERIODO
                ? self::PLACEMENT_LABELS[$placement].'. Se conserva aquí sin sumar.'
                : 'La fecha del movimiento no cae dentro del periodo: no suma aquí.';
        } elseif ($i['treatment'] === T::PENDIENTE) {
            $status = self::PENDIENTE;
            $sub = $i['rule_code'] ?? null;
            $explain = 'No suma ni se excluye hasta que alguien lo clasifique. '.($i['rule_reason'] ?? '');
        } else {
            $status = self::COMPLETADO;
            $sub = $i['treatment'] === T::CONCILIADO_ERP ? 'conciliado' : (T::affectsStatement($i['treatment']) ? 'reconocido' : 'excluido');
            $ov = $i['override'] ?? null;
            $explain = $groupLabel().' · '.T::label($i['treatment']).($i['impact']['line'] ?? null ? ' → '.$i['impact']['line'] : '').'. '
                .($ov ? 'Clasificación manual de '.($ov['user'] ?? '—').($ov['at'] ? ' el '.$ov['at'] : '').($ov['notes'] ? ': «'.$ov['notes'].'»' : '').'.'
                    : 'Regla '.($i['rule_code'] ?? '—').': '.($i['rule_reason'] ?? ''));
        }

        return ['status' => $status, 'label' => self::STATUS_LABELS[$status], 'sub' => $sub, 'explain' => trim($explain), 'flags' => $flags];
    }

    /** Señales de «posible concepto mal asignado» (no cambian ninguna cifra: sólo piden revisión). */
    private function flags(array $i): array
    {
        $flags = [];
        $ov = $i['override'] ?? null;
        $in = (bool) ($i['in_period'] ?? true);
        if (! $in || $i['treatment'] === T::PENDIENTE) {
            return [];
        }
        $amounts = $i['amounts'] ?? [];
        if ($i['source'] === 'banco' && $ov !== null && T::affectsStatement($i['treatment'])
            && ! Money::isZeroCents($amounts['conciliated'] ?? 0) && Money::isZeroCents($amounts['classifiable'] ?? 0)) {
            $flags[] = ['key' => 'conciliado_reclasificado', 'severity' => 'alta',
                'label' => 'Conciliado con ERP y reclasificado a mano para sumar: el ERP ya lo reconoce (no se suma otra vez). Revierte la clasificación o revisa la conciliación.'];
        }
        $suggested = $i['suggested_treatment'] ?? null;
        if ($suggested && $suggested !== $i['treatment'] && T::exists($suggested)) {
            $flags[] = ['key' => 'sugerencia_distinta', 'severity' => 'media',
                'label' => 'La sugerencia del sistema es «'.T::label($suggested).'» y está como «'.T::label($i['treatment']).'».'];
        }
        $auto = (string) ($i['auto_treatment'] ?? '');
        if ($ov !== null && $auto !== '' && $auto !== T::PENDIENTE && T::exists($auto) && T::group($auto) !== T::group($i['treatment'])) {
            $flags[] = ['key' => 'manual_contra_regla', 'severity' => 'media',
                'label' => 'La regla automática decía «'.T::label($auto).'» y se cambió a mano a «'.T::label($i['treatment']).'».'];
        }
        if ($ov === null && in_array($i['rule_code'] ?? null, self::KEYWORD_RULES, true)) {
            $flags[] = ['key' => 'regla_por_palabra', 'severity' => 'baja',
                'label' => 'Clasificado sólo por palabras del concepto (regla '.$i['rule_code'].'), sin folio ni ERP como evidencia: confirma que el concepto corresponde.'];
        }
        if (T::affectsStatement($i['treatment']) && in_array($i['branch_kind'] ?? null, ['desconocida', 'vacia'], true) && $i['source'] === 'banco') {
            $flags[] = ['key' => 'sin_sucursal', 'severity' => 'media',
                'label' => 'Suma al ER pero la sucursal del movimiento no se reconoce («'.($i['sucursal_raw'] ?? 'vacía').'»).'];
        }

        return $flags;
    }

    /**
     * Movimientos que explican una validación fallida. `files` = archivos con filas no guardadas.
     *
     * @return array{items: list<array{fingerprint:?string, amount:string}>, files: list<array<string,mixed>>, note: ?string}
     */
    public function itemsForCheck(string $key, array $items, array $scoped, array $fileControl, string $linkedApplied = '0.00'): array
    {
        $bank = array_filter($items, fn ($i) => $i['source'] === 'banco');
        $gross = fn ($i) => Money::str2(Money::of($i['cargo'])->plus(Money::of($i['abono'])));
        $list = [];
        $files = [];
        $note = null;
        switch ($key) {
            case 'particion_bancos':
            case 'import_matches_source':
                $files = array_values(array_map(fn ($u) => ['upload_id' => $u['upload_id'], 'file' => $u['file'], 'count' => $u['not_saved']['count'], 'amount' => $u['not_saved']['total']],
                    array_filter($fileControl['uploads'] ?? [], fn ($u) => ! $u['saved_ok'])));
                $note = $files === [] ? 'Ningún archivo tiene filas sin guardar: revisa el procesamiento (los movimientos del periodo no suman lo mismo por tratamiento).' : 'Filas leídas del archivo que no quedaron guardadas: ábrelas en «Ver movimientos».';
                break;
            case 'conciliados_reclasificados':
                $list = array_filter($bank, fn ($i) => in_array('conciliado_reclasificado', array_column($i['control']['flags'] ?? [], 'key'), true));
                break;
            case 'complementarios_incorporados':
                $list = array_filter($scoped, fn ($i) => $i['source'] === 'banco' && $i['impact']['included'] && ! Money::isZeroCents($i['amounts']['conciliated']));
                break;
            case 'huella_unica':
                $counts = array_count_values(array_column($items, 'fingerprint'));
                $list = array_filter($items, fn ($i) => ($counts[$i['fingerprint']] ?? 0) > 1);
                break;
            case 'particion_total':
                $list = array_filter($items, function ($i) {
                    $a = $i['amounts'];

                    return ! Money::isZeroCents(Money::of($a['gross'])->minus(Money::sum([$a['conciliated'], $a['recognized'], $a['excluded'], $a['pending'], $a['out_of_period']])));
                });
                break;
            case 'conciliados_sin_doble_gasto':
                $list = array_filter($bank, fn ($i) => ! Money::isZeroCents($i['amounts']['conciliated']) || in_array($i['reconciliation_status'], R::CONCILIADOS, true));
                $note = 'Σ aplicado en vínculos ERP: $'.number_format((float) $linkedApplied, 2).'. Compara cada movimiento conciliado con sus vínculos.';
                break;
            case 'exclusividad_categorias':
                $list = array_filter($scoped, fn ($i) => $i['impact']['included']);
                break;
            default:
                $note = 'Control de fórmula del Estado de Resultados: no proviene de movimientos individuales (revisa las líneas del estado).';
        }

        return ['items' => $this->refs($list, fn ($i) => $key === 'particion_total' || $key === 'huella_unica' ? $i['amounts']['gross'] : ($i['impact']['included'] ? $i['impact']['amount'] : $gross($i))), 'files' => $files, 'note' => $note];
    }

    /**
     * Referencias compactas a items (el front las resuelve contra dataset.movements; el redactor
     * anonimiza las de movimientos restringidos porque llevan `fingerprint`).
     *
     * @return list<array{fingerprint:?string, amount:string}>
     */
    public function refs(iterable $items, ?callable $amount = null): array
    {
        $out = [];
        foreach ($items as $i) {
            if (count($out) >= self::MAX_REFS) {
                break;
            }
            $out[] = ['fingerprint' => $i['fingerprint'], 'amount' => Money::str2($amount ? $amount($i) : Money::of($i['cargo'])->plus(Money::of($i['abono'])))];
        }

        return $out;
    }

    // ───────────────────────────────────────────────────────────────────
    // Filas que NO están en el dataset del periodo (endpoint control-movimientos)
    // ───────────────────────────────────────────────────────────────────

    /**
     * Filas de los archivos que aportan al periodo y que el reparto dejó en OTRO periodo
     * (van a otro periodo, repetidas allá, sin periodo, cerrado/existente).
     *
     * @return array{data: list<array<string,mixed>>, total:int, page:int, per_page:int, last_page:int}
     */
    public function outsideRows(Period $period, ?int $uploadId, ?string $placement, int $page, int $perPage, bool $canNomina, bool $canDg): array
    {
        $ids = $this->contributingUploadIds($period);
        if ($uploadId !== null) {
            $ids = in_array($uploadId, $ids, true) ? [$uploadId] : [];
        }
        $q = DB::table('estado_resultados_movements as m')
            ->join('estado_resultados_uploads as u', 'u.id', '=', 'm.upload_id')
            ->whereIn('m.upload_id', $ids ?: [0])
            ->where('m.period_id', '!=', $period->id);
        $hasPlacement = A::available();
        if ($placement !== null && $placement !== '') {
            if ($placement === 'otro_periodo') {
                $q->where('m.in_period', true);
            } elseif ($hasPlacement) {
                $q->where('m.in_period', false)->where('m.placement', $placement);
            }
        }
        $total = (clone $q)->count();
        $rows = $q->orderBy('m.movement_date')->orderBy('m.account_slot')->orderBy('m.source_row')
            ->forPage($page, $perPage)
            ->get(array_merge(['m.id', 'm.upload_id', 'm.period_id', 'm.in_period', 'm.fingerprint', 'm.account_slot', 'm.source_row', 'm.movement_date', 'm.bank_reference',
                'm.folio_raw', 'm.uuid', 'm.beneficiary', 'm.concept', 'm.observations', 'm.sucursal_raw', 'm.branch_name', 'm.cargo', 'm.abono',
                'm.auto_treatment', 'm.suggested_treatment', 'm.rule_code', 'm.reason', 'm.reconciliation_status', 'm.noi_employee_id', 'm.processed_at',
                'u.original_name', 'u.account_label', 'u.period_id as upload_period_id'], $hasPlacement ? ['m.placement'] : []));

        $winners = $this->winnersFor($rows->filter(fn ($r) => ($r->placement ?? null) === A::DUPLICADO)->pluck('id')->map(fn ($v) => (int) $v)->all());
        $labels = $this->periodLabels(array_merge($rows->pluck('period_id')->all(), $rows->pluck('upload_period_id')->all(), array_column($winners, 'period_id')));
        $overrides = [];
        foreach ($rows->pluck('period_id')->unique() as $pid) {
            $overrides[(int) $pid] = $this->classifications->effectiveOverrides((int) $pid);
        }

        $data = $rows->map(function ($r) use ($winners, $labels, $overrides, $canNomina, $canDg) {
            $pid = (int) $r->period_id;
            $in = (bool) $r->in_period;
            $place = $r->placement ?? A::PERIODO;
            $ov = $overrides[$pid][$r->fingerprint] ?? null;
            $processed = $r->processed_at !== null;
            $treatment = $ov['treatment'] ?? ($processed ? (string) $r->auto_treatment : T::PENDIENTE);
            $w = $winners[(int) $r->id] ?? null;
            $kind = $this->partKind($place, $in, false);
            $row = [
                'id' => (int) $r->id,
                'fingerprint' => $r->fingerprint,
                'upload_id' => (int) $r->upload_id,
                'file' => $r->original_name,
                'account_slot' => $r->account_slot,
                'account_label' => $r->account_label,
                'source_row' => (int) $r->source_row,
                'date' => substr((string) $r->movement_date, 0, 10),
                'reference' => $r->bank_reference,
                'folio' => $r->folio_raw,
                'uuid' => $r->uuid,
                'beneficiary' => $r->beneficiary,
                'concept' => $r->concept,
                'observations' => $r->observations,
                'sucursal_raw' => $r->sucursal_raw,
                'branch_name' => $r->branch_name,
                'cargo' => Money::str2($r->cargo),
                'abono' => Money::str2($r->abono),
                'period_id' => $pid,
                'period_label' => $labels[$pid] ?? null,
                'upload_period_label' => $labels[(int) $r->upload_period_id] ?? null,
                'in_period' => $in,
                'placement' => $place,
                'part' => $kind,
                'status' => $place === A::DUPLICADO ? self::DUPLICADO : self::FUERA,
                'status_label' => $place === A::DUPLICADO ? self::STATUS_LABELS[self::DUPLICADO] : self::STATUS_LABELS[self::FUERA],
                'explain' => $this->outsideExplain($place, $in, $labels[$pid] ?? null, $w, $labels),
                'duplicate_of' => $w ? $w + ['period_label' => $labels[$w['period_id']] ?? null] : null,
                'treatment' => $treatment,
                'treatment_label' => T::exists($treatment) ? T::label($treatment) : $treatment,
                'auto_treatment' => $processed ? $r->auto_treatment : null,
                'suggested_treatment' => $r->suggested_treatment,
                'rule_code' => $processed ? $r->rule_code : 'sin_procesar',
                'rule_reason' => $processed ? $r->reason : ($in ? 'El periodo '.($labels[$pid] ?? '').' aún no se procesa.' : null),
                'override' => $ov ? ['previous' => $ov['previous'] ?? null] : null,
                'noi_employee_id' => $r->noi_employee_id ? (int) $r->noi_employee_id : null,
            ];

            return $this->redactRow($row, $canNomina, $canDg);
        })->values()->all();

        return ['data' => $data, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / max(1, $perPage)))];
    }

    /**
     * Filas leídas del archivo que NO quedaron guardadas: se vuelve a leer el archivo original
     * y se comparan las filas de origen contra los movimientos guardados de la carga.
     *
     * @return array{files: list<array<string,mixed>>, data: list<array<string,mixed>>}
     */
    public function notSavedRows(Period $period, ?int $uploadId): array
    {
        $control = $this->fileControl($period);
        $files = [];
        $data = [];
        foreach ($control['uploads'] as $u) {
            if ($u['saved_ok'] || ($uploadId !== null && $u['upload_id'] !== $uploadId)) {
                continue;
            }
            $upload = EstadoResultadosUpload::query()->find($u['upload_id']);
            $entry = ['upload_id' => $u['upload_id'], 'file' => $u['file'], 'count' => $u['not_saved']['count'], 'amount' => $u['not_saved']['total'], 'readable' => false, 'message' => null];
            $path = $upload?->stored_path;
            if (! $path || ! Storage::disk(EstadoResultadosUploadService::DISK)->exists($path)) {
                $entry['message'] = 'El archivo original no está guardado en el servidor: no se puede saber qué filas faltan. Vuelve a cargarlo.';
                $files[] = $entry;

                continue;
            }
            try {
                $parsed = app(BankStatementParser::class)->parse(Storage::disk(EstadoResultadosUploadService::DISK)->path($path), pathinfo($path, PATHINFO_EXTENSION) ?: null);
            } catch (\Throwable $e) {
                $entry['message'] = 'No se pudo volver a leer el archivo original: '.$e->getMessage();
                $files[] = $entry;

                continue;
            }
            $savedRows = DB::table('estado_resultados_movements')->where('upload_id', $u['upload_id'])->pluck('source_row')->map(fn ($v) => (int) $v)->countBy()->all();
            $entry['readable'] = true;
            $found = 0;
            foreach ($parsed['movements'] as $m) {
                $row = (int) $m['source_row'];
                if (($savedRows[$row] ?? 0) > 0) {
                    $savedRows[$row]--;

                    continue;
                }
                $found++;
                $data[] = [
                    'fingerprint' => null, 'upload_id' => $u['upload_id'], 'file' => $u['file'], 'account_slot' => $u['slot'], 'source_row' => $row,
                    'date' => (string) $m['movement_date'], 'concept' => $m['concept'] ?? null, 'reference' => $m['bank_reference'] ?? null,
                    'cargo' => Money::str2($m['cargo'] ?? 0), 'abono' => Money::str2($m['abono'] ?? 0),
                    'status' => self::NO_GUARDADO, 'status_label' => self::STATUS_LABELS[self::NO_GUARDADO],
                    'explain' => 'La fila '.$row.' se leyó del archivo pero no quedó guardada como movimiento: no está en ningún periodo. Vuelve a cargar el archivo para recuperarla.',
                ];
            }
            if ($found === 0) {
                $entry['message'] = 'Al volver a leer el archivo no aparece ninguna fila sin guardar: la diferencia viene de los totales registrados al cargarlo. Vuelve a cargar el archivo.';
            }
            $files[] = $entry;
        }

        return ['files' => $files, 'data' => $data];
    }

    // ───────────────────────────────────────────────────────────────────

    private function partKind(string $placement, bool $in, bool $here): string
    {
        if ($in) {
            return $here ? 'entra' : 'otro_periodo';
        }

        return match ($placement) {
            A::DUPLICADO, A::SIN_PERIODO, A::PERIODO_CERRADO, A::PERIODO_EXISTENTE => $placement,
            default => 'fuera_periodo',
        };
    }

    private function partLabel(string $kind, ?string $periodLabel): string
    {
        return match ($kind) {
            'entra' => 'Entra en este periodo',
            'otro_periodo' => 'Va a '.($periodLabel ?? 'otro periodo'),
            A::DUPLICADO => 'Repetido (otro archivo ya lo trae)',
            A::SIN_PERIODO => 'Sin periodo mensual para su fecha',
            A::PERIODO_CERRADO => 'Su fecha cae en un periodo aprobado',
            A::PERIODO_EXISTENTE => 'Su fecha cae en un periodo cargado antes',
            default => 'Fuera de periodo (carga anterior al reparto)',
        };
    }

    private function outsideExplain(string $placement, bool $in, ?string $periodLabel, ?array $w, array $labels): string
    {
        if ($in) {
            return 'Por su fecha se suma en '.($periodLabel ?? 'otro periodo').': ahí se clasifica y se valida.';
        }
        if ($placement === A::DUPLICADO) {
            return $w ? 'Repetido: cuenta el de '.($w['file'] ?? '—').', fila '.$w['source_row'].' ('.($labels[$w['period_id']] ?? '—').'). Esta copia no suma.' : 'Repetido: otro archivo ya trae el mismo movimiento; esta copia no suma.';
        }

        return (self::PLACEMENT_LABELS[$placement] ?? 'Fuera de periodo').'. Se conserva en '.($periodLabel ?? 'el periodo donde se subió').' sin sumar.';
    }

    private function redactRow(array $row, bool $canNomina, bool $canDg): array
    {
        $hidden = (! $canNomina && EstadoResultadosRedactor::isPayroll($row)) || (! $canDg && EstadoResultadosRedactor::isDg($row));
        unset($row['override']);
        if (! $canNomina) {
            $row['noi_employee_id'] = null;
        }
        if ($hidden) {
            foreach (['concept', 'reference', 'beneficiary', 'observations', 'folio', 'uuid', 'sucursal_raw', 'rule_reason', 'fingerprint'] as $f) {
                $row[$f] = $f === 'concept' ? 'Detalle restringido' : null;
            }
            $row['redacted'] = true;
        }

        return $row;
    }

    private function triple(array $t): array
    {
        return ['count' => (int) $t['count'], 'cargos' => Money::str2($t['cargos']), 'abonos' => Money::str2($t['abonos']), 'total' => Money::str2(Money::of($t['cargos'])->plus(Money::of($t['abonos'])))];
    }

    private function emptyTotals(): array
    {
        return ['files' => 0, 'own_files' => 0, 'period_share' => ['count' => 0, 'cargos' => '0.00', 'abonos' => '0.00', 'total' => '0.00'],
            'not_saved' => ['count' => 0, 'total' => '0.00', 'files' => 0],
            'rule' => 'Lado archivo del periodo = Σ por archivo (total leído − lo que el reparto por fecha guardó en otros periodos).'];
    }
}
