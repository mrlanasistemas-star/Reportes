<?php

namespace App\Services\EstadoResultados\Control;

use App\Models\Period;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Classification\ConceptCatalogService;
use App\Services\EstadoResultados\Import\MovementPeriodAllocator as A;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Facades\DB;

/**
 * Pendientes AGRUPADOS por concepto de la pestaña Validación (24-nov-2026) y su clasificación
 * masiva segura (vista previa → confirmar), con bitácora.
 *
 * Problema que resuelve: en un mes hay cientos de pendientes repetidos («DEPOSITO LEROM»
 * por $4,095.00 de cada sucursal) que había que abrir uno por uno.
 *
 * Fuente única: los movimientos del MISMO dataset que pinta la lista (resolver del ER), así el
 * total de los grupos cuadra con el contador «Pendientes». Un solo recorrido en memoria:
 * ninguna consulta por grupo (sin N+1).
 *
 * La clasificación manual masiva escribe con ClassificationService (bitácora append-only con
 * usuario, fecha, tratamiento anterior y motivo). Nunca toca lo que el clasificador protege:
 * conciliado con ERP, duplicados, fuera de periodo, traspasos, reversos, lo ya clasificado a
 * mano ni periodos aprobados. La alternativa «regla del catálogo» usa ConceptCatalogService
 * (su vista previa ya explica las exclusiones) y se aplica al procesar la conciliación.
 */
class PendingGroupService
{
    /** Máximo de movimientos por operación masiva (igual que POST /clasificar). */
    public const MAX_ITEMS = 2000;

    /** Máximo de grupos que devuelve el endpoint. */
    public const MAX_GROUPS = 200;

    /** Meses (completos y abreviados) que se quitan del concepto: son fechas variables. */
    private const MONTHS = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'SETIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE',
        'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'SEPT', 'OCT', 'NOV', 'DIC'];

    /** Marcadores de referencia que quedan sueltos al quitar el número que acompañaban. */
    private const REF_WORDS = ['REF', 'REFERENCIA', 'FOLIO', 'NUM', 'NO', 'CLAVE', 'RASTREO', 'AUT', 'AUTORIZACION', 'OP', 'OPERACION', 'CTA', 'TRANS'];

    /** Motivos por los que un movimiento NO se clasifica en la operación masiva (clave ⇒ texto). */
    public const REASONS = [
        'periodo_aprobado' => 'El periodo está aprobado: abre una nueva versión para cambiarlo.',
        'no_encontrado' => 'Ya no está en el periodo (se reprocesó o se reemplazó el archivo).',
        'restringido' => 'Detalle de nómina o Dirección General: tu rol no permite clasificarlo.',
        'no_clasificable' => 'Viene del NOI de corporativo: se clasifica desde su fuente.',
        'duplicado' => 'Repetido: otro archivo o UUID ya trae este movimiento.',
        'fuera_periodo' => 'Fuera de este periodo: no suma aquí.',
        'conciliado_erp' => 'Conciliado con el ERP: el gasto ya cuenta desde el ERP.',
        'revision_erp' => 'Su folio tiene diferencias contra el ERP: primero se revisa la conciliación.',
        'traspaso' => 'Traspaso entre cuentas propias: no es ingreso ni gasto.',
        'reverso' => 'Par de reverso (cargo y abono que se anulan).',
        'manual' => 'Ya tiene una clasificación manual: no se pisa en bloque.',
        'no_pendiente' => 'Ya está clasificado por una regla: no está pendiente.',
        'sin_cambio' => 'Ya tiene ese tratamiento.',
    ];

    /** @param  ClassificationService  $classifications  escritura con bitácora */
    public function __construct(private readonly ClassificationService $classifications) {}

