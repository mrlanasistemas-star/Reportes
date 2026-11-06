/**
 * Tipos y utilidades del módulo Estado de Resultados.
 * El dataset lo construye EstadoResultadosDatasetBuilder (fuente única de verdad);
 * la UI sólo da formato — nunca recalcula cifras.
 */

export type SourceStatus =
    | 'pendiente'
    | 'cargado'
    | 'procesado'
    | 'con_incidencias';

export interface StatementLine {
    key: string;
    label: string;
    amount: string;
    pct: string | null;
    source: string;
    hint: string | null;
    state: string | null;
    /** Próximo contrato: detalle oculto por permisos (nómina / DG). */
    redacted?: boolean;
    redacted_reason?: string | null;
}

export interface StatementSection {
    type: 'section';
    key: string;
    number: string;
    title: string;
    kind: string;
    applies: boolean;
    note: string | null;
    lines: StatementLine[];
    total: { label: string; amount: string; pct: string | null };
    subtotals?: Array<{ label: string; amount: string }>;
    /** Detalle oculto por permisos (nómina / DG): sólo se ve el total. */
    restricted?: boolean;
}

export interface StatementResult {
    type: 'result';
    key: string;
    label: string;
    amount: string;
    pct: string | null;
    formula: string;
    level: number;
    complete: boolean;
    missing: string[];
}

export type StatementBlock = StatementSection | StatementResult;

export interface WaterfallStep {
    key: string;
    label: string;
    amount: string;
    type: 'total' | 'delta' | 'subtotal';
    level: number;
}

export interface ErpMatch {
    fingerprint: string;
    id: number;
    folio: string | null;
    amount: string;
    date: string | null;
    date_source: string;
    branch_name: string | null;
    universe: string;
    concept: string | null;
    category: string | null;
    delta_days?: number;
}

export interface Override {
    treatment: string;
    previous: string | null;
    notes: string;
    user: string | null;
    at: string | null;
    history_count: number;
}

export interface Movement {
    fingerprint: string;
    source: 'banco' | 'erp';
    account_slot: string;
    account_label: string | null;
    file: string | null;
    source_row: number;
    date: string | null;
    reference: string | null;
    folio: string | null;
    uuid: string | null;
    beneficiary: string | null;
    concept: string | null;
    category: string | null;
    observations: string | null;
    sucursal_raw: string | null;
    branch_name: string | null;
    branch_kind: string;
    cargo: string;
    abono: string;
    in_period: boolean;
    erp: ErpMatch[];
    reconciliation_status: string;
    reconciliation_message: string | null;
    reconciliation_label: string;
    auto_treatment: string;
    suggested_treatment: string | null;
    suggested_label: string | null;
    rule_code: string | null;
    rule_reason: string | null;
    treatment: string;
    treatment_label: string;
    treatment_group: string;
    treatment_explain: string;
    /** manual | regla | reembolso */
    treatment_source: string;
    override: Override | null;
    needs_review: boolean;
    transfer_pair: string | null;
    detail_lines: number;
    linked_bank: Array<{
        row: number;
        account: string;
        amount: string;
        date: string;
    }>;
    impact: {
        included: boolean;
        line: string | null;
        amount: string;
        text: string;
    };
    /** Renglón anonimizado por permisos. */
    redacted?: boolean;
    // Trazabilidad (06-nov-2026). Opcionales: snapshots anteriores no las traen.
    dimensions?: MovementDimensions;
    dates?: MovementDates;
    amounts?: MovementAmounts;
    links?: MovementLink[];
    applied?: string;
    match_type?: string | null;
    match_group?: string | null;
    reversal_pair?: string | null;
    refund_of?: string | null;
    refund_status?: string | null;
    erp_payment?: { amount: string; applied: string; pending: string };
    erp_payment_status?: string;
    reconciliation_delta_days?: number | null;
}

export interface KeyLabel {
    key: string | null;
    label: string;
}

export interface MovementDimensions {
    import: KeyLabel;
    reconciliation: KeyLabel;
    category: KeyLabel;
    accounting: KeyLabel;
    review: KeyLabel;
    inclusion: KeyLabel;
    source: KeyLabel;
    recognition: KeyLabel & {
        period_id?: number | null;
        period_code: string | null;
        basis?: string;
        basis_label: string;
        out_of_period?: string | null;
        out_of_period_label: string | null;
    };
}

