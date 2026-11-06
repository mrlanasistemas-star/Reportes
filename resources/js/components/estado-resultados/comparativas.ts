/**
 * Contrato `er-comparativas/1` (EstadoResultadosComparativoController / ComparisonService).
 * Todas las cifras vienen del backend; la UI sólo da formato y proporciones visuales.
 * null = "Sin datos" o "Pendiente" (nunca $0.00).
 */

export type CompMode =
    | 'anterior'
    | 'anio_anterior'
    | 'evolucion'
    | 'acumulado'
    | 'sucursales'
    | 'personalizado';

export interface Reason {
    code: string;
    message: string;
}

export interface CompMissing {
    side: 'actual' | 'comparacion';
    period_id: number | null;
    period_label: string;
    source: 'erp' | 'noi' | 'bancos' | 'noi_corp';
    source_label: string;
    /** carga: Carga de archivos · er: Estado de Resultados → Fuentes */
    where: 'carga' | 'er';
    where_label: string;
    message: string;
}

export interface Comparability {
    /** true sólo si level = comparable. */
    comparable: boolean;
    /** comparable | con_reservas | no_comparable (opcional en respuestas anteriores). */
    level?: 'comparable' | 'con_reservas' | 'no_comparable';
    level_label?: string;
    headline?: string;
    reasons: Reason[];
    warnings: Reason[];
    /** Lo que falta para que sean comparables: fuente, periodo y dónde se carga. */
    missing?: CompMissing[];
    /** Conceptos que la diferencia de fuentes no afecta (lectura normal). */
    unaffected?: string[];
}

export interface CompPeriod {
    id: number | null;
    code: string | null;
    label: string;
    start: string | null;
    end: string | null;
    days: number | null;
    year: number | null;
    month: number | null;
}

export interface CompRef {
    period: CompPeriod;
    available: boolean;
    status: string;
    status_label: string;
    unavailable_reason: Reason | null;
    source: string | null;
    source_label: string | null;
    report?: Record<string, unknown> | null;
}

export interface CompRow {
    key: string;
    label: string;
    /** income | expense | result | signed_result | margin */
    kind: string;
    unit: 'MXN' | '%';
    current: string | null;
    previous: string | null;
    variation: string | null;
    variation_unit: 'MXN' | 'pp';
    variation_pct: string | null;
    assessment: 'favorable' | 'desfavorable' | 'neutral' | null;
    assessment_label: string | null;
    reason: Reason | null;
    /** «Con reservas»: la variación se muestra pero puede deberse a la fuente que falta. */
    reservation?: Reason | null;
    complete: { current: boolean; previous: boolean };
    centralized?: boolean;
}

export interface CompPair {
    key: string;
    label: string;
    current: CompRef;
    comparison: CompRef;
    available: boolean;
    unavailable_reason: Reason | null;
    comparability: Comparability;
    rows: CompRow[];
    opex_categories: CompRow[];
}

export interface Series {
    key: string;
    label: string;
    data: Array<string | null>;
}

export interface CompEvolution {
    key: 'evolucion';
    label: string;
    months: number;
    range: { from: string; to: string };
    labels: string[];
    status: string[];
    series: Record<string, Array<string | null>>;
    with_data: number;
    note: string;
}

export interface CompYtd {
    key: 'acumulado';
    label: string;
    year: number;
    partial: boolean;
    months_included: number;
    months_with_data: number;
    months_total: number;
    current: { label: string; missing_months: string[] };
    previous_year: { label: string };
    comparison: CompPair;
}

export interface BranchRow {
    key: string;
    label: string;
    available: boolean;
    status: string;
    status_label: string;
    ingresos_operativos: string | null;
    opex_total: string | null;
    ebitda: string | null;
    margen_ebitda: string | null;
    participacion_ingresos: string | null;
    participacion_ebitda: string | null;
    participacion_reason: Reason | null;
    previous_period: string | null;
    comparability: Comparability;
    variation: Record<
        string,
        {
            previous: string | null;
            variation: string | null;
            variation_pct?: string | null;
            variation_unit?: string;
            assessment: string | null;
            reason: Reason | null;
        }
    >;
}

