<?php

namespace App\Services\EstadoResultados\Classification;

use App\Models\EstadoResultados\EstadoResultadosClassification;
use App\Models\EstadoResultados\EstadoResultadosConceptRule;
use App\Models\EstadoResultados\EstadoResultadosConceptRuleLog;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Catálogo de conceptos del Estado de Resultados (global, administrado por el usuario):
 * «cuando en el estado de cuenta venga ESTE texto, el movimiento va a TAL concepto».
 *
 * - CRUD con bitácora append-only (estado_resultados_concept_rule_logs) y borrado lógico.
 * - match(): qué regla gana para un movimiento (mayor prioridad, luego el texto más largo
 *   = más específico, luego la más antigua) y qué otras reglas también coinciden (conflicto).
 * - signature(): firma de las reglas vigentes. Se guarda en cada corrida de «Procesar
 *   conciliación»; si cambia, el periodo queda desactualizado hasta reprocesar.
 *
 * El catálogo NO se aplica aquí a los movimientos: lo aplica MovementClassifier al procesar,
 * respetando lo que nunca debe pisar (fuera de periodo/duplicado, conciliado con ERP,
 * duplicado UUID, traspasos entre cuentas propias). Lo manual por movimiento sigue ganando.
 */
class ConceptCatalogService
{
    /** Prefijo del rule_code de un movimiento clasificado por el catálogo («catalogo:{id}»). */
    public const RULE_PREFIX = 'catalogo:';

    /** Firma cuando no hay reglas activas (no altera firmas históricas). */
    public const EMPTY_SIGNATURE = 'sin_catalogo';

    /** Modos de coincidencia del texto. */
    public const MODES = [
        'contiene' => 'Contiene',
        'empieza' => 'Empieza con',
        'exacto' => 'Es exactamente',
    ];

    /** campo de la regla => columna de estado_resultados_movements (y etiqueta). */
    public const FIELDS = [
        'concept' => 'Concepto',
        'observations' => 'Observaciones',
        'beneficiary' => 'Beneficiario',
        'concept_gasto' => 'Concepto de gasto',
        'bank_reference' => 'Referencia bancaria',
    ];

    /** Campos donde se busca si la regla no indica ninguno. */
    public const DEFAULT_FIELDS = ['concept', 'observations'];

    /** Sentido del movimiento al que aplica la regla. */
    public const DIRECTIONS = [
        'cualquiera' => 'Cargos y abonos',
        'cargo' => 'Sólo cargos (salidas)',
        'abono' => 'Sólo abonos (entradas)',
    ];

    /** Longitud mínima del texto normalizado (evita reglas que atrapen de más). */
    public const MIN_TEXT = 3;

    /**
     * Misma normalización que MovementClassifier: MAYÚSCULAS, sin acentos, espacios simples.
     *
     * @param  string|null  $v  texto
     */
    public static function normalize(?string $v): string
    {
        $v = mb_strtoupper(Str::ascii(trim((string) $v)));

        return preg_replace('/\s+/', ' ', $v) ?? $v;
    }

    /**
     * ¿Existe la tabla del catálogo? (memorizado por proceso; sin migración no hay catálogo).
     */
    public static function available(): bool
    {
        static $ok = null;

        return $ok ??= Schema::hasTable('estado_resultados_concept_rules');
    }

    // ───────────────────────────────────────────────────────────────────
    // Lectura
    // ───────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> todas las reglas (activas e inactivas) para la pantalla. */
    public function list(): array
    {
        if (! self::available()) {
            return [];
        }

        return EstadoResultadosConceptRule::query()->with(['creator:id,name', 'editor:id,name'])
            ->orderByDesc('active')->orderByDesc('priority')->orderBy('name')->orderBy('id')
            ->get()->map(fn ($r) => $this->present($r))->all();
    }

    /**
     * Reglas activas ya compiladas para el clasificador (orden de precedencia aplicado).
     *
     * @return list<array{id:int,name:string,text:string,norm:string,mode:string,fields:list<string>,account_slot:?string,direction:string,treatment:string,priority:int}>
     */
    public function activeRules(): array
    {
        if (! self::available()) {
            return [];
        }

        $rules = EstadoResultadosConceptRule::query()->where('active', true)->orderBy('id')->get()
            ->map(fn ($r) => $this->compile($r))
            ->filter(fn ($r) => $r['norm'] !== '' && Treatment::isAssignable($r['treatment']))
            ->values()->all();

        return self::sortByPrecedence($rules);
    }