    /**
     * Normalización para AGRUPAR conceptos (no es la del catálogo, que conserva números):
     *  1. MAYÚSCULAS, sin acentos y espacios simples (ConceptCatalogService::normalize);
     *  2. quita fechas (12/04/2026, 2026-04-12, 12ABR26) y meses sueltos (ABRIL, ABR);
     *  3. quita toda palabra con dígitos (folios, referencias, importes, claves de rastreo);
     *  4. cambia la puntuación por espacios y quita marcadores sueltos (REF, FOLIO, NO…).
     * «DEPÓSITO  LEROM 000123» y «Deposito Lerom» ⇒ «DEPOSITO LEROM». Vacío ⇒ «(SIN CONCEPTO)».
     *
     * @param  string|null  $concept  concepto tal como viene del estado de cuenta
     */
    public static function normalizeConcept(?string $concept): string
    {
        $v = ConceptCatalogService::normalize($concept);
        $v = preg_replace('/\b\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4}\b/', ' ', $v) ?? $v;
        $v = preg_replace('/\S*\d\S*/', ' ', $v) ?? $v;
        $v = preg_replace('/[^A-Z ]+/', ' ', $v) ?? $v;
        $skip = array_flip([...self::MONTHS, ...self::REF_WORDS]);
        $words = array_values(array_filter(explode(' ', $v), fn ($w) => $w !== '' && ! isset($skip[$w])));

        return $words === [] ? '(SIN CONCEPTO)' : implode(' ', $words);
    }

    /**
     * Agrupa los PENDIENTES del dataset por concepto normalizado + sentido (cargo/abono).
     *
     * Cada grupo: conteo, suma, importe típico (el más repetido), sucursales de origen con su
     * conteo, cuentas, rango de fechas, tratamiento sugerido más común, si son aportaciones a
     * créditos, huellas (para «Ver movimientos» y clasificar) y el texto de regla del catálogo
     * que cubre al grupo (el tramo más largo del concepto que todos contienen).
     * Orden: más movimientos primero, luego mayor importe.
     *
     * @param  list<array<string,mixed>>  $movements  dataset.movements (ya redactado para el usuario)
     * @param  int  $limit  máximo de grupos (1..MAX_GROUPS)
     * @return array{groups: list<array<string,mixed>>, total_groups:int, total_pending:int, pending_amount:string, redacted:int, truncated:bool}
     */
    public function groups(array $movements, int $limit = 60): array
    {
        $limit = max(1, min(self::MAX_GROUPS, $limit));
        $acc = [];
        $pending = 0;
        $redacted = 0;
        $amount = Money::of(0);
        foreach ($movements as $m) {
            if (MovementControlService::controlOf($m)['status'] !== MovementControlService::PENDIENTE) {
                continue;
            }
            $pending++;
            $gross = Money::of($m['cargo'] ?? 0)->plus(Money::of($m['abono'] ?? 0));
            $amount = $amount->plus($gross);
            if (! empty($m['redacted']) || empty($m['fingerprint'])) {
                $redacted++;

                continue;
            }
            $dir = (float) ($m['cargo'] ?? 0) > 0 ? 'cargo' : 'abono';
            $label = self::normalizeConcept($m['concept'] ?? null);
            $key = $dir.'|'.$label;
            $acc[$key] ??= ['label' => $label, 'direction' => $dir, 'items' => []];
            $acc[$key]['items'][] = $m + ['_gross' => Money::str2($gross)];
        }

        $groups = array_map(fn ($g) => $this->present($g), array_values($acc));
        usort($groups, fn ($a, $b) => [$b['count'], (float) $b['total'], $a['label']] <=> [$a['count'], (float) $a['total'], $b['label']]);

        return [
            'groups' => array_slice($groups, 0, $limit),
            'total_groups' => count($groups),
            'total_pending' => $pending,
            'pending_amount' => Money::str2($amount),
            'redacted' => $redacted,
            'truncated' => count($groups) > $limit,
        ];
    }