export interface MovementDates {
    operation: string | null;
    bank: string | null;
    erp_capture: string | null;
    erp_payment: string | null;
    accrual: string | null;
}

/** Conciliado + reconocido + excluido + pendiente + fuera de periodo = bruto. */
export interface MovementAmounts {
    gross: string;
    conciliated: string;
    recognized: string;
    excluded: string;
    pending: string;
    out_of_period: string;
    classifiable: string;
}

export interface MovementLink {
    kind: string;
    kind_label: string;
    /** banco | erp | noi */
    target_type: string;
    target: string | null;
    match_type: string | null;
    group_key?: string | null;
    applied: string;
    movement_pending: string;
    target_pending: string;
    delta_days: number | null;
    evidence: Record<string, unknown> | unknown[];
    redacted?: boolean;
}

export interface TreatmentMeta {
    key: string;
    label: string;
    group: string;
    line: string | null;
    tone: string;
    explain: string;
    assignable: boolean;
}

export type AdjustmentState =
    | 'sin_informacion'
    | 'confirmado_cero'
    | 'capturado';

export interface Adjustment {
    key: string;
    label: string;
    section: string;
    effect: string;
    affects: boolean;
    state: AdjustmentState;
    amount: string | null;
    notes: string | null;
    updated_by: string | null;
    updated_at: string | null;
}

export interface Check {
    key: string;
    label: string;
    expected: string;
    actual: string;
    difference: string;
    ok: boolean;
    explain: string | null;
}

export interface Indicator {
    key: string;
    label: string;
    value: string | null;
    unit: 'MXN' | '%' | 'n' | 'txt';
}

export interface CountAmount {
    count: number;
    amount: string;
}

export interface AccountSource {
    key: string;
    label: string;
    status: SourceStatus;
    upload: null | {
        id: number;
        name: string;
        account: string | null;
        hash: string;
        movements: number;
        detail_lines: number;
        out_of_period: number;
        total_cargos: string;
        total_abonos: string;
        control_ok: boolean | null;
        warnings: string[];
        first_date: string | null;
        last_date: string | null;
        uploaded_by: string | null;
        uploaded_at: string | null;
    };
    pending_review: number;
    history: Array<{
        id: number;
        name: string;
        status: string;
        hash: string;
        movements: number;
        uploaded_by: string | null;
        uploaded_at: string | null;
    }>;
}

export interface SimpleSource {
    label: string;
    status: SourceStatus;
    detail: string;
    count?: number;
}

export interface ProcessingSource {
    label: string;
    status: SourceStatus;
    last_run: null | {
        status: string;
        user: string | null;
        at: string | null;
        summary: Record<string, unknown> | null;
        error: string | null;
        rules_version: string;
    };
    stale_erp: boolean;
    unprocessed: boolean;
}

