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
    treatment_source: 'manual' | 'regla';
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
}

export interface Permissions {
    cargar: boolean;
    procesar: boolean;
    clasificar: boolean;
    ajustes: boolean;
    exportar: boolean;
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
    ) {
        super(message);
    }
}

async function parseError(res: Response): Promise<ApiError> {
    try {
        const body = (await res.json()) as {
            message?: string;
            details?: string[];
            errors?: Record<string, string[]>;
        };
        const first = body.errors ? Object.values(body.errors)[0]?.[0] : null;

        return new ApiError(
            first ?? body.message ?? `Error ${res.status}`,
            body.details ?? [],
        );
    } catch {
        return new ApiError(
            res.status === 413
                ? 'El archivo es demasiado grande para el servidor.'
                : `Error ${res.status} del servidor.`,
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
