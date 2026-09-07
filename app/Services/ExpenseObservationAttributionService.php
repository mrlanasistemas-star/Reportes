<?php

namespace App\Services;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Atribuye gastos OPEX del Excel de Gastos Lendus (gastos_lendus_excel) al
 * colaborador BENEFICIARIO real, usando las columnas Observación/Justificación
 * — que ya llegan concatenadas en fact_expenses.observations (' | ') — en vez de
 * confiar en la columna "Empleado" del archivo (que representa a quien
 * captura/administra el gasto desde la sucursal, NO siempre al beneficiario).
 *
 * PROBLEMA REAL (auditoría 27-ago-2026): GastosExcelBranchResolverService copia
 * employee_id/branch_id desde la fila espejo del PDF cuando existe (mismo monto +
 * fecha) — y ese employee_id del PDF viene, a su vez, de la MISMA columna
 * "Empleado" cruda (GastosLendusPdfImportService::resolveEmployee(), match
 * EXACTO sin verificar semántica de beneficiario). Para conceptos como RECARGAS
 * TELEFONICAS/GASTOS EMERGENTES, eso deja el gasto atribuido al administrador de
 * sucursal, no al colaborador real — y por eso el OPEX por colaborador salía en
 * $0/incorrecto en Web/Excel/PDF (ver RadiographySnapshotBuilder::
 * buildEmployeesGestores()::$expensesByNorm, que SÍ suma cualquier fact_expenses
 * con employee_id poblado, sin importar de dónde salió ese employee_id).
 *
 * ESTE SERVICIO NO CAMBIA QUÉ ES OPEX, NO TOCA amount/category/concept, NO borra
 * ni duplica gastos — solo re-resuelve employee_id/branch_id (las mismas
 * columnas que ya representan "beneficiario"/"su sucursal" en todo el pipeline)
 * cuando Observación/Justificación dan evidencia MÁS confiable que la propagada
 * desde el PDF. Deja intacto el tratamiento especializado de PAGO FINANCIAMIENTO
 * MOTO/COMPRA DE CASCOS (excluidos aquí — ya los resuelve
 * FinanciamientoMotosAssignmentService con su propio motor idéntico).
 *
 * Nunca inventa persona: solo atribuye con evidencia fuerte (alias confirmado,
 * nombre exacto, o fuzzy con margen amplio) contra el ROSTER VÁLIDO del periodo
 * (PeriodEmployeeRosterService) — nunca contra Employee::all(). Ambigüedad o
 * conflicto entre Observación y Justificación → NEEDS_REVIEW, nunca auto-asigna.
 *
 * ================================================================================
 * AUDITORÍA 07-sep-2026 — MATCHING POR CONTENCIÓN, NO POR CADENA COMPLETA
 * ================================================================================
 * El motor original (hasta 06-sep-2026) comparaba el TEXTO COMPLETO de la celda
 * contra el NOMBRE COMPLETO del roster — igualdad exacta o similar_text() de las
 * dos cadenas enteras. Eso fallaba en el caso real más común: una Observación con
 * ruido alrededor del nombre ("RECARGA TELEFONICA PARA ALBERTO FLORENTINO BRAVO
 * BRAVO DEL MES") diluye el score de similar_text muy por debajo del piso de
 * ambigüedad (75%), porque compara longitudes completas, no substrings.
 *
 * matchAgainstRoster() ahora sigue, en orden, EXACTAMENTE los 5 niveles pedidos:
 *   A) Normalización (PersonIdentityResolverService::normalizePersonName — ya
 *      correcta: Str::ascii() + minúsculas + solo alfanumérico + espacios).
 *   B) Alias confirmado — contención de secuencia de tokens (con límites de
 *      palabra), no igualdad de cadena completa.
 *   C) Nombre completo del roster contenido en el texto — misma contención por
 *      tokens.
 *   D) Combinaciones parciales de nombre (primer nombre+apellidos, nombre
 *      compuesto+apellidos, etc.) — SOLO si esa combinación identifica a UNA
 *      SOLA persona en TODO el roster del periodo (índice construido sobre el
 *      roster completo, no solo el candidato evaluado).
 *   C+D están unificados en un solo paso (buscar combinaciones, de la más larga
 *   -más específica- a la más corta, en el índice global de combinaciones) — la
 *   combinación más larga que matchea y es única gana; si dos identidades
 *   distintas empatan en especificidad, es ambiguo.
 *   E) Fuzzy — ÚLTIMO recurso, y por VENTANA de tokens del texto (no cadena
 *      completa contra cadena completa) para que el ruido alrededor del nombre
 *      ya no diluya el score. Mismos umbrales ya validados
 *      (FUZZY_ACCEPT_THRESHOLD=92, margen=8, piso ambiguo=75).
 *
 * Ninguna de estas reglas cambia isEligibleForAttribution(), la prioridad
 * Observación>Justificación, la detección de conflicto, ni el invariante de
 * monto — solo CÓMO se decide si un nombre está "en" el texto.
 */