    /**
     * Vista previa de la clasificación MANUAL masiva: qué movimientos cambian, cuáles se excluyen
     * y por qué, importe que cambia y advertencias. Sólo lectura.
     *
     * @param  list<array<string,mixed>>  $movements  dataset SIN redactar (consolidado)
     * @param  list<string>  $fingerprints  huellas pedidas
     * @param  string  $treatment  tratamiento destino (asignable)
     * @param  bool  $locked  el periodo tiene versión aprobada vigente
     * @param  callable(array): bool  $canSee  el usuario puede ver/clasificar ese movimiento (nómina/DG)
     * @return array<string,mixed>
     */
    public function preview(array $movements, array $fingerprints, string $treatment, bool $locked, callable $canSee): array
    {
        $byFp = [];
        foreach ($movements as $m) {
            $byFp[$m['fingerprint']] ??= $m;
        }
        $reasons = [];
        $items = [];
        $applies = [];
        $amount = Money::of(0);
        $warnSuggested = 0;
        $warnDirection = 0;
        foreach (array_values(array_unique($fingerprints)) as $fp) {
            $m = $byFp[$fp] ?? null;
            $reason = $this->exclusion($m, $treatment, $locked, $canSee);
            $gross = $m ? Money::of($m['cargo'] ?? 0)->plus(Money::of($m['abono'] ?? 0)) : Money::of(0);
            if ($reason === null) {
                $applies[] = ['fingerprint' => $fp, 'source' => (string) $m['source'], 'current' => (string) $m['treatment'], 'considered' => $m['override']['considered_amount'] ?? null];
                $amount = $amount->plus($gross);
                if (($m['suggested_treatment'] ?? null) && $m['suggested_treatment'] !== $treatment) {
                    $warnSuggested++;
                }
                $isCargo = (float) ($m['cargo'] ?? 0) > 0;
                if (T::affectsStatement($treatment) && ($treatment === T::INGRESO_NO_OPERATIVO ? $isCargo : ! $isCargo)) {
                    $warnDirection++;
                }
            } else {
                $reasons[$reason] ??= ['key' => $reason, 'label' => self::REASONS[$reason], 'count' => 0, 'amount' => Money::of(0)];
                $reasons[$reason]['count']++;
                $reasons[$reason]['amount'] = $reasons[$reason]['amount']->plus($gross);
            }
            if (count($items) < 300) {
                $hide = $m !== null && ! $canSee($m);
                $items[] = [
                    'fingerprint' => $fp, 'applies' => $reason === null, 'reason_key' => $reason, 'reason' => $reason ? self::REASONS[$reason] : null,
                    'date' => $m['date'] ?? null, 'concept' => $hide ? 'Detalle restringido' : ($m['concept'] ?? null),
                    'amount' => Money::str2($gross), 'current_label' => $m ? T::label((string) $m['treatment']) : null,
                ];
            }
        }

        $warnings = [];
        if ($treatment === T::PENDIENTE) {
            $warnings[] = 'Dejarlos en «Pendiente» no los saca de la revisión.';
        }
        if ($warnSuggested > 0) {
            $warnings[] = $warnSuggested.' movimiento(s) tienen otra sugerencia del sistema.';
        }
        if ($warnDirection > 0) {
            $warnings[] = $treatment === T::INGRESO_NO_OPERATIVO
                ? $warnDirection.' son cargos (salidas) y el destino es un ingreso.'
                : $warnDirection.' son abonos (entradas) y el destino es un gasto del ER.';
        }

        return [
            'treatment' => $treatment, 'treatment_label' => T::label($treatment), 'locked' => $locked,
            'requested' => count(array_unique($fingerprints)), 'applies' => count($applies), 'amount' => Money::str2($amount),
            'excluded' => count(array_unique($fingerprints)) - count($applies),
            'reasons' => array_values(array_map(fn ($r) => ['amount' => Money::str2($r['amount'])] + $r, $reasons)),
            'warnings' => $warnings, 'items' => $items, 'truncated' => count(array_unique($fingerprints)) > 300,
            '_apply' => $applies,
        ];
    }

    /**
     * Clasifica a mano, en bloque, SÓLO los movimientos que la vista previa deja pasar (la
     * vista previa se recalcula aquí: el front nunca decide las exclusiones). Bitácora por
     * movimiento (ClassificationService) con usuario, fecha, tratamiento anterior y motivo.
     *
     * @param  Period  $period  periodo (no aprobado: si no, HTTP 423)
     * @param  list<array<string,mixed>>  $movements  dataset SIN redactar
     * @param  list<string>  $fingerprints  huellas pedidas
     * @param  string  $treatment  tratamiento destino
     * @param  string  $notes  motivo (mínimo 3 caracteres)
     * @param  int|null  $userId  usuario
     * @param  callable(array): bool  $canSee  permiso de detalle por movimiento
     * @return array{count:int, excluded:int, reasons:list<array<string,mixed>>}
     */
    public function apply(Period $period, array $movements, array $fingerprints, string $treatment, string $notes, ?int $userId, callable $canSee): array
    {
        $preview = $this->preview($movements, $fingerprints, $treatment, false, $canSee);
        $count = $preview['_apply'] === [] ? 0 : $this->classifications->classify($period, $preview['_apply'], $treatment, $notes, $userId);

        return ['count' => $count, 'excluded' => $preview['excluded'], 'reasons' => $preview['reasons']];
    }

