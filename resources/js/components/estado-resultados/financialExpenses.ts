/**
 * Gastos financieros (intereses LEROM / BBVA desde la tabla de amortización).
 * Contrato de GET /estado-resultados/{period}/gastos-financieros
 * (FinancialExpenseService::panel).
 */

/**
 * Archivo de tabla de amortización cargado para un crédito: estado (vigente o
 * sustituida), filas, rango de fechas, totales de pago/capital/interés, columnas
 * detectadas, advertencias y quién/cuándo lo subió o sustituyó.
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
    /** 24-nov-2026: IVA de interés, comisión financiera y su IVA (el IVA nunca es gasto). */
    total_interest_vat?: string;
    total_commission?: string;
    total_commission_vat?: string;
    /** simulacion = documento NO vinculante del acreedor; definitivo = tabla confirmada. */
    document_kind?: DocumentKind;
    columns: Record<string, string>;
    warnings: string[];
    uploaded_by: string | null;
    uploaded_at: string | null;
    superseded_at: string | null;
}

/**
 * Renglón de la tabla de amortización: número de pago, fecha, pago, capital,
 * interés, IVA del interés, saldo y origen (archivo o captura manual).
 */
export interface AmortizationRow {
    id: number;
    number: number | null;
    date: string;
    payment: string;
    principal: string;
    interest: string;
    interest_vat: string | null;
    /** Comisión financiera del pago (LEROM). NO es interés. */
    commission?: string | null;
    commission_vat?: string | null;
    balance: string | null;
    origin: 'archivo' | 'captura' | string;
    source_row: number | null;
}

/**
 * Pago esperado según la tabla y si se encontró en el estado de cuenta; `match`
 * trae el movimiento bancario emparejado (fecha, monto, días y diferencia).
 */
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

/**
 * Evidencia bancaria del crédito: patrón de búsqueda, tolerancias de días y
 * monto, pagos esperados, cargos del banco sin emparejar y totales del cotejo.
 */
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

/**
 * Situación de un crédito en el periodo: datos del crédito, tabla vigente, si
 * cubre el periodo, renglones y totales del periodo, evidencia bancaria, ajuste
 * de interés aplicado, valor congelado de la versión aprobada e historial de
 * archivos.
 */