class ExpenseObservationAttributionService
{
    /** Umbral fuzzy alto — esto corre sobre TODO el universo de OPEX (no un
     *  concepto acotado como motos/finiquito), así que exige más margen que el
     *  fuzzy_name (≥80%) usado en FinanciamientoMotosAssignmentService. */
    private const FUZZY_ACCEPT_THRESHOLD = 92.0;
    private const FUZZY_ACCEPT_MARGIN    = 8.0;
    /** Por debajo de esto ni se reporta como "ambiguo" — es simplemente texto no-persona. */
    private const AMBIGUOUS_FLOOR        = 75.0;
    /** Cuántos candidatos fuzzy se exponen en el diagnóstico (audit command). */
    private const MAX_DIAGNOSTIC_CANDIDATES = 5;

    public function __construct(
        private readonly PersonIdentityResolverService $personResolver,
        private readonly PeriodEmployeeRosterService $rosterService,
        private readonly BranchRadiographyCalculator $branchCalculator,
        private readonly OpexClassificationService $opexClassifier,
    ) {
    }

    /**
     * @return array<int, array{
     *   fact_expense_id:int, period_id:int, report_upload_id:int, category:string, concept:string,
     *   amount:float, raw_employee_name:?string, observation:?string, justification:?string,
     *   previous_employee_id:?int, previous_branch_id:?int,
     *   employee_id:?int, employee_name:?string, branch_id:?int, branch_name:?string,
     *   metodo:?string, confianza:float, fuente:?string, estado:string, changed:bool,
     *   candidates:array, reason:?string,
     * }>
     */
    public function attributeForPeriod(Period $period, array $dataIds, bool $dryRun = false): array
    {
        $lendusExcelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
        if (!$lendusExcelId) {
            return [];
        }

        $roster = $this->rosterService->rosterRowsForSelector($period);
        $rosterByEmployeeId = [];
        foreach ($roster['rows'] as $row) {
            $rosterByEmployeeId[$row['employee_id']] = $row;
        }

        if (empty($rosterByEmployeeId)) {
            return [];
        }

        // ── Índices de matching, construidos UNA vez para todo el periodo ──────
        // fullNameTokensById: employee_id => tokens normalizados del nombre completo.
        // comboIndex: "combinación de tokens" => [employee_id, ...] (sobre TODO el
        // roster) — una combinación solo es usable si tiene exactamente 1 dueño.
        $fullNameTokensById = [];
        $comboIndex = [];
        foreach ($rosterByEmployeeId as $eid => $row) {
            $tokens = $this->tokensOf($this->personResolver->normalizePersonName($row['name']));
            if (empty($tokens)) {
                continue;
            }
            $fullNameTokensById[$eid] = $tokens;
            foreach ($this->nameCombinations($tokens) as $combo) {
                $comboIndex[implode(' ', $combo)][] = $eid;
            }
        }

        $aliasIndex = DB::table('employee_aliases')
            ->whereIn('employee_id', array_keys($rosterByEmployeeId))
            ->pluck('employee_id', 'normalized_alias')
            ->all();

        $delegatedConcepts = array_map('mb_strtoupper', FinanciamientoMotosAssignmentService::CONCEPTS);

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusExcelId)
            ->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), $delegatedConcepts)
            // Auditoría 07-sep-2026 (cierre, sección 2): antes se excluía aquí
            // cualquier fila con observations NULL — dejándola completamente
            // fuera del universo auditado/atribuible. Una fila OPEX elegible sin
            // texto simplemente no puede resolver a una persona (splitObservations
            // de '' devuelve [null,null] más abajo), pero SIGUE evaluándose y cae
            // correctamente a 'branch_general' (si ya tiene sucursal resuelta) o
            // 'no_atribuible' — nunca desaparece del reporte de auditoría.
            ->select(
                'e.id', 'e.period_id', 'e.report_upload_id', 'e.category', 'e.concept',
                'e.employee_id', 'e.branch_id', 'e.observations', 'e.raw_payload',
                DB::raw("COALESCE(NULLIF(e.paid_amount,0), e.amount) as amount")
            )
            ->get()
            // PROBLEMA REAL detectado corriendo esto contra datos reales (Junio 2026):
            // sin este filtro, conceptos como NOMINA/PAGO DE IMSS/DEDUCCIONES/ANTICIPO DE
            // NOMINA (categoría 'Nómina y Capital Humano') se atribuían igual que un gasto
            // OPEX — pero BranchRadiographyCalculator::accumulateGastos() NUNCA los suma a
            // ningún KPI a propósito ("ya están cubiertos por NOI y por el archivo IMSS
            // oficial — sumarlos aquí también duplicaría el gasto", ver su comentario
            // inline). buildEmployeesGestores() NO filtra por categoría al sumar gastos por
            // employee_id — así que atribuir esas filas habría duplicado, a nivel
            // colaborador, dinero que el EBITDA de empleado ya cuenta vía $neto (NOI). Este
            // filtro replica EXACTAMENTE la misma exclusión que accumulateGastos() aplica a
            // nivel sucursal/general — nunca "qué es OPEX", solo A QUIÉN se atribuye.
            ->filter(fn ($row) => $this->isEligibleForAttribution((string) $row->category, (string) $row->concept))
            ->values();

        $operativeMap = $this->branchCalculator->buildBranchMap()['operative'];
        $results = [];

        $matchContext = [
            'rosterByEmployeeId'  => $rosterByEmployeeId,
            'aliasIndex'          => $aliasIndex,
            'comboIndex'          => $comboIndex,
            'fullNameTokensById'  => $fullNameTokensById,
        ];

        foreach ($rows as $row) {
            $result = $this->resolveRow($row, $matchContext, $operativeMap);

            if ($result['changed'] && !$dryRun) {
                DB::table('fact_expenses')->where('id', $row->id)->update([
                    'employee_id'               => $result['employee_id'],
                    'branch_id'                 => $result['branch_id'],
                    'attribution_method'        => $result['metodo'],
                    'attribution_confidence'    => $result['confianza'] ?: null,
                    'attribution_source'        => $result['fuente'],
                    'attribution_needs_review'  => $result['estado'] === 'conflicto' || $result['estado'] === 'ambiguo',
                    'updated_at'                => now(),
                ]);
            } elseif (!$dryRun && in_array($result['estado'], ['conflicto', 'ambiguo'], true)) {
                // No toca employee_id/branch_id — solo deja constancia de la revisión pendiente.
                DB::table('fact_expenses')->where('id', $row->id)->update([
                    'attribution_method'       => $result['metodo'],
                    'attribution_confidence'   => $result['confianza'] ?: null,
                    'attribution_source'       => $result['fuente'],
                    'attribution_needs_review' => true,
                    'updated_at'               => now(),
                ]);
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Auditoría 07-sep-2026 (cierre) — delega en OpexClassificationService, la
     * FUENTE ÚNICA de clasificación financiera (antes esto era una lista manual
     * duplicada de la que vive en BranchRadiographyCalculator::accumulateGastos(),
     * con el riesgo explícito de que divergieran). Ningún criterio cambió: mismo
     * resultado que antes para cada categoría/concepto — ver
     * tests/Unit/OpexClassificationServiceTest.php.
     */
    private function isEligibleForAttribution(string $category, string $concept): bool
    {
        return $this->opexClassifier
            ->classify($category, $concept, OpexClassificationService::SOURCE_LENDUS)['eligible_for_attribution'];
    }

    private function resolveRow(object $row, array $matchContext, array $operativeMap): array
    {
        [$obsText, $justText] = $this->splitObservations((string) $row->observations);

        $obsMatch  = $obsText  !== null ? $this->matchAgainstRoster($obsText, $matchContext) : null;
        $justMatch = $justText !== null ? $this->matchAgainstRoster($justText, $matchContext) : null;

        $rawEmployeeName = is_array($row->raw_payload)
            ? ($row->raw_payload['solicitante'] ?? null)
            : (($decoded = json_decode((string) $row->raw_payload, true)) ? ($decoded['solicitante'] ?? null) : null);

        $base = [
            'fact_expense_id'      => $row->id,
            'period_id'            => $row->period_id,
            'report_upload_id'     => $row->report_upload_id,
            'category'             => (string) $row->category,
            'concept'              => (string) $row->concept,
            'amount'               => (float) $row->amount,
            'raw_employee_name'    => $rawEmployeeName,
            'observation'          => $obsText,
            'justification'        => $justText,
            'previous_employee_id' => $row->employee_id,
            'previous_branch_id'   => $row->branch_id,
        ];

        // CONFLICTO: ambos campos resuelven a personas VÁLIDAS y DISTINTAS — nunca
        // se elige arbitrariamente entre ellas.
        if (
            $obsMatch && $justMatch
            && ($obsMatch['employee_id'] ?? null) && ($justMatch['employee_id'] ?? null)
            && $obsMatch['employee_id'] !== $justMatch['employee_id']
        ) {
            return $base + [
                'employee_id' => null, 'employee_name' => null, 'branch_id' => null, 'branch_name' => null,
                'metodo' => 'conflict', 'confianza' => 0.0, 'fuente' => null,
                'estado' => 'conflicto', 'changed' => false,
                'candidates' => [
                    ['employee_id' => $obsMatch['employee_id'], 'name' => $matchContext['rosterByEmployeeId'][$obsMatch['employee_id']]['name'] ?? null, 'fuente' => 'observation'],
                    ['employee_id' => $justMatch['employee_id'], 'name' => $matchContext['rosterByEmployeeId'][$justMatch['employee_id']]['name'] ?? null, 'fuente' => 'justification'],
                ],
                'reason' => 'Observación y Justificación nombran personas distintas.',
            ];
        }

        $winner = null;
        $source = null;
        if ($obsMatch && ($obsMatch['employee_id'] ?? null)) {
            $winner = $obsMatch;
            $source = 'observation';
        } elseif ($justMatch && ($justMatch['employee_id'] ?? null)) {
            $winner = $justMatch;
            $source = 'justification';
        }

        if ($winner === null) {
            // Ninguno resolvió con confianza — ¿alguno fue "candidato pero ambiguo"?
            $ambiguousSide = null;
            if ($obsMatch && ($obsMatch['method'] ?? null) === 'ambiguous') {
                $ambiguousSide = ['match' => $obsMatch, 'fuente' => 'observation'];
            } elseif ($justMatch && ($justMatch['method'] ?? null) === 'ambiguous') {
                $ambiguousSide = ['match' => $justMatch, 'fuente' => 'justification'];
            }

            if ($ambiguousSide !== null) {
                return $base + [
                    'employee_id' => null, 'employee_name' => null, 'branch_id' => null, 'branch_name' => null,
                    'metodo' => 'ambiguous', 'confianza' => $ambiguousSide['match']['confidence'] ?? 0.0,
                    'fuente' => $ambiguousSide['fuente'],
                    'estado' => 'ambiguo', 'changed' => false,
                    'candidates' => $ambiguousSide['match']['candidates'] ?? [],
                    'reason' => $ambiguousSide['match']['reason'] ?? 'Más de un candidato posible, sin margen suficiente para decidir.',
                ];
            }

            $reason = $obsMatch['reason'] ?? $justMatch['reason'] ?? 'El texto no coincide con ningún colaborador del roster del periodo.';

            // ── branch_general (auditoría 07-sep-2026, cierre) ──────────────────
            // Ningún colaborador identificado, pero el gasto YA tiene una sucursal
            // operativa resuelta (GastosExcelBranchResolverService la garantiza
            // antes de que este servicio corra — nunca se inventa aquí). Un gasto
            // general de sucursal (renta, luz, agua, internet de oficina, limpieza,
            // vigilancia...) NO es un error de atribución — es un destino válido
            // por diseño. Solo cae a 'no_atribuible' cuando NI SIQUIERA hay
            // sucursal resuelta (verificado contra datos reales: no ocurre hoy,
            // pero se conserva como salvaguarda).
            $branchIsOperative = $row->branch_id && isset($operativeMap[(int) $row->branch_id]);
            if ($branchIsOperative) {
                return $base + [
                    'employee_id' => null, 'employee_name' => null,
                    'branch_id'   => (int) $row->branch_id,
                    'branch_name' => $operativeMap[(int) $row->branch_id] ?? null,
                    'metodo' => null, 'confianza' => 0.0, 'fuente' => null,
                    'estado' => 'branch_general', 'changed' => false,
                    'candidates' => [], 'reason' => 'Sin colaborador identificable — gasto general de la sucursal ya resuelta.',
                ];
            }

            return $base + [
                'employee_id' => null, 'employee_name' => null, 'branch_id' => null, 'branch_name' => null,
                'metodo' => null, 'confianza' => 0.0, 'fuente' => null,
                'estado' => 'no_atribuible', 'changed' => false,
                'candidates' => [], 'reason' => $reason,
            ];
        }

        $winnerEmployeeId = $winner['employee_id'];
        $rosterRow        = $matchContext['rosterByEmployeeId'][$winnerEmployeeId];

        // Sucursal atribuida = la HISTÓRICA del colaborador para ESTE periodo (ya
        // resuelta por PeriodEmployeeRosterService vía employee_branch_assignments,
        // con fallback a la asignación histórica más reciente — nunca employees.
        // branch_id actual). Solo se acepta si es una de las 13 sucursales oficiales;
        // si no, se conserva el branch_id que ya tenía la fila (nunca se inventa ni
        // se blanquea sucursal).
        $branchId   = ($rosterRow['branch_id'] && $rosterRow['is_branch_operativa']) ? (int) $rosterRow['branch_id'] : $row->branch_id;
        $branchName = ($rosterRow['branch_id'] && $rosterRow['is_branch_operativa'])
            ? ($operativeMap[$branchId] ?? $rosterRow['branch_name'])
            : ($row->branch_id ? ($operativeMap[$row->branch_id] ?? null) : null);

        $changed = ((int) $row->employee_id !== $winnerEmployeeId) || ((int) $row->branch_id !== (int) $branchId);

        return $base + [
            'employee_id'   => $winnerEmployeeId,
            'employee_name' => $rosterRow['name'],
            'branch_id'     => $branchId,
            'branch_name'   => $branchName,
            'metodo'        => $winner['method'],
            'confianza'     => $winner['confidence'],
            'fuente'        => $source,
            'estado'        => $changed ? 'atribuido' : 'ya_correcto',
            'changed'       => $changed,
            'candidates'    => [],
            'reason'        => null,
        ];
    }

    /**
     * "OBS | JUST" (ambos), "OBS" o "JUST" (solo uno) — reconstruye el formato EXACTO
     * usado por GastosLendusExcelImportService: implode(' | ', array_filter([obs, just])).
     * Con un único segmento no es posible saber si era observación o justificación —
     * no importa para la resolución (se intenta igual como "texto primario").
     */
    private function splitObservations(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, null];
        }
        if (str_contains($raw, ' | ')) {
            [$first, $rest] = explode(' | ', $raw, 2);
            $first = trim($first) !== '' ? trim($first) : null;
            $rest  = trim($rest) !== '' ? trim($rest) : null;
            return [$first, $rest];
        }
        return [$raw, null];
    }

    /** Tokeniza un nombre/texto YA normalizado (espacio simple, sin acentos/puntuación). */
    private function tokensOf(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }
        return array_values(array_filter(explode(' ', $normalized), fn ($t) => $t !== ''));
    }

    /**
     * Genera combinaciones identificadoras a partir de los tokens del nombre
     * completo de un colaborador — pensadas para nombres del patrón mexicano
     * (1-2 nombres de pila + 1-2 apellidos). Siempre incluye el nombre completo.
     * Para nombres de 4 tokens (2 nombres + 2 apellidos) agrega:
     *   - [nombre2, apellido1, apellido2]  ("nombre compuesto" recortado + ambos apellidos)
     *   - [nombre1, apellido1, apellido2]  (primer nombre + ambos apellidos)
     * Nunca genera combinaciones de un solo token (demasiado ambiguas) ni asume
     * qué token es apellido en nombres de 3 tokens (se deja solo el nombre
     * completo — ya suficientemente específico con 3 palabras).
     *
     * @return array<int, array<int, string>>
     */
    private function nameCombinations(array $tokens): array
    {
        $n = count($tokens);
        if ($n < 2) {
            return [];
        }

        $combos = [$tokens];
        if ($n >= 4) {
            $combos[] = array_slice($tokens, 1);                          // nombre(s) restante(s) + ambos apellidos
            $combos[] = array_merge([$tokens[0]], array_slice($tokens, 2)); // primer nombre + ambos apellidos
        }

        $seen = [];
        $unique = [];
        foreach ($combos as $combo) {
            $key = implode(' ', $combo);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $combo;
        }

        return $unique;
    }

    /** Contención de una secuencia de tokens dentro de otra, respetando límites de palabra. */
    private function tokensContain(array $haystackTokens, array $needleTokens): bool
    {
        if (empty($needleTokens)) {
            return false;
        }
        $haystack = ' ' . implode(' ', $haystackTokens) . ' ';
        $needle   = ' ' . implode(' ', $needleTokens) . ' ';
        return str_contains($haystack, $needle);
    }

    /** Umbral mínimo de similitud POR TOKEN dentro de la ventana fuzzy — evita que
     *  un nombre corto coincida por pura casualidad de caracteres dentro de una
     *  palabra más larga no relacionada (ej. "ANA" no debe "verse" dentro de
     *  "MARIANA" solo porque comparten 3 letras consecutivas: similar_text a nivel
     *  de CADENA COMPLETA sí encontraría ese substring y lo puntuaría alto, pero
     *  aquí cada token del nombre candidato debe tener su propio par plausible
     *  entre los tokens de la ventana de texto — "mariana" vs "ana" da ~60%,
     *  por debajo de este piso, así que el candidato queda descartado). */
    private const PER_TOKEN_FLOOR = 70.0;

    /**
     * Compara los tokens de un nombre candidato contra ventanas de tokens del
     * texto (tamaño = tokens del candidato ±1) — nunca cadena completa del texto
     * contra cadena completa del nombre (eso diluye el score con el ruido
     * alrededor). CADA token del candidato debe tener un par plausible
     * (≥PER_TOKEN_FLOOR) dentro de la ventana — si un solo token del candidato no
     * tiene ningún par plausible, esa ventana no cuenta para nada (protección
     * contra coincidencias de substring sin relación real, y contra "encontrar"
     * a alguien cuando falta una palabra completa de su nombre).
     */
    private function windowScoreForCandidate(array $textTokens, array $candidateTokens): float
    {
        $k = count($candidateTokens);
        $textCount = count($textTokens);
        $best = 0.0;

        foreach ([$k - 1, $k, $k + 1] as $winSize) {
            if ($winSize < 1 || $winSize > $textCount) {
                continue;
            }
            for ($i = 0; $i <= $textCount - $winSize; $i++) {
                $window = array_slice($textTokens, $i, $winSize);

                $sumBest = 0.0;
                $allTokensClearFloor = true;
                foreach ($candidateTokens as $candidateToken) {
                    $tokenBest = 0.0;
                    foreach ($window as $windowToken) {
                        similar_text($candidateToken, $windowToken, $pct);
                        if ($pct > $tokenBest) {
                            $tokenBest = $pct;
                        }
                    }
                    if ($tokenBest < self::PER_TOKEN_FLOOR) {
                        $allTokensClearFloor = false;
                        break;
                    }
                    $sumBest += $tokenBest;
                }

                if (!$allTokensClearFloor) {
                    continue;
                }

                $windowScore = $sumBest / count($candidateTokens);
                if ($windowScore > $best) {
                    $best = $windowScore;
                }
            }
        }

        return $best;
    }

    /**
     * Resuelve un texto libre (Observación o Justificación) contra el roster del
     * periodo, en el orden A→E documentado en el docblock de la clase.
     *
     * @return array{employee_id:?int,method:?string,confidence:float,candidates:array,reason:?string}|null
     *         null SOLO cuando el texto normalizado queda vacío (nada que evaluar).
     */
    private function matchAgainstRoster(string $text, array $matchContext): ?array
    {
        $normalized = $this->personResolver->normalizePersonName($text);
        if ($normalized === '') {
            return null;
        }

        $textTokens = $this->tokensOf($normalized);
        $rosterByEmployeeId = $matchContext['rosterByEmployeeId'];
        $fullNameTokensById = $matchContext['fullNameTokensById'];

        // ── B) Alias confirmado — RECOLECTAR TODOS los alias contenidos en el texto
        // antes de decidir (auditoría 07-sep-2026, cierre): el orden en que MySQL/
        // el índice PHP devuelve las filas de employee_aliases nunca debe decidir
        // una persona. Si el texto contiene el alias de MÁS de un colaborador
        // distinto ("PAGO PARA JUAN PEREZ Y MARIA LOPEZ", ambos alias válidos) →
        // ambiguo, nunca se asigna al primero que aparezca en la iteración.
        $aliasHits = []; // employee_id => true (deduplicado)
        foreach ($matchContext['aliasIndex'] as $normalizedAlias => $employeeId) {
            $aliasTokens = $this->tokensOf((string) $normalizedAlias);
            if (count($aliasTokens) < 1) {
                continue;
            }
            if ($this->tokensContain($textTokens, $aliasTokens)) {
                $aliasHits[(int) $employeeId] = true;
            }
        }
        if (count($aliasHits) === 1) {
            $eid = array_key_first($aliasHits);
            return ['employee_id' => $eid, 'method' => 'alias', 'confidence' => 1.0, 'candidates' => [], 'reason' => null];
        }
        if (count($aliasHits) > 1) {
            $candidates = array_map(fn ($eid) => [
                'employee_id' => $eid,
                'name'        => $rosterByEmployeeId[$eid]['name'] ?? null,
                'score'       => 100.0,
                'method'      => 'alias_shared',
            ], array_keys($aliasHits));

            return [
                'employee_id' => null, 'method' => 'ambiguous', 'confidence' => 1.0,
                'candidates'  => array_slice($candidates, 0, self::MAX_DIAGNOSTIC_CANDIDATES),
                'reason'      => 'El texto contiene el alias de más de un colaborador distinto.',
            ];
        }

        // ── C+D) Nombre completo / combinaciones parciales únicas del roster ─────
        // Recorre TODAS las combinaciones (propias del roster completo) que
        // aparecen como secuencia contigua en el texto. Las que identifican UNA
        // sola persona en todo el roster son usables (gana la más específica —
        // más tokens). Las que aparecen en el texto pero son compartidas por ≥2
        // identidades distintas son evidencia de AMBIGÜEDAD explícita (regla D:
        // "solo aceptar si esa combinación identifica a una sola persona" — si no
        // la identifica, es ambiguo, no simplemente descartado en silencio).
        $bestLenByEmployee = []; // employee_id => longitud de la combinación más larga que matcheó (única)
        $sharedComboHit = null;  // combinación más específica que matcheó pero es compartida por >1 identidad
        foreach ($matchContext['comboIndex'] as $comboKey => $ownerIds) {
            $comboTokens = $this->tokensOf($comboKey);
            if (!$this->tokensContain($textTokens, $comboTokens)) {
                continue;
            }
            $uniqueOwners = array_values(array_unique($ownerIds));
            $len = count($comboTokens);
            if (count($uniqueOwners) !== 1) {
                if ($sharedComboHit === null || $len > $sharedComboHit['len']) {
                    $sharedComboHit = ['len' => $len, 'owners' => $uniqueOwners];
                }
                continue;
            }
            $eid = $uniqueOwners[0];
            if (!isset($bestLenByEmployee[$eid]) || $len > $bestLenByEmployee[$eid]) {
                $bestLenByEmployee[$eid] = $len;
            }
        }

        if (!empty($bestLenByEmployee)) {
            $maxLen = max($bestLenByEmployee);
            $winners = array_keys(array_filter($bestLenByEmployee, fn ($len) => $len === $maxLen));

            if (count($winners) === 1) {
                $eid     = $winners[0];
                $fullLen = count($fullNameTokensById[$eid] ?? []);
                $isFull  = $fullLen > 0 && $maxLen >= $fullLen;
                return [
                    'employee_id' => $eid,
                    'method'      => $isFull ? 'full_name_in_text' : 'partial_name_unique',
                    'confidence'  => $isFull ? 1.0 : 0.95,
                    'candidates'  => [],
                    'reason'      => null,
                ];
            }

            // Dos (o más) identidades distintas igual de específicas dentro del
            // mismo texto — nunca se elige arbitrariamente.
            $candidates = array_map(fn ($eid) => [
                'employee_id' => $eid,
                'name'        => $rosterByEmployeeId[$eid]['name'] ?? null,
                'score'       => 100.0,
                'method'      => 'combo',
            ], $winners);

            return [
                'employee_id' => null, 'method' => 'ambiguous', 'confidence' => 1.0,
                'candidates'  => array_slice($candidates, 0, self::MAX_DIAGNOSTIC_CANDIDATES),
                'reason'      => 'Más de un colaborador del roster comparte la misma combinación de nombre encontrada en el texto.',
            ];
        }

        if ($sharedComboHit !== null) {
            // La combinación más específica encontrada en el texto SÍ identifica
            // personas del roster, pero más de una — nunca se asigna al azar.
            $candidates = array_map(fn ($eid) => [
                'employee_id' => $eid,
                'name'        => $rosterByEmployeeId[$eid]['name'] ?? null,
                'score'       => 100.0,
                'method'      => 'combo_shared',
            ], $sharedComboHit['owners']);

            return [
                'employee_id' => null, 'method' => 'ambiguous', 'confidence' => 1.0,
                'candidates'  => array_slice($candidates, 0, self::MAX_DIAGNOSTIC_CANDIDATES),
                'reason'      => 'La combinación de nombre encontrada en el texto es compartida por más de un colaborador del roster (ninguno se elige arbitrariamente).',
            ];
        }

        // ── E) Fuzzy — último recurso, por VENTANA de tokens del texto ───────────
        // Pre-filtro barato: exige ≥2 tokens de ≥3 caracteres — descarta rápido
        // texto operativo tipo "RECARGA DE EXTINTOR"/"SEMANA 23" sin recorrer el
        // roster completo por cada fila.
        $significantTokens = array_values(array_filter($textTokens, fn ($t) => mb_strlen($t) >= 3));
        if (count($significantTokens) < 2) {
            return [
                'employee_id' => null, 'method' => null, 'confidence' => 0.0, 'candidates' => [],
                'reason' => 'Texto sin al menos 2 palabras significativas (≥3 caracteres) — no parece contener un nombre de persona.',
            ];
        }

        $scored = []; // employee_id => best window score
        foreach ($rosterByEmployeeId as $eid => $rosterRow) {
            $candidateTokens = $fullNameTokensById[$eid] ?? [];
            if (empty($candidateTokens)) {
                continue;
            }
            $best = $this->windowScoreForCandidate($textTokens, $candidateTokens);
            if ($best > 0.0) {
                $scored[$eid] = $best;
            }
        }

        if (empty($scored)) {
            return [
                'employee_id' => null, 'method' => null, 'confidence' => 0.0, 'candidates' => [],
                'reason' => 'Ningún colaborador del roster tiene similitud detectable con el texto.',
            ];
        }

        arsort($scored);
        $bestEid   = array_key_first($scored);
        $bestScore = $scored[$bestEid];
        $secondScore = 0.0;
        $i = 0;
        foreach ($scored as $eid => $score) {
            if ($i === 1) {
                $secondScore = $score;
                break;
            }
            $i++;
        }

        $topCandidates = [];
        $i = 0;
        foreach ($scored as $eid => $score) {
            if ($i >= self::MAX_DIAGNOSTIC_CANDIDATES) {
                break;
            }
            $topCandidates[] = ['employee_id' => $eid, 'name' => $rosterByEmployeeId[$eid]['name'] ?? null, 'score' => round($score, 1), 'method' => 'fuzzy_window'];
            $i++;
        }

        if ($bestScore >= self::FUZZY_ACCEPT_THRESHOLD && ($bestScore - $secondScore) >= self::FUZZY_ACCEPT_MARGIN) {
            return ['employee_id' => $bestEid, 'method' => 'fuzzy_name', 'confidence' => round($bestScore / 100, 2), 'candidates' => [], 'reason' => null];
        }

        if ($bestScore >= self::AMBIGUOUS_FLOOR) {
            // Candidato real pero sin confianza/margen suficiente — reportado como
            // ambiguo, nunca auto-asignado.
            $bestName = $rosterByEmployeeId[$bestEid]['name'] ?? '?';
            return [
                'employee_id' => null, 'method' => 'ambiguous', 'confidence' => round($bestScore / 100, 2),
                'candidates'  => $topCandidates,
                'reason'      => sprintf(
                    'Mejor coincidencia difusa: "%s" (%.1f%%)%s — sin margen suficiente sobre el umbral de %.0f%%.',
                    $bestName,
                    $bestScore,
                    $secondScore > 0 ? sprintf(', 2do lugar %.1f%%', $secondScore) : '',
                    self::FUZZY_ACCEPT_THRESHOLD
                ),
            ];
        }

        return [
            'employee_id' => null, 'method' => null, 'confidence' => 0.0, 'candidates' => [],
            'reason' => sprintf('Mejor coincidencia difusa (%.1f%%) por debajo del piso mínimo de %.0f%%.', $bestScore, self::AMBIGUOUS_FLOOR),
        ];
    }
}