export interface Dataset {
    meta: {
        period: {
            id: number;
            code: string;
            label: string;
            start: string;
            end: string;
        };
        scope: { key: string; label: string; consolidated: boolean };
        currency: string;
        rules_version: string;
        tolerance_days: number;
        generated_at: string;
        dataset_hash: string;
        report?: ReportMeta;
        redactions?: Redactions;
        recognition?: {
            version: string;
            criterio: string;
            reglas: Record<string, string>;
        };
    };
    results: Record<string, string>;
    margins: Record<string, string | null>;
    completeness: Record<string, { complete: boolean; missing: string[] }>;
    statement: StatementBlock[];
    waterfall: WaterfallStep[];
    radiography_reconciliation: {
        er_ebitda_sucursales: string;
        radiografia_ebitda: string;
        er_ebitda_base_radiografia: string;
        difference: string;
        difference_base: string;
        ok: boolean;
        base_ok: boolean;
        explanations: Array<{ label: string; amount: string }>;
        informative: string[];
        components: Array<{ label: string; er: string; radiografia: string }>;
    };
    checks: Check[];
    conciliation: {
        movimientos_leidos: number;
        movimientos_en_periodo: number;
        fuera_periodo: number;
        total_cargos: string;
        total_abonos: string;
        candidatos: number;
        conciliados_erp: CountAmount;
        nuevos: CountAmount;
        transferencias: CountAmount;
        fuera_er: CountAmount;
        pendientes: CountAmount;
        incorporado_bancos: string;
        incorporado_erp_fuera_radiografia: string;
        erp_fuera_radiografia: CountAmount;
        por_tratamiento: Array<{
            key: string;
            label: string;
            group: string;
            count: number;
            cargos: string;
            abonos: string;
        }>;
        por_estado: Record<string, number>;
        por_tipo_emparejamiento?: Array<{
            key: string;
            count: number;
            applied: string;
        }>;
        parciales?: { count: number; excedente_pendiente: string };
        reversos?: CountAmount;
        reembolsos_vinculados?: CountAmount;
        erp_saldo_pendiente_pago?: string;
    };
    movements: Movement[];
    outside: {
        groups: Array<{
            key: string;
            label: string;
            explain: string;
            count: number;
            cargos: string;
            abonos: string;
        }>;
        manual_flows: Array<{
            key: string;
            label: string;
            state: string;
            amount: string | null;
            effect: string;
        }>;
    };
    indicators: { financial: Indicator[]; informative: Indicator[] };
    adjustments: Adjustment[];
    sources: {
        radiografia: SimpleSource;
        erp: SimpleSource;
        nomina: SimpleSource;
        cuentas: AccountSource[];
        ajustes: SimpleSource & { counts: Record<string, number> };
        procesamiento: ProcessingSource;
        exportaciones: Array<{
            format: string;
            scope: string;
            hash: string;
            user: string | null;
            at: string | null;
        }>;
    };
    treatments: TreatmentMeta[];
    reconciliation_labels: Record<string, string>;
    /** Opcional: snapshots aprobados antes del 06-nov-2026 no la traen. */
    validation?: Validation;
    dimensions?: {
        version?: string;
        inclusion?: Array<{ key: string; label: string }>;
        review?: Array<{ key: string; label: string }>;
        category?: Array<{ key: string; label: string }>;
        reconciliation?: Array<{ key: string; label: string }>;
        match_types?: Array<{ key: string; label: string }>;
        link_kinds?: Array<{ key: string; label: string }>;
    };
}

export type ReportStatusKey =
    | 'borrador'
    | 'pendiente_conciliacion'
    | 'listo_revision'
    | 'aprobado'
    | 'sustituido';

export interface ReportBlocker {
    /** pendientes | procesamiento | validaciones | datos_faltantes */
    code: string;
    message: string;
    scope?: string;
}

export interface VersionSummary {
    id: number;
    version: number;
    status: string;
    status_label: string;
    locked: boolean;
    rules_version: string | null;
    dataset_hash: string | null;
    snapshot_hash: string | null;
    scopes: string[];
    approved_by: string | null;
    approved_at: string | null;
    approval_notes?: string | null;
    reopened: { at: string; by: string | null; reason: string | null } | null;
    superseded_at: string | null;
    superseded_by_version_id?: number | null;
}

export interface VersionHistory {
    locked: boolean;
    current: VersionSummary | null;
    draft_version: number | null;
    versions: VersionSummary[];
    logs: Array<{
        id: number;
        action: string;
        action_label: string;
        version: number;
        reason: string | null;
        user: string | null;
        at: string | null;
    }>;
}

export interface ReportMeta {
    status: string;
    status_label: string;
    /** en_vivo | snapshot */
    source: string;
    version: number;
    version_id: number | null;
    locked: boolean;
    current: boolean;
    preliminary: boolean;
    preliminary_reasons: string[];
    blockers: ReportBlocker[];
    approvable: boolean;
    approved_by: string | null;
    approved_at: string | null;
    snapshot_hash: string | null;
    criterion: string;
    rules_version: string;
    generated_at: string;
    issued_at: string;
    reopened: { at: string; by: string | null; reason: string | null } | null;
    previous_version: VersionSummary | null;
    superseded_at: string | null;
}

export interface Redactions {
    nomina: boolean;
    dg: boolean;
    hidden_movements: { nomina: number; dg: number };
    redacted_references?: number;
    message: string | null;
}

export interface DiffItem {
    fingerprint: string | null;
    date: string | null;
    folio: string | null;
    concept: string | null;
    account_slot: string;
    bank_amount: string;
    erp_amounts: string[];
    difference: string | null;
    delta_days: number | null;
    status: string;
    status_label: string;
    message: string | null;
    out_of_period?: string | null;
    redacted?: boolean;
}