export interface CompBranches {
    key: 'sucursales';
    label: string;
    restricted: boolean;
    consolidated_visible: boolean;
    rows: BranchRow[];
    totals: {
        sucursales: null | {
            label: string;
            ingresos_operativos: string | null;
            opex_total: string | null;
            ebitda: string | null;
            margen_ebitda: string | null;
        };
        consolidado: null | {
            label: string;
            ingresos_operativos: string | null;
            opex_total: string | null;
            ebitda: string | null;
            margen_ebitda: string | null;
        };
        centralizados: null | {
            label: string;
            total: string;
            categories: Array<{
                key: string;
                label: string;
                amount: string;
                restricted: boolean;
            }>;
        };
        conciliacion: null | {
            suma_ebitda_sucursales: string | null;
            menos_centralizados: string | null;
            ebitda_consolidado: string | null;
            no_asignado_a_sucursal: string | null;
            note: string;
        };
    };
    sort_options: Array<{ key: string; label: string }>;
    default_sort: string;
    note: string;
}

export interface CascadeStep {
    key: string;
    label: string;
    type: 'total' | 'delta' | 'subtotal';
    amount: string | null;
    start: string | null;
    end: string | null;
    pending: boolean;
}

export interface CompCharts {
    evolucion_resultados?: {
        title: string;
        labels: string[];
        status: string[];
        series: Series[];
    };
    ingresos_vs_opex?: {
        title: string;
        labels: string[];
        status: string[];
        series: Series[];
        result_series: Series | null;
    };
    composicion_opex?: {
        title: string;
        available: boolean;
        total: string | null;
        items: Array<{
            key: string;
            label: string;
            amount: string;
            pct_of_opex: string | null;
            centralized: boolean;
            restricted: boolean;
        }>;
        note: string;
    };
    cascada?: {
        title: string;
        available: boolean;
        steps: CascadeStep[];
        note: string;
    };
    margenes?: {
        title: string;
        labels: string[];
        status: string[];
        series: Series[];
    };
    sucursales?: null | {
        title: string;
        sort_options: Array<{ key: string; label: string }>;
        default_sort: string;
        rows: Array<
            Pick<
                BranchRow,
                | 'key'
                | 'label'
                | 'available'
                | 'ingresos_operativos'
                | 'opex_total'
                | 'ebitda'
                | 'margen_ebitda'
            >
        >;
        centralized: CompBranches['totals']['centralizados'];
    };
}

export interface ComparativasPayload {
    contract_version: string;
    generated_at: string;
    model: {
        key: string;
        label: string;
        formulas: string[];
        branch_note: string;
    };
    period: CompPeriod;
    scope: { key: string; label: string; consolidated: boolean };
    access: {
        restricted: boolean;
        consolidated_allowed: boolean;
        message: string | null;
    };
    sources_note: string;
    current: CompRef & {
        values: Record<string, string | null>;
        margins: Record<string, string | null>;
    };
    modes: {
        anterior: CompPair | null;
        anio_anterior: CompPair | null;
        personalizado: CompPair | null;
        evolucion: CompEvolution | null;
        acumulado: CompYtd | null;
        sucursales: CompBranches | null;
    };
    charts: CompCharts;
    diagnostics?: { ms: number };
}

/** Variación en pesos o puntos porcentuales con signo explícito. */
export function signed(v: string | null, unit: 'MXN' | 'pp' | '%'): string {
    if (v === null) {
        return '—';
    }

    const n = Number(v);
    const sign = n > 0 ? '+' : n < 0 ? '−' : '';
    const abs = Math.abs(n);

    if (unit === 'MXN') {
        return `${sign}${new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(abs)}`;
    }

    return `${sign}${abs.toFixed(2)} ${unit === 'pp' ? 'pp' : '%'}`;
}

/** Variación compacta para tarjetas: +$82.5K, −6.74 pp. */
export function signedCompact(
    v: string | null,
    unit: 'MXN' | 'pp' | '%',
): string {
    if (v === null) {
        return '—';
    }

    if (unit !== 'MXN') {
        return signed(v, unit);
    }

    const n = Number(v);
    const abs = Math.abs(n);
    const sign = n > 0 ? '+' : n < 0 ? '−' : '';
    const body =
        abs >= 1_000_000
            ? `$${(abs / 1_000_000).toFixed(2)}M`
            : abs >= 1_000
              ? `$${(abs / 1_000).toFixed(1)}K`
              : `$${abs.toFixed(2)}`;

    return `${sign}${body}`;
}