    /**
     * Deshace la clasificación manual de los movimientos indicados: agrega a la bitácora una
     * clasificación que devuelve cada uno al tratamiento de la regla automática (lo que diría
     * el sistema sin la intervención manual). Los que no tienen clasificación manual o cuya
     * regla automática no es asignable a mano (p. ej. conciliado con ERP) se omiten.
     *
     * @param  Period  $period  periodo (no aprobado)
     * @param  list<array<string,mixed>>  $movements  dataset SIN redactar
     * @param  list<string>  $fingerprints  huellas
     * @param  string  $notes  motivo
     * @param  int|null  $userId  usuario
     * @param  callable(array): bool  $canSee  permiso de detalle
     * @return array{count:int, skipped:int}
     */
    public function revert(Period $period, array $movements, array $fingerprints, string $notes, ?int $userId, callable $canSee): array
    {
        $wanted = array_flip($fingerprints);
        $byTarget = [];
        $skipped = 0;
        foreach ($movements as $m) {
            if (! isset($wanted[$m['fingerprint'] ?? ''])) {
                continue;
            }
            unset($wanted[$m['fingerprint']]);
            $auto = (string) ($m['auto_treatment'] ?? '');
            // Un «Ya considerado» parcial con el mismo tratamiento que la regla también se deshace (quita el reparto).
            $split = ($m['override']['considered_amount'] ?? null) !== null;
            if (($m['override'] ?? null) === null || ! $canSee($m) || ! T::isAssignable($auto) || ($auto === $m['treatment'] && ! $split)) {
                $skipped++;

                continue;
            }
            $byTarget[$auto][] = ['fingerprint' => $m['fingerprint'], 'source' => (string) $m['source'], 'current' => (string) $m['treatment'],
                'considered' => $m['override']['considered_amount'] ?? null, 'considered_reason' => $m['override']['considered_reason'] ?? null];
        }
        $skipped += count($wanted);

        $count = DB::transaction(function () use ($period, $byTarget, $notes, $userId) {
            $n = 0;
            foreach ($byTarget as $target => $items) {
                $n += $this->classifications->classify($period, $items, $target, $notes, $userId);
            }

            return $n;
        });

        return ['count' => $count, 'skipped' => $skipped];
    }

    /**
     * Por qué un movimiento NO entra en la clasificación masiva (null = sí entra).
     *
     * @param  array|null  $m  movimiento del dataset sin redactar
     * @param  string  $treatment  destino
     * @param  bool  $locked  periodo aprobado
     * @param  callable  $canSee  permiso de detalle
     */
    private function exclusion(?array $m, string $treatment, bool $locked, callable $canSee): ?string
    {
        $status = (string) ($m['reconciliation_status'] ?? '');

        return match (true) {
            $locked => 'periodo_aprobado',
            $m === null => 'no_encontrado',
            ! $canSee($m) => 'restringido',
            ($m['source'] ?? null) === 'noi_corp' => 'no_clasificable',
            ($m['placement'] ?? null) === A::DUPLICADO || $status === R::DUPLICADO_UUID => 'duplicado',
            ! ($m['in_period'] ?? true) => 'fuera_periodo',
            $m['treatment'] === T::CONCILIADO_ERP || in_array($status, R::CONCILIADOS, true) => 'conciliado_erp',
            in_array($status, R::REVISION, true) => 'revision_erp',
            $status === 'transferencia' || ($m['rule_code'] ?? null) === 'traspaso_cuentas_propias' => 'traspaso',
            $status === 'reverso' || $m['treatment'] === T::REVERSO => 'reverso',
            ($m['override'] ?? null) !== null => 'manual',
            $m['treatment'] !== T::PENDIENTE => 'no_pendiente',
            $m['treatment'] === $treatment => 'sin_cambio',
            default => null,
        };
    }