export interface DiffGroup {
    count: number;
    amount: string;
    items: DiffItem[];
}

export interface PartitionCheck {
    total: string;
    sum_parts: string;
    difference: string;
    ok: boolean;
}

export interface Validation {
    /** ok | con_pendientes | con_diferencias */
    status: string;
    scope_note: string | null;
    totals: {
        source: {
            count: number;
            cargos: string;
            abonos: string;
            total: string;
        };
        imported: {
            count: number;
            cargos: string;
            abonos: string;
            total: string;
        };
        erp_outside: { count: number; total: string };
        conciliated: CountAmount;
        recognized: CountAmount;
        excluded_justified: CountAmount;
        pending: CountAmount;
        out_of_period: CountAmount;
    };
    partition: {
        bank: PartitionCheck;
        erp: PartitionCheck;
        all: PartitionCheck;
    };
    differences: {
        by_amount: DiffGroup;
        by_date: DiffGroup;
        by_folio: DiffGroup;
    };
    radiography: {
        er: string;
        radiografia: string;
        difference: string;
        base_difference?: string;
        ok: boolean;
        base_ok?: boolean;
        explained: Array<{ label: string; amount: string }>;
    };
    integrity: {
        status: string;
        import_matches_source?: boolean;
        uploads: Array<{
            slot: string;
            file: string;
            hash: string;
            control_ok: boolean | null;
            control_cargos: string | null;
            control_abonos: string | null;
            read_cargos: string;
            read_abonos: string;
            movements: number;
        }>;
        erp_stale: boolean;
        rules_version_last_run?: string | null;
        recognition_version_last_run?: string | null;
    };
    eliminations: {
        transfers: { pairs: number; count: number; amount: string };
        unpaired_transfers: {
            count: number;
            amount: string;
            items: Array<{
                fingerprint: string | null;
                date: string | null;
                account_slot: string;
                cargo: string;
                abono: string;
                concept: string | null;
            }>;
        };
        internal_flows: CountAmount;
        reversals: { pairs: number; count: number; amount: string };
        corporate_allocation?: string;
    };
    noi: {
        employees: number;
        total: string;
        unassigned_total: string;
        unassigned_employees: number;
        linked_payments: CountAmount;
        recognizing_rule: string;
    };
    unresolved: Array<{
        key: string;
        label: string;
        count: number;
        amount: string;
    }>;
    failed_checks: Array<{ key: string; label: string; difference: string }>;
}

export interface Permissions {
    cargar: boolean;
    procesar: boolean;
    clasificar: boolean;
    ajustes: boolean;
    exportar: boolean;
    consultar?: boolean;
    conciliar?: boolean;
    reclasificar?: boolean;
    editar_ajustes?: boolean;
    aprobar?: boolean;
    ver_nomina?: boolean;
    ver_dg?: boolean;
}

export interface UploadRules {
    max_mb: number;
    extensions: string[];
    accept: string;
}

export interface RowError {
    row: number | null;
    column: string | null;
    message: string;
}

export interface UploadAttempt {
    id: number;
    slot: string;
    slot_label: string;
    file: string;
    file_hash: string | null;
    file_size: number;
    status: string;
    status_label: string;
    message: string | null;
    errors_count: number;
    errors: RowError[];
    upload_id: number | null;
    original_kept: boolean;
    user: string | null;
    at: string | null;
}

// ── Formato ────────────────────────────────────────────────────────────

const currency = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
});

export function mxn(v: string | number | null | undefined): string {
    if (v === null || v === undefined || v === '') {
        return '—';
    }

    return currency.format(Number(v));
}

export function compact(v: string | number | null | undefined): string {
    const n = Number(v ?? 0);
    const sign = n < 0 ? '-' : '';
    const abs = Math.abs(n);

    if (abs >= 1_000_000) {
        return `${sign}$${(abs / 1_000_000).toFixed(2)}M`;
    }

    if (abs >= 1_000) {
        return `${sign}$${(abs / 1_000).toFixed(1)}K`;
    }

    return `${sign}$${abs.toFixed(0)}`;
}

export function pct(v: string | number | null | undefined): string {
    if (v === null || v === undefined || v === '') {
        return '—';
    }

    return `${Number(v).toFixed(2)}%`;
}

export function isNegative(v: string | number | null | undefined): boolean {
    return Number(v ?? 0) < 0;
}

