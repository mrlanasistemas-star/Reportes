/** Tipos del módulo «Datos duros» (/datos-duros) — espejo de App\Services\HardData\HardDataService. */

export type HardDataPeriod = {
    id: number;
    name: string;
    range: string | null;
    type: string;
    year: number | null;
    month: number | null;
};

export type HardDataCategory =
    | 'DIAMANTE'
    | 'MASTER'
    | 'SENIOR'
    | 'JUNIOR'
    | 'MANTENIDO';

export type HardDataBucket = { contracts: number; amount: number };

export type HardDataRow = {
    id: number;
    name: string;
    code: string | null;
    promoter_code: string | null;
    position: string | null;
    branch_id: number | null;
    branch: string | null;
    region: string | null;
    routes: string[];
    status: string | null;
    roster_label: string | null;
    placement: number;
    operations: number;
    placement_by_product: {
        product: string | null;
        operations: number;
        amount: number;
    }[];
    recovery: number;
    recovery_components: Record<string, number> | null;
    portfolio: number;
    overdue: number;
    current: number;
    mora_pct: number | null;
    buckets: Record<string, HardDataBucket>;
    payroll: number;
    perceptions: number;
    deductions: number;
    payroll_concepts: Record<string, number>;
    commissions: number;
    opex: number;
    opex_items: {
        category: string;
        concept: string;
        amount: number;
        type: string;
    }[];
    income_base: number;
    total_expenses: number;
    ebitda: number;
    margin_pct: number | null;
    category: HardDataCategory | string;
};

export type HardDataDataset = {
    period: HardDataPeriod;
    status: 'ok' | 'not_available' | string;
    message: string | null;
    calculated_at: string | null;
    rows: HardDataRow[];
    /** Colaboradores del alcance que no se muestran (sin ingresos o sin nómina en el periodo). */
    hidden?: number;
    scope: { general: boolean; label: string };
};

export type HardDataHistoryPoint = {
    period: HardDataPeriod;
    present: boolean;
    branch?: string | null;
    placement?: number;
    recovery?: number;
    portfolio?: number;
    overdue?: number;
    mora_pct?: number | null;
    payroll?: number;
    opex?: number;
    ebitda?: number;
    category?: string;
};

export type HardDataSortKey =
    | 'name'
    | 'placement'
    | 'recovery'
    | 'portfolio'
    | 'mora_pct'
    | 'ebitda'
    | 'payroll';

export type HardDataFlag =
    | 'mora_alta'
    | 'ebitda_negativo'
    | 'sin_colocacion'
    | 'baja';
