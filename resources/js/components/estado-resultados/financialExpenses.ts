/**
 * Gastos financieros (intereses LEROM / BBVA desde la tabla de amortización).
 * Contrato de GET /estado-resultados/{period}/gastos-financieros
 * (FinancialExpenseService::panel).
 */

export interface AmortizationUpload {
    id: number;
    name: string;
    hash: string;
    status: 'vigente' | 'sustituida' | string;
    rows: number;
    first_date: string | null;
    last_date: string | null;
    total_payment: string;
    total_principal: string;
    total_interest: string;
    columns: Record<string, string>;
    warnings: string[];
    uploaded_by: string | null;
    uploaded_at: string | null;
    superseded_at: string | null;
}

export interface AmortizationRow {
    id: number;
    number: number | null;
    date: string;
    payment: string;
    principal: string;
    interest: string;
    interest_vat: string | null;
    balance: string | null;
    origin: 'archivo' | 'captura' | string;
    source_row: number | null;
}

export interface BankExpected {
    row_id: number;
    number: number | null;
    date: string;
    payment: string;
    status: 'encontrado' | 'no_encontrado';
    match: {
        fingerprint: string;
        date: string;
        amount: string;
        concept: string | null;
        days: number;
        difference: string;
    } | null;
}

export interface BankEvidence {
    pattern: string | null;
    tolerance_days: number;
    tolerance_amount: string;
    expected: BankExpected[];
    unmatched_bank: Array<{
        fingerprint: string;
        date: string;
        amount: string;
        concept: string | null;
    }>;
    totals: {
        expected_count: number;
        found_count: number;
        matched_count: number;
        expected: string;
        found: string;
        difference: string;
    };
}

export interface CreditPeriod {
    credit: {
        id: number;
        key: string;
        name: string;
        creditor: string | null;
        periodicity: 'semanal' | 'mensual' | string;
        concept: string;
        concept_label: string;
    };
    upload: AmortizationUpload | null;
    has_table: boolean;
    covered: boolean;
    rows: AmortizationRow[];
    totals: {
        count: number;
        payment: string;
        principal: string;
        interest: string;
        interest_vat: string;
    };
    first_date: string | null;
    last_date: string | null;
    bank: BankEvidence;
    adjustment: {
        key: string;
        label: string;
        state: string;
        amount: string | null;
        origin: 'manual' | 'tabla_amortizacion' | null;
        difference: string | null;
        updated_by: string | null;
        notes: string | null;
    } | null;
    frozen?: {
        version: number;
        state: string | null;
        amount: string | null;
        live_amount: string | null;
        outdated: boolean;
    };
    history: AmortizationUpload[];
}

export interface FinancialExpensesPayload {
    period: { id: number; label: string; start: string; end: string };
    locked: { version: number } | null;
    credits: CreditPeriod[];
    totals: {
        payment: string;
        principal: string;
        interest: string;
        interest_vat: string;
    };
    rules: {
        extensions: string[];
        accept: string;
        columns: string[];
        tolerance: string;
    };
}

/** Detalle que el backend agrega a los ajustes de interés (AdjustmentService::forPeriod). */
export interface AdjustmentAmortization {
    covered: boolean;
    payments: number;
    payment: string;
    principal_excluded: string;
    interest: string;
    difference: string | null;
    applied: boolean;
}

export const PERIODICITY_LABEL: Record<string, string> = {
    semanal: 'Pagos semanales',
    mensual: 'Pagos mensuales',
};

/** dd/mm/aaaa a partir de aaaa-mm-dd. */
export function dmy(d: string | null | undefined): string {
    if (!d) {
        return '—';
    }

    const [y, m, day] = d.split('-');

    return `${day}/${m}/${y}`;
}