    /**
     * Arma la tarjeta de un grupo a partir de sus movimientos.
     *
     * @param  array{label:string, direction:string, items:list<array>}  $g  acumulado del grupo
     * @return array<string,mixed>
     */
    private function present(array $g): array
    {
        $items = $g['items'];
        $amounts = array_count_values(array_column($items, '_gross'));
        arsort($amounts);
        $typical = (string) array_key_first($amounts);
        $branches = array_count_values(array_map(fn ($m) => trim((string) ($m['branch_name'] ?? '')) ?: (trim((string) ($m['sucursal_raw'] ?? '')) ?: 'Sin sucursal'), $items));
        arsort($branches);
        $accounts = array_count_values(array_map(fn ($m) => (string) ($m['account_label'] ?? $m['account_slot'] ?? ($m['source'] === 'erp' ? 'ERP de gastos' : '—')), $items));
        arsort($accounts);
        $suggested = array_count_values(array_filter(array_map(fn ($m) => $m['suggested_treatment'] ?? null, $items), fn ($t) => $t && T::isAssignable($t) && $t !== T::PENDIENTE));
        arsort($suggested);
        $concepts = array_count_values(array_map(fn ($m) => trim((string) ($m['concept'] ?? '')), $items));
        arsort($concepts);
        $dates = array_filter(array_column($items, 'date'));
        sort($dates);
        $rule = $this->ruleText($g['label'], $items);
        $codes = array_count_values(array_map(fn ($m) => (string) ($m['rule_code'] ?? ''), $items));
        arsort($codes);
        $topSuggested = array_key_first($suggested);

        return [
            'key' => $g['direction'].'|'.$g['label'],
            'label' => $g['label'],
            'direction' => $g['direction'],
            'count' => count($items),
            'total' => Money::str2(Money::sum(array_column($items, '_gross'))),
            'typical_amount' => $typical,
            'typical_count' => (int) ($amounts[$typical] ?? 0),
            'sample_concept' => (string) array_key_first($concepts),
            'variants' => count($concepts),
            'branches' => array_map(fn ($n, $c) => ['name' => (string) $n, 'count' => $c], array_keys($branches), $branches),
            'accounts' => array_map(fn ($n, $c) => ['name' => (string) $n, 'count' => $c], array_keys($accounts), $accounts),
            'suggested' => $topSuggested ? ['treatment' => $topSuggested, 'label' => T::label($topSuggested), 'count' => $suggested[$topSuggested]] : null,
            'rule_codes' => array_slice(array_map(fn ($c, $n) => ['code' => (string) $c, 'count' => $n], array_keys($codes), $codes), 0, 5),
            'credit_contribution' => array_intersect(array_keys($codes), MovementControlService::CREDIT_CONTRIBUTION_RULES) !== [],
            'date_from' => $dates[0] ?? null,
            'date_to' => $dates ? end($dates) : null,
            'fingerprints' => array_column($items, 'fingerprint'),
            'rule_text' => $rule['text'],
            'rule_covers' => $rule['covers'],
            'rule_name' => mb_convert_case(mb_strtolower($g['label']), MB_CASE_TITLE),
        ];
    }

    /**
     * Texto para una regla del catálogo («contiene») que atrape al grupo: el tramo de palabras
     * consecutivas del concepto agrupado más largo que TODOS los movimientos contienen (con la
     * normalización del catálogo). Si ninguno alcanza, el concepto agrupado y cuántos cubre.
     *
     * @param  string  $label  concepto normalizado del grupo
     * @param  list<array>  $items  movimientos del grupo
     * @return array{text:string, covers:int}
     */
    private function ruleText(string $label, array $items): array
    {
        $norms = array_map(fn ($m) => ConceptCatalogService::normalize($m['concept'] ?? null), $items);
        $covers = fn (string $t) => count(array_filter($norms, fn ($n) => str_contains($n, $t)));
        $words = array_slice(explode(' ', $label), 0, 12);
        for ($len = count($words); $len >= 1; $len--) {
            for ($start = 0; $start + $len <= count($words); $start++) {
                $text = implode(' ', array_slice($words, $start, $len));
                if (mb_strlen($text) >= ConceptCatalogService::MIN_TEXT && $covers($text) === count($items)) {
                    return ['text' => $text, 'covers' => count($items)];
                }
            }
        }

        return ['text' => $label, 'covers' => $covers($label)];
    }
}