export interface CreditPeriod {
    credit: {
        id: number;
        key: string;
        name: string;
        creditor: string | null;
        periodicity: 'diaria' | 'semanal' | 'mensual' | string;
        concept: string;
        concept_label: string;
        /** Concepto de ajuste de la comisión (LEROM: comisiones_financieras). */
        commission_concept?: string | null;
        commission_concept_label?: string | null;
    };
    upload: AmortizationUpload | null;
    has_table: boolean;
    /** Compatibilidad: coverage.status es completo o parcial. */
    covered: boolean;
    coverage?: Coverage;
    rows: AmortizationRow[];
    totals: {
        count: number;
        payment: string;
        principal: string;
        interest: string;
        interest_vat: string;
        commission?: string;
        commission_vat?: string;
    };
    first_date: string | null;
    last_date: string | null;
    /** Legado (UI actual): cotejo simple tabla vs. banco. Lo nuevo está en creditor_payments. */
    bank: BankEvidence;
    creditor_payments?: CreditorPayments;
    /** Pago aplicado al crédito (máx. aportaciones / pagos al acreedor) repartido FIFO. */
    application?: CreditApplication;
    contributions?: CreditContributions;
    status?: CreditStatus;
    recognized?: CreditRecognized;
    commission_adjustment?: CreditAdjustment | null;
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

/**
 * Respuesta completa del panel de gastos financieros: periodo, versión
 * bloqueada (si la hay), créditos, totales y reglas de carga del archivo.
 */
export interface FinancialExpensesPayload {
    period: { id: number; label: string; start: string; end: string };
    locked: { version: number } | null;
    credits: CreditPeriod[];
    totals: {
        payment: string;
        principal: string;
        interest: string;
        interest_vat: string;
        commission?: string;
        commission_vat?: string;
    };
    summary?: FinancialSummary;
    /** Aportaciones que no se pudieron atribuir a un crédito (sin acreedor o con dos). */
    contributions_unassigned?: Contribution[];
    allocation?: FinancialAllocation;
    rules: {
        extensions: string[];
        accept: string;
        columns: string[];
        tolerance: string;
        document_kinds?: DocumentKind[];
        tolerance_days?: number;
        tolerance_amount?: string;
        late_window_days?: number;
        commissions_in_financial_result?: boolean;
        allocation_method?: string;
    };
}

/* ───────────── Contrato 24-nov-2026 (FinancialExpenseService::panel / allocation) ─────────────
 * Campos NUEVOS y opcionales (?) para no romper los componentes actuales. Importes: string con
 * 2 decimales. Las cuentas bancarias siempre llegan enmascaradas («****1234»).
 */

export type DocumentKind = 'simulacion' | 'definitivo';

/**
 * Cobertura de la tabla sobre el periodo. «parcial» (la tabla empieza o termina dentro del
 * periodo) es VÁLIDA y alimenta el ER; «fuera_de_calendario» = sin pagos programados (antes del
 * primero o después del último); NUNCA significa liquidado.
 */
export interface Coverage {
    status: 'completo' | 'parcial' | 'sin_tabla' | 'fuera_de_calendario';
    position: 'antes' | 'despues' | null;
    reason: string;
}

/** Resumen del ajuste del ER de un concepto (interés o comisión). */
export interface CreditAdjustment {
    key: string;
    label: string;
    state: string;
    amount: string | null;
    origin: 'manual' | 'tabla_amortizacion' | null;
    difference: string | null;
    updated_by: string | null;
    notes: string | null;
}

/** Movimiento bancario referenciado (cuenta enmascarada). */
export interface MovementRef {
    fingerprint: string;
    date: string;
    amount: string;
    concept: string | null;
    account: string;
    branch: string | null;
}

export type CreditorPaymentStatus =
    | 'conciliado'
    | 'parcial'
    | 'no_localizado'
    | 'atrasado'
    | 'adelantado';

/**
 * Renglón previsto de la tabla contra los cargos al acreedor. `stage`: previsto (sólo tabla),
 * observado (con cargo) o confirmado (periodo aprobado o cargos clasificados a mano). Con
 * varios cargos posibles no se asocia ninguno: `ambiguous` + `candidates`.
 */
export interface CreditorPaymentRow {
    row_id: number;
    number: number | null;
    scheduled_date: string;
    bank_date: string | null;
    expected_payment: string;
    detected_payment: string;
    principal: string;
    interest: string;
    commission: string;
    /** IVA de interés + IVA de comisión (separado, nunca gasto). */
    vat: string;
    /** detectado − esperado. */
    difference: string;
    status: CreditorPaymentStatus;
    stage: 'previsto' | 'observado' | 'confirmado';
    /** La fecha prevista ya pasó (hoy, CDMX). */
    due: boolean;
    days: number | null;
    movements: MovementRef[];
    ambiguous: boolean;
    candidates: MovementRef[];
    /** Importe aplicado a este pago (FIFO) y lo que le falta, al centavo. */
    applied?: string;
    shortfall?: string;
    coverage?: PaymentCoverage;
}

/** Cobertura de un pago con lo aplicado: cubierto / parcial / sin_cubrir. */
export type PaymentCoverage = 'cubierto' | 'parcial' | 'sin_cubrir';

/**
 * Pago aplicado al crédito en el periodo (FinancialExpenseService::applyFunds).
 * applied = máx(contributed, paid_to_creditor): es el mismo dinero (entra por depósito de
 * las sucursales y sale por cargo al acreedor), por eso no se suma. Se reparte FIFO sobre
 * los pagos de la tabla; difference = applied − expected (negativo = faltante).
 */
export interface CreditApplication {
    rule: 'max_aportaciones_pagos';
    basis: 'aportaciones' | 'pagos_acreedor' | 'sin_fondos';
    expected: string;
    contributed: string;
    paid_to_creditor: string;
    applied: string;
    difference: string;
    shortfall: string;
    excess: string;
    due_shortfall: string;
    expected_count: number;
    covered_count: number;
    partial_count: number;
    uncovered_count: number;
    note: string;
}

/** Pagos al acreedor del periodo (cargos que coinciden con bank_pattern). */
export interface CreditorPayments {
    pattern: string | null;
    tolerance_days: number;
    tolerance_amount: string;
    rows: CreditorPaymentRow[];
    /** Cargos al acreedor sin renglón previsto (pagos extra). */
    unscheduled: Array<MovementRef & { status: 'no_previsto' }>;
    /** Abonos del acreedor (devoluciones / reversos), listados aparte. */
    reversals: MovementRef[];
    totals: {
        expected_count: number;
        reconciled_count: number;
        expected: string;
        detected: string;
        detected_scheduled: string;
        unscheduled: string;
        reversals: string;
        difference: string;
        /** Pagos totalmente cubiertos con lo aplicado. */
        covered_count?: number;
        /** Pagos NO cubiertos (parciales + sin cubrir) y su faltante. */
        unreconciled_count: number;
        unreconciled_amount: string;
    };
}

/**
 * Aportación de sucursal para el servicio de deuda (abono; rule aportacion_credito o
 * aportacion_credito_sugerida). Nunca marca un pago al acreedor como pagado.
 */
export interface Contribution {
    fingerprint: string;
    date: string;
    branch: string | null;
    account: string;
    concept: string | null;
    amount: string;
    credit_key: string | null;
    rule: string | null;
    manual: boolean;
    status: 'identificada' | 'pendiente';
    reason: string | null;
}

/** Aportaciones del periodo de un crédito. */
export interface CreditContributions {
    items: Contribution[];
    /** Sólo identificadas, por sucursal (orden alfabético). */
    by_branch: Array<{ branch: string; amount: string }>;
    identified: string;
    pending: string;
    total: string;
    count: number;
}

/**
 * Estado DERIVADO del crédito en el periodo (no se guarda). posible_liquidacion no es
 * «liquidado». document_balance es el saldo SEGÚN LA TABLA, no el saldo bancario.
 */
export interface CreditStatus {
    status:
        | 'vigente'
        | 'sin_pagos_programados'
        | 'pago_no_localizado'
        | 'pago_incompleto'
        | 'posible_liquidacion';
    reason: string;
    last_scheduled: { number: number | null; date: string } | null;
    next_payment: {
        number: number | null;
        date: string;
        payment: string;
    } | null;
    remaining_payments: number;
    document_balance: string | null;
    document_balance_note: string;
    document_kind: DocumentKind | null;
}

/** Importes que el ER reconoce del crédito en el periodo (la captura manual gana). */
export interface CreditRecognized {
    interest: string;
    commission: string;
    total: string;
    /** Comisión de la tabla cuando la config la deja fuera del EBT (sólo informativa). */
    commission_informative: string | null;
}

/** Resumen del periodo (consolidado). */
export interface FinancialSummary {
    observed_payment: string;
    /** Σ debía pagar / aplicado / faltante de los créditos con tabla (ver CreditApplication). */
    expected_payment?: string;
    applied_payment?: string;
    shortfall?: string;
    principal_informative: string;
    interest_recognized: string;
    commission_recognized: string;
    vat_separated: string;
    financial_cost: string;
    /** Negativo: lo que reduce el EBT. */
    ebt_effect: string;
    unreconciled: { count: number; amount: string };
    contributions_total: string;
    contributions_identified: string;
    contributions_pending: string;
    commissions_in_financial_result: boolean;
    commission_criterion: 'pendiente_confirmacion_finanzas';
    notes: string[];
}

/**
 * Prorrateo GERENCIAL por sucursal de un crédito (analítico: no crea ajustes ni toca el ER ni
 * Radiografía). Σ branches.assigned + unassigned.amount = recognized.total, exacto a centavos.
 */
export interface AllocationCredit {
    credit_key: string;
    credit_name: string;
    recognized: { interest: string; commission: string; total: string };
    principal_informative: string;
    contributions: { identified: string; pending: string; total: string };
    branches: Array<{
        branch: string;
        contributed: string;
        /** Participación 0..1 (6 decimales). */
        share: string;
        interest: string;
        commission: string;
        assigned: string;
        /** Capital atribuido con la misma participación: SÓLO informativo. */
        principal_informative: string;
    }>;
    unassigned: {
        amount: string;
        interest: string;
        commission: string;
        principal_informative: string;
        reason: string | null;
    };
    check: {
        assigned_plus_unassigned: string;
        recognized: string;
        ok: boolean;
    };
}

/** Prorrateo del periodo (FinancialExpenseService::allocation, mismo cálculo para PDF/Excel). */
export interface FinancialAllocation {
    method: 'aportaciones' | string;
    analytic: true;
    note: string;
    credits: AllocationCredit[];
    by_branch: Array<{ branch: string; assigned: string }>;
    unassigned: string;
    recognized: string;
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
    /** 24-nov-2026: interes | comision, comisión del periodo e importe que alimenta el concepto. */
    kind?: 'interes' | 'comision';
    commission?: string;
    vat_excluded?: string;
    amount?: string;
}

/** Texto de la periodicidad de pagos de un crédito. */
export const PERIODICITY_LABEL: Record<string, string> = {
    diaria: 'Pagos diarios (días hábiles)',
    semanal: 'Pagos semanales',
    mensual: 'Pagos mensuales',
};

/**
 * dd/mm/aaaa a partir de aaaa-mm-dd. Tolera fechas con hora
 * ("aaaa-mm-dd hh:mm:ss" o ISO "aaaa-mm-ddThh:mm:ss…"): se descarta la hora.
 */
export function dmy(d: string | null | undefined): string {
    if (!d) {
        return '—';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(d.trim());

    if (!match) {
        return d;
    }

    const [, y, m, day] = match;

    return `${day}/${m}/${y}`;
}