/** Número para proporciones visuales (anchos, alturas). Nunca para mostrar dinero. */
export function num(v: string | number | null | undefined): number {
    const n = Number(v ?? 0);

    return Number.isFinite(n) ? n : 0;
}

/** Paso de la cascada → sección del estado de resultados que lo explica. */
export const STEP_SECTION: Record<string, string> = {
    depreciacion_amortizacion: 'da',
    resultado_financiero_neto: 'financiero',
};

export function sectionOfStep(stepKey: string): string {
    return STEP_SECTION[stepKey] ?? stepKey;
}

export function escapeHtml(s: string): string {
    return s.replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[c] as string,
    );
}

export function isZero(v: string | number | null | undefined): boolean {
    return Math.abs(Number(v ?? 0)) < 0.005;
}

export const STATUS_META: Record<SourceStatus, { label: string; cls: string }> =
    {
        procesado: {
            label: 'Procesado',
            cls: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
        },
        cargado: {
            label: 'Cargado · sin procesar',
            cls: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300',
        },
        con_incidencias: {
            label: 'Con incidencias',
            cls: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
        },
        pendiente: {
            label: 'Pendiente',
            cls: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300',
        },
    };

export const TONE_CLS: Record<string, string> = {
    success:
        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
    warning:
        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    danger: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300',
    info: 'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-900 dark:bg-cyan-950/40 dark:text-cyan-300',
    muted: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300',
};

const CONCILIATED = [
    'conciliado_erp_exacto',
    'conciliado_erp_tolerancia',
    'conciliado_erp_agregado',
    'erp_con_pago_bancario',
];
const REVIEW = [
    'folio_monto_diferente',
    'folio_fecha_diferente',
    'folio_ya_conciliado',
    'folio_ambiguo',
    'folio_otro_periodo',
    'posible_duplicado_erp',
];

export function isConciliated(
    m: Pick<Movement, 'reconciliation_status'>,
): boolean {
    return CONCILIATED.includes(m.reconciliation_status);
}

export function reconciliationTone(status: string): string {
    if (CONCILIATED.includes(status)) {
        return TONE_CLS.success;
    }

    if (REVIEW.includes(status)) {
        return TONE_CLS.warning;
    }

    if (status === 'transferencia') {
        return TONE_CLS.info;
    }

    return TONE_CLS.muted;
}

// ── HTTP ───────────────────────────────────────────────────────────────

export function xsrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    };
}

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly details: string[] = [],
        public readonly status = 0,
        /** JSON completo de la respuesta (blockers, errors por fila, attempt_id…). */
        public readonly body: Record<string, unknown> = {},
    ) {
        super(message);
    }

    /** Errores por fila de una carga rechazada. */
    get rowErrors(): RowError[] {
        return Array.isArray(this.body.errors)
            ? (this.body.errors as RowError[])
            : [];
    }

    get blockers(): ReportBlocker[] {
        return Array.isArray(this.body.blockers)
            ? (this.body.blockers as ReportBlocker[])
            : [];
    }
}

async function parseError(res: Response): Promise<ApiError> {
    try {
        const body = (await res.json()) as {
            message?: string;
            details?: string[];
            errors?: Record<string, string[]> | RowError[];
        } & Record<string, unknown>;
        // Validación de Laravel: { campo: [mensajes] }; carga rechazada: lista por fila.
        const first =
            body.errors && !Array.isArray(body.errors)
                ? Object.values(body.errors)[0]?.[0]
                : null;

        return new ApiError(
            first ?? body.message ?? `Error ${res.status}`,
            body.details ?? [],
            res.status,
            body,
        );
    } catch {
        return new ApiError(
            res.status === 413
                ? 'El archivo es demasiado grande para el servidor.'
                : `Error ${res.status} del servidor.`,
            [],
            res.status,
        );
    }
}

export async function apiJson<T>(
    url: string,
    init: RequestInit = {},
): Promise<T> {
    const isForm = init.body instanceof FormData;
    const res = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            ...xsrfHeaders(),
            ...(init.body && !isForm
                ? { 'Content-Type': 'application/json' }
                : {}),
            ...((init.headers as Record<string, string> | undefined) ?? {}),
        },
    });

    if (!res.ok) {
        throw await parseError(res);
    }

    return (await res.json()) as T;
}