    /**
     * Firma de las reglas vigentes (lo que cambia la clasificación). Sin reglas ⇒ EMPTY_SIGNATURE.
     *
     * @param  array|null  $rules  reglas compiladas (null = activeRules())
     * @return string SHA-256 de las reglas ordenadas por id
     */
    public function signature(?array $rules = null): string
    {
        $rules ??= $this->activeRules();
        if ($rules === []) {
            return self::EMPTY_SIGNATURE;
        }
        $parts = array_map(fn ($r) => [$r['id'], $r['name'], $r['norm'], $r['mode'], $r['fields'], $r['account_slot'], $r['direction'], $r['treatment'], $r['priority']], $rules);
        usort($parts, fn ($a, $b) => $a[0] <=> $b[0]);

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Bitácora de cambios de una regla (más reciente primero) con antes/después.
     *
     * @param  EstadoResultadosConceptRule  $rule  regla
     * @return list<array<string,mixed>>
     */
    public function logs(EstadoResultadosConceptRule $rule): array
    {
        return EstadoResultadosConceptRuleLog::query()->with('user:id,name')->where('rule_id', $rule->id)->orderByDesc('id')->get()
            ->map(fn ($l) => [
                'action' => $l->action, 'user' => $l->user?->name,
                'at' => $l->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                'before' => $l->before, 'after' => $l->after,
            ])->all();
    }

    // ───────────────────────────────────────────────────────────────────
    // Coincidencia
    // ───────────────────────────────────────────────────────────────────

    /**
     * Regla ganadora para un movimiento y las demás que también coinciden.
     *
     * @param  array<string,mixed>  $m  columnas del movimiento (concept, observations, beneficiary, concept_gasto, bank_reference, account_slot, cargo)
     * @param  list<array<string,mixed>>  $rules  activeRules() (ya ordenadas por precedencia)
     * @return array{rule:?array, others:list<array>}
     */
    public static function match(array $m, array $rules): array
    {
        $hits = [];
        foreach ($rules as $r) {
            if (self::matches($m, $r)) {
                $hits[] = $r;
            }
        }
        if ($hits === []) {
            return ['rule' => null, 'others' => []];
        }
        $hits = self::sortByPrecedence($hits);

        return ['rule' => $hits[0], 'others' => array_slice($hits, 1)];
    }

    /**
     * ¿La regla coincide con el movimiento? (filtros de cuenta y sentido + texto en algún campo).
     *
     * @param  array  $m  columnas del movimiento
     * @param  array  $r  regla compilada
     */
    public static function matches(array $m, array $r): bool
    {
        if ($r['account_slot'] !== null && $r['account_slot'] !== ($m['account_slot'] ?? null)) {
            return false;
        }
        $isCargo = (float) ($m['cargo'] ?? 0) > 0;
        if (($r['direction'] === 'cargo' && ! $isCargo) || ($r['direction'] === 'abono' && $isCargo)) {
            return false;
        }
        foreach ($r['fields'] as $field) {
            $value = self::normalize(isset($m[$field]) ? (string) $m[$field] : '');
            if ($value === '') {
                continue;
            }
            $hit = match ($r['mode']) {
                'exacto' => $value === $r['norm'],
                'empieza' => str_starts_with($value, $r['norm']),
                default => str_contains($value, $r['norm']),
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mayor prioridad primero; empate ⇒ texto más largo (más específico); luego la más antigua.
     *
     * @param  array  $rules  reglas compiladas
     * @return array reglas ordenadas
     */
    public static function sortByPrecedence(array $rules): array
    {
        usort($rules, fn ($a, $b) => [$b['priority'], mb_strlen($b['norm']), $a['id']] <=> [$a['priority'], mb_strlen($a['norm']), $b['id']]);

        return $rules;
    }

    /**
     * Resultado de clasificación (mismo formato que MovementClassifier::out) para la regla ganadora.
     * Una regla que manda a «pendiente» marca el movimiento para revisión.
     *
     * @param  array  $rule  regla ganadora
     * @param  array  $others  otras reglas que también coinciden (se citan en el motivo)
     * @return array{treatment:string, suggested:null, rule:string, reason:string, review:bool, concept_rule:array}
     */
    public static function outcome(array $rule, array $others = []): array
    {
        $reason = 'Catálogo de conceptos: “'.$rule['text'].'” → '.$rule['name'].' ('.Treatment::label($rule['treatment']).').';
        if ($others !== []) {
            $reason .= ' También coinciden: '.implode('; ', array_map(fn ($o) => '“'.$o['text'].'” → '.$o['name'].' (prioridad '.$o['priority'].')', $others))
                .'. Gana la de mayor prioridad y, si empatan, la del texto más largo.';
        }

        return [
            'treatment' => $rule['treatment'],
            'suggested' => null,
            'rule' => self::RULE_PREFIX.$rule['id'],
            'reason' => $reason,
            'review' => $rule['treatment'] === Treatment::PENDIENTE,
            'concept_rule' => ['id' => $rule['id'], 'name' => $rule['name'], 'conflicts' => array_map(fn ($o) => $o['id'], $others)],
        ];
    }

    /**
     * Vista previa: movimientos del periodo que coinciden con una regla (guardada o en captura)
     * y si de verdad se aplicaría al procesar (o por qué no).
     *
     * Sólo lectura. Un movimiento coincide pero «no aplica» si está bloqueado (fuera de periodo,
     * manual, conciliado, duplicado UUID, en revisión ERP, traspaso o reverso) o si otra regla de
     * mayor precedencia lo gana. «amount» suma sólo los que sí aplicarían.
     *
     * @param  Period  $period  periodo cuyos movimientos se revisan
     * @param  array<string,mixed>  $draft  datos de la regla (mismo formato que create/update)
     * @param  int|null  $ruleId  id de la regla si ya existe (se excluye de las «otras»)
     * @param  int  $limit  máximo de items devueltos
     * @param  callable|null  $hidden  decide si una fila se oculta por permisos (nómina/DG)
     * @return array{total:int, applies:int, blocked:int, amount:string, items:list<array<string,mixed>>, truncated:bool}
     */
    public function preview(Period $period, array $draft, ?int $ruleId = null, int $limit = 200, ?callable $hidden = null): array
    {
        $data = $this->sanitize($draft);
        $candidate = ['id' => $ruleId ?? PHP_INT_MAX, 'name' => $data['name'] ?: 'Regla en captura', 'text' => $data['match_text'],
            'norm' => $data['match_normalized'], 'mode' => $data['match_mode'], 'fields' => $data['fields'],
            'account_slot' => $data['account_slot'], 'direction' => $data['direction'], 'treatment' => $data['treatment'], 'priority' => $data['priority']];

        $others = array_values(array_filter($this->activeRules(), fn ($r) => $r['id'] !== $ruleId));
        $overrides = EstadoResultadosClassification::query()->where('period_id', $period->id)->pluck('item_fingerprint')->flip()->all();

        $movements = EstadoResultadosMovement::query()
            ->where('period_id', $period->id)
            ->whereIn('upload_id', EstadoResultadosUpload::query()->active()->select('id'))
            ->orderBy('movement_date')->orderBy('account_slot')->orderBy('source_row')
            ->get(['id', 'fingerprint', 'account_slot', 'movement_date', 'cargo', 'abono', 'concept', 'observations', 'beneficiary', 'concept_gasto',
                'bank_reference', 'sucursal_raw', 'in_period', 'reconciliation_status', 'rule_code', 'auto_treatment', 'suggested_treatment', 'noi_employee_id', 'processed_at']);

        $items = [];
        $applies = 0;
        $amount = Money::of(0);
        foreach ($movements as $m) {
            $row = $m->only(['concept', 'observations', 'beneficiary', 'concept_gasto', 'bank_reference', 'account_slot', 'cargo']);
            if ($candidate['norm'] === '' || ! self::matches($row, $candidate)) {
                continue;
            }
            $blocked = $this->blockedReason($m, isset($overrides[$m->fingerprint]));
            $winner = $blocked === null ? self::match($row, self::sortByPrecedence([...$others, $candidate]))['rule'] : null;
            if ($blocked === null && $winner !== null && $winner['id'] !== $candidate['id']) {
                $blocked = 'Otra regla del catálogo gana este movimiento: “'.$winner['text'].'” → '.$winner['name'].' (prioridad '.$winner['priority'].').';
            }
            if ($blocked === null) {
                $applies++;
                $amount = $amount->plus(Money::of((float) $m->cargo > 0 ? $m->cargo : $m->abono));
            }
            // Sin permiso de nómina / DG: se ven fecha e importe, no el texto que identifica a la persona.
            $hide = $hidden !== null && $hidden(['treatment' => $m->auto_treatment, 'auto_treatment' => $m->auto_treatment,
                'suggested_treatment' => $m->suggested_treatment, 'rule_code' => $m->rule_code, 'noi_employee_id' => $m->noi_employee_id]);
            $items[] = [
                'fingerprint' => $m->fingerprint, 'date' => $m->movement_date?->format('Y-m-d'), 'account_slot' => $m->account_slot,
                'cargo' => Money::str2($m->cargo), 'abono' => Money::str2($m->abono),
                'concept' => $hide ? 'Oculto por permisos' : $m->concept, 'observations' => $hide ? null : $m->observations,
                'beneficiary' => $hide ? null : $m->beneficiary, 'current' => $m->processed_at ? Treatment::label((string) $m->auto_treatment) : 'Sin procesar',
                'applies' => $blocked === null, 'blocked_reason' => $blocked, 'redacted' => $hide,
            ];
        }

        return [
            'total' => count($items), 'applies' => $applies, 'blocked' => count($items) - $applies, 'amount' => Money::str2($amount),
            'items' => array_slice($items, 0, $limit), 'truncated' => count($items) > $limit,
        ];
    }

    /**
     * Por qué el catálogo NO tocaría este movimiento al procesar (null = sí lo toca).
     *
     * @param  EstadoResultadosMovement  $m  movimiento
     * @param  bool  $hasOverride  tiene clasificación manual en el periodo
     */
    private function blockedReason(EstadoResultadosMovement $m, bool $hasOverride): ?string
    {
        $status = (string) $m->reconciliation_status;

        return match (true) {
            ! $m->in_period => 'Está fuera del periodo o repetido en otro archivo: no se cuenta aquí.',
            $hasOverride => 'Tiene una clasificación manual: lo manual siempre gana sobre el catálogo.',
            in_array($status, R::CONCILIADOS, true) => 'Está conciliado con el ERP: el gasto ya se cuenta desde el ERP (no se duplica).',
            $status === R::DUPLICADO_UUID => 'Su UUID ya está en otro movimiento (duplicado).',
            in_array($status, R::REVISION, true) => 'Su folio tiene diferencias contra el ERP: primero se revisa la conciliación para no contar dos veces el gasto.',
            $status === 'transferencia' || $m->rule_code === 'traspaso_cuentas_propias' => 'Es un traspaso entre cuentas propias: no es ingreso ni gasto.',
            $status === 'reverso' => 'Forma un par de reverso (cargo y abono que se anulan).',
            default => null,
        };
    }

    // ───────────────────────────────────────────────────────────────────
    // Escritura (con bitácora)
    // ───────────────────────────────────────────────────────────────────

    /**
     * Crea una regla validada y registra «creada» en la bitácora (transacción).
     *
     * @param  array  $input  datos del formulario
     * @param  int|null  $userId  usuario
     *
     * @throws \InvalidArgumentException datos inválidos (ver validated())
     */
    public function create(array $input, ?int $userId): EstadoResultadosConceptRule
    {
        $data = $this->validated($input);

        return DB::transaction(function () use ($data, $userId) {
            $rule = EstadoResultadosConceptRule::query()->create($data + ['created_by' => $userId, 'updated_by' => $userId]);
            $this->log($rule, 'creada', null, $this->snapshot($rule), $userId);

            return $rule;
        });
    }

    /**
     * Edita una regla; si nada cambia no guarda ni registra (no invalida periodos). Si cambia,
     * registra «editada» con antes/después. Nota: 'active' viene de sanitize() (true si no se envía).
     *
     * @param  EstadoResultadosConceptRule  $rule  regla
     * @param  array  $input  datos del formulario
     * @param  int|null  $userId  usuario
     *
     * @throws \InvalidArgumentException datos inválidos
     */
    public function update(EstadoResultadosConceptRule $rule, array $input, ?int $userId): EstadoResultadosConceptRule
    {
        $data = $this->validated($input);

        return DB::transaction(function () use ($rule, $data, $userId) {
            $before = $this->snapshot($rule);
            $rule->fill($data + ['updated_by' => $userId]);
            if (! $rule->isDirty(array_keys($data))) {
                return $rule; // sin cambio real: no ensucia la bitácora ni invalida el periodo
            }
            $rule->save();
            $this->log($rule, 'editada', $before, $this->snapshot($rule), $userId);

            return $rule;
        });
    }

    /**
     * Activa o desactiva una regla (sin cambio ⇒ no hace nada) y lo registra en la bitácora.
     *
     * @param  EstadoResultadosConceptRule  $rule  regla
     * @param  bool  $active  nuevo estado
     * @param  int|null  $userId  usuario
     */
    public function setActive(EstadoResultadosConceptRule $rule, bool $active, ?int $userId): EstadoResultadosConceptRule
    {
        if ($rule->active === $active) {
            return $rule;
        }

        return DB::transaction(function () use ($rule, $active, $userId) {
            $before = $this->snapshot($rule);
            $rule->forceFill(['active' => $active, 'updated_by' => $userId])->save();
            $this->log($rule, $active ? 'activada' : 'desactivada', $before, $this->snapshot($rule), $userId);

            return $rule;
        });
    }

    /**
     * Borrado lógico de una regla (registra quién la borró y la acción «borrada»).
     *
     * @param  EstadoResultadosConceptRule  $rule  regla
     * @param  int|null  $userId  usuario
     */
    public function delete(EstadoResultadosConceptRule $rule, ?int $userId): void
    {
        DB::transaction(function () use ($rule, $userId) {
            $before = $this->snapshot($rule);
            $rule->forceFill(['updated_by' => $userId])->save();
            $rule->delete();
            $this->log($rule, 'borrada', $before, null, $userId);
        });
    }

    /**
     * Presentación para la pantalla (etiquetas legibles incluidas).
     *
     * @param  EstadoResultadosConceptRule  $r  regla
     * @return array<string, mixed>
     */
    public function present(EstadoResultadosConceptRule $r): array
    {
        $fields = array_values((array) $r->fields);

        return [
            'id' => $r->id,
            'name' => $r->name,
            'match_text' => $r->match_text,
            'match_normalized' => $r->match_normalized,
            'match_mode' => $r->match_mode,
            'match_mode_label' => self::MODES[$r->match_mode] ?? $r->match_mode,
            'fields' => $fields,
            'fields_label' => implode(', ', array_map(fn ($f) => self::FIELDS[$f] ?? $f, $fields)),
            'account_slot' => $r->account_slot,
            'account_label' => $r->account_slot ? (EstadoResultadosUploadService::SLOTS[$r->account_slot] ?? $r->account_slot) : 'Cualquier cuenta',
            'direction' => $r->direction,
            'direction_label' => self::DIRECTIONS[$r->direction] ?? $r->direction,
            'treatment' => $r->treatment,
            'treatment_label' => Treatment::label($r->treatment),
            'line_label' => Treatment::affectsStatement($r->treatment) ? 'Afecta el Estado de Resultados' : 'Fuera del Estado de Resultados',
            'priority' => (int) $r->priority,
            'active' => (bool) $r->active,
            'notes' => $r->notes,
            'created_by' => $r->creator?->name,
            'updated_by' => $r->editor?->name,
            'created_at' => $r->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
            'updated_at' => $r->updated_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
        ];
    }

    /**
     * Opciones para el formulario (modos, campos, sentidos, cuentas y texto mínimo).
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        $pairs = fn (array $map) => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys($map), $map);

        return [
            'modes' => $pairs(self::MODES),
            'fields' => $pairs(self::FIELDS),
            'default_fields' => self::DEFAULT_FIELDS,
            'directions' => $pairs(self::DIRECTIONS),
            'accounts' => array_merge([['key' => '', 'label' => 'Cualquier cuenta']], $pairs(EstadoResultadosUploadService::SLOTS)),
            'min_text' => self::MIN_TEXT,
        ];
    }

    /**
     * Normaliza la entrada SIN exigir que esté completa (vista previa): recorta longitudes,
     * descarta valores fuera de catálogo (con sus valores por defecto) y acota la prioridad 0–1000.
     *
     * @param  array  $in  entrada cruda
     * @return array<string, mixed>
     */
    private function sanitize(array $in): array
    {
        $fields = array_values(array_intersect(array_keys(self::FIELDS), (array) ($in['fields'] ?? [])));
        $slot = (string) ($in['account_slot'] ?? '');
        $mode = (string) ($in['match_mode'] ?? 'contiene');
        $direction = (string) ($in['direction'] ?? 'cualquiera');
        $text = trim(preg_replace('/\s+/', ' ', (string) ($in['match_text'] ?? '')) ?? '');

        return [
            'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120),
            'match_text' => mb_substr($text, 0, 200),
            'match_normalized' => mb_substr(self::normalize($text), 0, 200),
            'match_mode' => isset(self::MODES[$mode]) ? $mode : 'contiene',
            'fields' => $fields !== [] ? $fields : self::DEFAULT_FIELDS,
            'account_slot' => isset(EstadoResultadosUploadService::SLOTS[$slot]) ? $slot : null,
            'direction' => isset(self::DIRECTIONS[$direction]) ? $direction : 'cualquiera',
            'treatment' => (string) ($in['treatment'] ?? ''),
            'priority' => max(0, min(1000, (int) ($in['priority'] ?? 100))),
            'active' => array_key_exists('active', $in) ? (bool) $in['active'] : true,
            'notes' => ($n = trim((string) ($in['notes'] ?? ''))) !== '' ? mb_substr($n, 0, 2000) : null,
        ];
    }

    /**
     * Entrada completa y válida para guardar (mensajes en español).
     *
     * @param  array  $in  entrada cruda
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException nombre < 3, texto < MIN_TEXT o tratamiento no asignable
     */
    private function validated(array $in): array
    {
        $data = $this->sanitize($in);
        if (mb_strlen($data['name']) < 3) {
            throw new \InvalidArgumentException('Escribe el nombre del concepto del reporte (por ejemplo «Renta de oficinas»).');
        }
        if (mb_strlen($data['match_normalized']) < self::MIN_TEXT) {
            throw new \InvalidArgumentException('El texto a buscar debe tener al menos '.self::MIN_TEXT.' caracteres para no atrapar movimientos de más.');
        }
        if (! Treatment::isAssignable($data['treatment'])) {
            throw new \InvalidArgumentException('Elige a dónde va el movimiento (tratamiento) de la lista.');
        }

        return $data;
    }

    /**
     * Regla del modelo → arreglo compilado para el clasificador (texto normalizado y valores
     * por defecto para modo, campos y sentido).
     *
     * @param  EstadoResultadosConceptRule  $r  regla
     * @return array<string, mixed>
     */
    private function compile(EstadoResultadosConceptRule $r): array
    {
        return [
            'id' => (int) $r->id, 'name' => (string) $r->name, 'text' => (string) $r->match_text,
            'norm' => self::normalize($r->match_normalized ?: $r->match_text), 'mode' => isset(self::MODES[$r->match_mode]) ? $r->match_mode : 'contiene',
            'fields' => array_values(array_intersect(array_keys(self::FIELDS), (array) $r->fields)) ?: self::DEFAULT_FIELDS,
            'account_slot' => $r->account_slot ?: null, 'direction' => isset(self::DIRECTIONS[$r->direction]) ? $r->direction : 'cualquiera',
            'treatment' => (string) $r->treatment, 'priority' => (int) $r->priority,
        ];
    }

    /**
     * Foto de los campos editables de una regla para la bitácora.
     *
     * @param  EstadoResultadosConceptRule  $r  regla
     * @return array<string, mixed>
     */
    private function snapshot(EstadoResultadosConceptRule $r): array
    {
        return $r->only(['name', 'match_text', 'match_mode', 'fields', 'account_slot', 'direction', 'treatment', 'priority', 'active', 'notes']);
    }

    /**
     * Inserta un evento en la bitácora append-only del catálogo.
     *
     * @param  EstadoResultadosConceptRule  $rule  regla
     * @param  string  $action  creada | editada | activada | desactivada | borrada
     * @param  array|null  $before  estado anterior
     * @param  array|null  $after  estado nuevo
     * @param  int|null  $userId  usuario
     */
    private function log(EstadoResultadosConceptRule $rule, string $action, ?array $before, ?array $after, ?int $userId): void
    {
        EstadoResultadosConceptRuleLog::query()->create([
            'rule_id' => $rule->id, 'action' => $action, 'before' => $before, 'after' => $after, 'user_id' => $userId,
        ]);
    }
}
