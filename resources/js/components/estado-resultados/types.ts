/**
 * Tipos y utilidades del módulo Estado de Resultados.
 * El dataset lo construye EstadoResultadosDatasetBuilder (fuente única de verdad);
 * la UI sólo da formato — nunca recalcula cifras.
 */

import type { AdjustmentAmortization } from './financialExpenses';

/** Estado de una fuente de datos del periodo (ver STATUS_META para etiqueta y colores). */
export type SourceStatus =
    | 'pendiente'
    | 'cargado'
    | 'procesado'
    | 'con_incidencias';

/** Renglón de detalle de una sección o categoría del estado de resultados. */
export interface StatementLine {
    key: string;
    label: string;
    amount: string;
    /** % sobre los ingresos operativos (texto decimal); null = no aplica. */
    pct: string | null;
    /** Fuente de la cifra (Radiografía, ERP, Bancos, Ajustes…); se muestra en tooltips. */
    source: string;
    /** Explicación breve de la línea. */
    hint: string | null;
    /** Estado especial: 'sin_informacion' (no se asume $0) o 'restringido' (oculto por permisos). */
    state: string | null;
    /** Próximo contrato: detalle oculto por permisos (nómina / DG). */
    redacted?: boolean;
    redacted_reason?: string | null;
}

/** Categoría de OPEX (sucursales, nómina, corporativo; DG sólo en versiones anteriores al 25-nov-2026): NO son niveles de resultado. */
export interface OpexCategory {
    key: string;
    label: string;
    /** false = la categoría no aplica en este alcance; se muestra `note` en su lugar. */
    applies: boolean;
    note: string | null;
    lines: StatementLine[];
    total: { label: string; amount: string | null; pct: string | null };
    restricted?: boolean;
}

/** Sección del estado de resultados (Ingresos, OPEX, D&A, financiero, ISR). */
export interface StatementSection {
    type: 'section';
    key: string;
    /** Paso 1–11 del modelo de un solo EBITDA (10–11 = retiros, sólo consolidado) (ausente en versiones anteriores). */
    step?: number;
    /** Número visible del modelo anterior (respaldo cuando no hay `step`). */
    number: string;
    title: string;
    kind: string;
    applies: boolean;
    note: string | null;
    /** 'pendiente' = sin información (p. ej. ISR): nunca se presenta como $0. */
    state?: string | null;
    lines: StatementLine[];
    /** amount null = pendiente. */
    total: { label: string; amount: string | null; pct: string | null };
    subtotals?: Array<{ key?: string; label: string; amount: string }>;
    /** Sólo la sección OPEX: sus categorías. */
    categories?: OpexCategory[];
    /** Detalle oculto por permisos (nómina / DG): sólo se ve el total. */
    restricted?: boolean;
    /** Sólo la sección 'retiros' (paso 10, consolidado): retiros de DG y socios, después de la utilidad. */
    withdrawal?: boolean;
}

/** Renglón de resultado (EBITDA, EBIT, EBT, Utilidad neta). */
export interface StatementResult {
    type: 'result';
    key: string;
    step?: number;
    label: string;
    /** null = pendiente (p. ej. utilidad neta sin ISR). */
    amount: string | null;
    pct: string | null;
    /** Fórmula en texto que explica cómo se obtiene. */
    formula: string;
    /** Nivel del resultado en el modelo (orden en la cascada). */
    level: number;
    note?: string | null;
    /** false = falta información para darlo por definitivo (ver `missing`). */
    complete: boolean;
    /** Conceptos que faltan para completar el resultado. */
    missing: string[];
    /** Sólo 'resultado_despues_retiros': true = quedó en rojo (tomaron dinero del flujo siguiente). */
    negative?: boolean;
    /** Sólo 'resultado_despues_retiros': de qué resultado parte (EBT si el ISR está pendiente). */
    base?: 'utilidad_neta' | 'ebt';
}

/** Bloque del estado de resultados: sección o resultado (discriminado por `type`). */
export type StatementBlock = StatementSection | StatementResult;

/** Paso de la cascada Ingresos → Utilidad neta. */
export interface WaterfallStep {
    key: string;
    label: string;
    /** null = pendiente (nunca $0). */
    amount: string | null;
    /** total/subtotal parten de 0; delta parte del acumulado anterior. */
    type: 'total' | 'delta' | 'subtotal';
    level: number;
    step?: number;
    pending?: boolean;
    /** 'opex' para las categorías de OPEX; 'retiros' para los retiros de DG y socios (paso 10). */
    group?: string;
    /** Sólo 'resultado_despues_retiros' (paso 11): resultado del que parte. */
    base?: 'utilidad_neta' | 'ebt';
}

/** Movimiento que cuenta como retiro de Dirección General o socios (consolidado). */
export interface WithdrawalItem {
    fingerprint: string;
    source: 'banco' | 'erp' | string;
    date: string | null;
    concept: string | null;
    /** Concepto agrupado (sin fechas ni folios). */
    group: string;
    account_slot: string | null;
    account_label: string | null;
    folio: string | null;
    branch_name: string | null;
    beneficiary: string | null;
    /** Quién se lo llevó (beneficiario, Dirección General o Socios). */
    who: string;
    cargo: string | null;
    abono: string | null;
    /** Importe que resta (positivo = salida). */
    amount: string;
    treatment: string;
    treatment_label: string;
    rule_code: string | null;
    reason: string | null;
    manual: boolean;
}

/** Retiros de DG y socios agrupados por concepto. */
export interface WithdrawalConcept {
    key: string;
    label: string;
    count: number;
    amount: string;
    /** % sobre el total de retiros. */
    pct: string | null;
}

/**
 * Retiros de Dirección General y socios (25-nov-2026): no son OPEX, no cambian el EBITDA;
 * se restan al final, después de la Utilidad neta (o del EBT si el ISR está pendiente).
 */
export interface Withdrawals {
    /** false en sucursal: DG y socios nunca se asignan a una sucursal. */
    applies: boolean;
    note: string | null;
    total: string;
    count: number;
    base: 'utilidad_neta' | 'ebt';
    base_label: string;
    base_amount: string;
    result: string | null;
    result_label: string;
    formula: string;
    negative: boolean;
    explain: string | null;
    rule: string;
    /** Sin permiso ver_dg: by_concept e items vienen vacíos. */
    restricted?: boolean;
    by_concept: WithdrawalConcept[];
    items: WithdrawalItem[];
}

/** Registro del ERP emparejado con un movimiento. */
export interface ErpMatch {
    fingerprint: string;
    id: number;
    folio: string | null;
    amount: string;
    date: string | null;
    date_source: string;
    branch_name: string | null;
    /** Universo del ERP al que pertenece (p. ej. dentro o fuera de Radiografía). */
    universe: string;
    concept: string | null;
    category: string | null;
    /** Días de diferencia entre la fecha del banco y la del ERP. */
    delta_days?: number;
}

/** Clasificación manual que sustituye al tratamiento automático de un movimiento. */
export interface Override {
    treatment: string;
    /** Tratamiento que tenía antes de la corrección. */
    previous: string | null;
    notes: string;
    user: string | null;
    at: string | null;
    /** Número de cambios de clasificación registrados. */
    history_count: number;
    /** «Ya considerado» parcial: importe apartado (null = sin reparto). */
    considered_amount?: string | null;
    /** Motivo de «Ya considerado»: radiografia | otro_periodo | otra_fuente. */
    considered_reason?: string | null;
}

/** Motivos de «Ya considerado» (mismo catálogo que Treatment::CONSIDERED_REASONS). */
export const CONSIDERED_REASONS: Array<{ key: string; label: string }> = [
    { key: 'radiografia', label: 'En Radiografía de sucursales' },
    { key: 'otro_periodo', label: 'Ya reconocido en otro periodo' },
    { key: 'otra_fuente', label: 'Ya registrado en otra cuenta/fuente' },
];

/** Tratamiento de la parte ya considerada según su motivo. */
export const consideredTreatment = (reason: string) =>
    reason === 'radiografia' ? 'cubierto_radiografia' : 'ya_considerado';

/** Destino de una parte del importe de un movimiento en el cuadre (Σ partes = bruto). */
export interface MovementPart {
    /** Tratamiento (categoría del cuadre). */
    key: string;
    amount: string;
    side: 'cargo' | 'abono';
    /** Mismo gasto que una fila ERP del dataset: el cuadre no lo repite. */
    overlap?: boolean;
}

/** Categoría del cuadre de gastos e ingresos (un tratamiento). */
export interface CuadreCategory {
    key: string;
    label: string;
    /** er | retiros | fuera | pendiente */
    kind: 'er' | 'retiros' | 'fuera' | 'pendiente';
    /** Subgrupo de «Fuera del ER» (ya_considerado, internos, capital, balance, periodo). */
    outside: string | null;
    line: string | null;
    assignable: boolean;
    count: number;
    cargos: string;
    abonos: string;
    total: string;
}

/** Cuadre del periodo: Σ por destino = universo importado (sin repetir el ERP ya pagado). */
export interface Cuadre {
    imported: string;
    overlap: string;
    universe: string;
    ingresos: string;
    gastos: string;
    by_kind: Record<'er' | 'retiros' | 'fuera' | 'pendiente', string>;
    outside: Array<{ key: string; label: string; amount: string }>;
    sum_categories: string;
    difference: string;
    ok: boolean;
    categories: CuadreCategory[];
}

/**
 * Movimiento del periodo (banco, ERP o NOI de corporativo) con su conciliación,
 * tratamiento contable e impacto en el estado de resultados.
 */
export interface Movement {
    fingerprint: string;
    /** banco | erp | noi_corp (NOI de corporativo, fuente propia del ER) */
    source: 'banco' | 'erp' | 'noi_corp';
    /** Clave de la cuenta bancaria (slot de carga) de la que proviene. */
    account_slot: string;
    account_label: string | null;
    file: string | null;
    /** Fila del archivo original. */
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
    /** La fecha cae dentro del periodo consultado. */
    in_period: boolean;
    /** Registros del ERP emparejados. */
    erp: ErpMatch[];
    reconciliation_status: string;
    reconciliation_message: string | null;
    reconciliation_label: string;
    /** Tratamiento que asignaron las reglas automáticas. */
    auto_treatment: string;
    suggested_treatment: string | null;
    suggested_label: string | null;
    rule_code: string | null;
    rule_reason: string | null;
    /** Tratamiento vigente (automático o manual). */
    treatment: string;
    treatment_label: string;
    treatment_group: string;
    treatment_explain: string;
    /** manual | regla | catalogo | reembolso */
    treatment_source: string;
    /** Regla del catálogo de conceptos que lo clasificó (null = ninguna). */
    concept_rule_id?: number | null;
    /** Concepto del catálogo («Renta de oficinas»); el reporte agrupa por él. */
    concept_label?: string | null;
    override: Override | null;
    /** Requiere revisión humana (aparece en «Por revisar»). */
    needs_review: boolean;
    /** Huella del movimiento espejo de una transferencia entre cuentas. */
    transfer_pair: string | null;
    /** Renglones de detalle del archivo que se agruparon en este movimiento. */
    detail_lines: number;
    /** Movimientos del banco ligados (para registros del ERP). */
    linked_bank: Array<{
        row: number;
        account: string;
        amount: string;
        date: string;
    }>;
    /** Efecto en el estado de resultados: si suma, a qué línea, cuánto y explicación. */
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
    /** Importe ya aplicado en emparejamientos. */
    applied?: string;
    match_type?: string | null;
    match_group?: string | null;
    /** Huella del movimiento que lo revierte (o al que revierte). */
    reversal_pair?: string | null;
    /** Huella del cargo original del que este movimiento es reembolso. */
    refund_of?: string | null;
    refund_status?: string | null;
    erp_payment?: { amount: string; applied: string; pending: string };
    erp_payment_status?: string;
    reconciliation_delta_days?: number | null;
    /** Importe «ya considerado» (parcial) y su tratamiento (25-nov-2026). */
    considered?: string;
    considered_reason?: string | null;
    considered_treatment?: string | null;
    /** Destinos del importe en el cuadre (Σ = bruto). */
    parts?: MovementPart[];
}

/** Par clave/etiqueta de una dimensión (key null = sin valor). */
export interface KeyLabel {
    key: string | null;
    label: string;
}

/** Dimensiones de trazabilidad de un movimiento (importación, conciliación, revisión, reconocimiento…). */
export interface MovementDimensions {
    import: KeyLabel;
    reconciliation: KeyLabel;
    category: KeyLabel;
    accounting: KeyLabel;
    review: KeyLabel;
    inclusion: KeyLabel;
    source: KeyLabel;
    /** Periodo y criterio en que se reconoce el movimiento, y motivo si queda fuera de periodo. */
    recognition: KeyLabel & {
        period_id?: number | null;
        period_code: string | null;
        basis?: string;
        basis_label: string;
        out_of_period?: string | null;
        out_of_period_label: string | null;
    };
}

/** Fechas relevantes de un movimiento según su origen. */
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
    /** Parte que todavía se puede clasificar. */
    classifiable: string;
    /** Parte «ya considerada» (incluida en excluded). */
    considered?: string;
}

/** Vínculo de trazabilidad entre un movimiento y otro registro (banco, ERP o NOI). */
export interface MovementLink {
    kind: string;
    kind_label: string;
    /** banco | erp | noi */
    target_type: string;
    target: string | null;
    match_type: string | null;
    group_key?: string | null;
    /** Importe aplicado en este vínculo. */
    applied: string;
    /** Saldo pendiente del movimiento tras el vínculo. */
    movement_pending: string;
    /** Saldo pendiente del registro destino tras el vínculo. */
    target_pending: string;
    delta_days: number | null;
    /** Evidencia del emparejamiento (estructura libre del backend). */
    evidence: Record<string, unknown> | unknown[];
    redacted?: boolean;
}

/** Catálogo de tratamientos contables: etiqueta, grupo, línea del ER y si se puede asignar a mano. */
export interface TreatmentMeta {
    key: string;
    label: string;
    group: string;
    /** Línea del estado de resultados a la que suma (null = no suma). */
    line: string | null;
    tone: string;
    explain: string;
    /** Se puede elegir al clasificar manualmente. */
    assignable: boolean;
}

/** Estado de un ajuste: sin información (pendiente), confirmado en cero o capturado. */
export type AdjustmentState =
    | 'sin_informacion'
    | 'confirmado_cero'
    | 'capturado';

/** Ajuste contable manual o derivado (D&A, ISR, intereses…) del periodo. */
export interface Adjustment {
    key: string;
    label: string;
    section: string;
    /** Texto del efecto en el estado de resultados. */
    effect: string;
    /** false = sólo informativo (no cambia ningún resultado). */
    affects: boolean;
    state: AdjustmentState;
    amount: string | null;
    notes: string | null;
    updated_by: string | null;
    updated_at: string | null;
    /** manual = captura en Ajustes · tabla_amortizacion = interés de la tabla del crédito. */
    origin?: 'manual' | 'tabla_amortizacion' | null;
    /** Intereses de crédito: detalle de la tabla de amortización (pagos, capital excluido, diferencia). */
    amortization?: AdjustmentAmortization | null;
}

/** Validación numérica esperado vs real (debe cuadrar en $0.00). */
export interface Check {
    key: string;
    label: string;
    expected: string;
    actual: string;
    difference: string;
    ok: boolean;
    explain: string | null;
}

/** Indicador financiero o informativo. */
export interface Indicator {
    key: string;
    label: string;
    value: string | null;
    /** Unidad: moneda, porcentaje, número o texto libre. */
    unit: 'MXN' | '%' | 'n' | 'txt';
}

/** Conteo de movimientos y su importe. */
export interface CountAmount {
    count: number;
    amount: string;
}

/** Archivo de una cuenta: 1 = obligatorio, 2 = opcional (20-nov-2026). */
export type AccountFileUpload = NonNullable<AccountSource['upload']>;

/** Estado de uno de los dos archivos de una cuenta bancaria. */
export interface AccountFilePart {
    part: number;
    label: string;
    required: boolean;
    /** pendiente (falta el 1) | opcional (no hay 2) | cargado | con_incidencias | procesado */
    status: SourceStatus | 'opcional';
    upload: AccountFileUpload | null;
}

/** Cuenta bancaria cuyo estado de cuenta se sube en Fuentes. */
export interface AccountSource {
    key: string;
    label: string;
    status: SourceStatus;
    /** Archivo 1 y archivo 2 de la cuenta (ausente en datasets anteriores al 20-nov-2026). */
    parts?: AccountFilePart[];
    /** Archivo 1 (compatibilidad). */
    upload: null | {
        id: number;
        part?: number;
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
        /** Avisos clasificados: informativa | bloqueante. */
        warnings_detail?: Array<{
            code: string;
            severity: string;
            message: string;
        }>;
        blocking_warnings?: Array<{
            code: string;
            severity: string;
            message: string;
        }>;
        /** recibido | validado | procesado | conciliado | rechazado | duplicado */
        lifecycle?: string | null;
        lifecycle_label?: string | null;
        first_date: string | null;
        last_date: string | null;
        uploaded_by: string | null;
        uploaded_at: string | null;
    };
    /** Movimientos de esta cuenta pendientes de revisión. */
    pending_review: number;
    /** Cargas anteriores (vigentes y sustituidas). */
    history: Array<{
        id: number;
        part?: number;
        name: string;
        status: string;
        hash: string;
        lifecycle?: string | null;
        movements: number;
        uploaded_by: string | null;
        uploaded_at: string | null;
    }>;
}

/** Fuente que se carga en Carga de archivos y el ER sólo lee (Radiografía, ERP, Nómina, Ajustes). */
export interface SimpleSource {
    label: string;
    status: SourceStatus;
    detail: string;
    count?: number;
    /** Diagnóstico de Carga de archivos (sólo ERP y NOI). */
    diagnosis?: SourceDiagnosis | null;
}

/** Qué ve el ER de una fuente de Carga de archivos (UploadModuleInspector). */
export interface SourceDiagnosis {
    reason: string;
    module_label?: string;
    /** Renglones disponibles en las tablas de hechos para el periodo. */
    fact_rows: number;
    uploads: Array<{
        id: number;
        period_id: number;
        name: string;
        status: string;
        status_label: string;
        rows: number;
        uploaded_by: string | null;
        uploaded_at: string | null;
    }>;
    /** Última ejecución de carga de registros. */
    run: null | {
        id: number;
        status: string;
        error: string | null;
        at: string | null;
    };
    /** Cargas del mismo módulo asignadas a otros periodos (posible confusión de periodo). */
    elsewhere: Array<{
        upload_id: number;
        period_id: number;
        period_label: string;
        name: string;
        status: string;
    }>;
}

/** Estado de un item de la lista de verificación de fuentes. */
export type ChecklistState = 'lista' | 'falta' | 'con_problemas';

/** Acción sugerida para resolver un item de la lista de verificación. */
export interface ChecklistAction {
    /** upload: se sube aquí · carga: Carga de archivos · tab: otra pestaña · process: procesar conciliación */
    kind: 'upload' | 'carga' | 'tab' | 'process';
    label: string;
    slot?: string;
    /** Archivo de la cuenta (1 obligatorio, 2 opcional); sin valor = archivo 1. */
    part?: number;
    tab?: string;
}

/** Una fuente de la lista de Fuentes (SourceChecklist). */
export interface ChecklistItem {
    key: string;
    label: string;
    /** Dónde se resuelve: aquí en el ER, en Carga de archivos o en otra pestaña del ER. */
    where: 'er' | 'carga' | 'er_tab';
    where_label: string;
    state: ChecklistState;
    state_label: string;
    required: boolean;
    message: string;
    fix: string | null;
    /** Por qué importa (qué cifras afecta). */
    affects: string;
    action: ChecklistAction;
    /** Código del motivo del estado (uso interno). */
    reason: string | null;
    diagnosis: SourceDiagnosis | null;
}

/** Lista «Qué necesita este periodo» con su resumen de conteos. */
export interface SourceChecklist {
    items: ChecklistItem[];
    summary: {
        total: number;
        lista: number;
        falta: number;
        con_problemas: number;
        /** Items que no están listos. */
        pendientes: number;
        pendientes_obligatorias: number;
    };
}

/** NOI de corporativo (fuente propia del ER). */
export interface CorporatePayrollSource {
    key: 'noi_corporativo';
    label: string;
    status: SourceStatus;
    required: boolean;
    detail: string;
    upload: null | {
        id: number;
        name: string;
        hash: string;
        employees: number;
        lines: number;
        total_percepciones: string;
        total_deducciones: string;
        warnings: string[];
        uploaded_by: string | null;
        uploaded_at: string | null;
    };
    /** Importe que suma a la nómina corporativa (sin duplicados). */
    recognized: string;
    /** Colaboradores que ya estaban en el NOI de sucursales y no se suman otra vez. */
    overlap: CountAmount;
    /** Nómina corporativa que llega por ERP o bancos (sin este archivo). */
    other_sources_amount: string;
    /** Explicación de la regla anti-duplicado. */
    rule: string;
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

/** Estado del procesamiento (conciliación) del periodo. */
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
    /** El ERP cambió después del último procesamiento. */
    stale_erp: boolean;
    /** El catálogo de conceptos cambió desde el último procesamiento. */
    stale_catalog?: boolean;
    /** Hay cargas que aún no se procesan. */
    unprocessed: boolean;
}

/**
 * Dataset completo del Estado de Resultados para un periodo y alcance, tal
 * como lo arma el backend (en vivo o desde una versión aprobada).
 */
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
        /** Días de tolerancia en fecha para conciliar banco ↔ ERP. */
        tolerance_days: number;
        generated_at: string;
        /** Huella del dataset (cambia si cambia cualquier cifra). */
        dataset_hash: string;
        report?: ReportMeta;
        /** Modelo de un solo EBITDA. Ausente en versiones aprobadas con el modelo anterior. */
        model?: { version: string; steps: string[]; sign: string };
        redactions?: Redactions;
        recognition?: {
            version: string;
            criterio: string;
            reglas: Record<string, string>;
        };
    };
    /** Modelo vigente: ingresos_operativos, opex_total, ebitda, …; null = pendiente. */
    results: Record<string, string | null>;
    /** Claves del modelo anterior, SÓLO como controles (p. ej. EBITDA sucursales = Radiografía). */
    controls?: Record<string, string | null>;
    /** Márgenes sobre ingresos (texto decimal); null = sin margen. */
    margins: Record<string, string | null>;
    /** Por resultado: si está completo y qué le falta. */
    completeness: Record<string, { complete: boolean; missing: string[] }>;
    statement: StatementBlock[];
    waterfall: WaterfallStep[];
    /** Retiros de DG y socios. Ausente en versiones aprobadas antes del 25-nov-2026. */
    withdrawals?: Withdrawals;
    /** Cuadre de gastos e ingresos (versiones anteriores al 25-nov-2026 no lo traen). */
    cuadre?: Cuadre;
    /** Cuadre del EBITDA de sucursales del ER contra el de la Radiografía. */
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
    /** Resumen de la conciliación del periodo (conteos e importes por estado). */
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
    /** Movimientos del periodo (ya redactados según permisos). */
    movements: Movement[];
    /** Lo que pasó por el banco pero no es resultado, y los flujos manuales informativos. */
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
        /** Opcional: datasets/snapshots anteriores al 06-nov-2026 no lo traen. */
        noi_corporativo?: CorporatePayrollSource;
        checklist?: SourceChecklist;
        exportaciones: Array<{
            format: string;
            scope: string;
            hash: string;
            user: string | null;
            at: string | null;
        }>;
    };
    /** Catálogo de tratamientos disponibles. */
    treatments: TreatmentMeta[];
    /** Etiqueta legible de cada estado de conciliación. */
    reconciliation_labels: Record<string, string>;
    /** Opcional: snapshots aprobados antes del 06-nov-2026 no la traen. */
    validation?: Validation;
    /** Catálogos de valores de las dimensiones de trazabilidad (para filtros). */
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

/** Estados del flujo de gobierno de un reporte. */
export type ReportStatusKey =
    | 'borrador'
    | 'pendiente_conciliacion'
    | 'listo_revision'
    | 'aprobado'
    | 'sustituido';

/** Motivo que impide aprobar la versión. */
export interface ReportBlocker {
    /** pendientes | procesamiento | validaciones | datos_faltantes */
    code: string;
    message: string;
    scope?: string;
}

/** Resumen de una versión aprobada o sustituida del reporte. */
export interface VersionSummary {
    id: number;
    version: number;
    status: string;
    status_label: string;
    /** La versión está congelada. */
    locked: boolean;
    rules_version: string | null;
    dataset_hash: string | null;
    /** Sello de aprobación (hash de la foto congelada). */
    snapshot_hash: string | null;
    scopes: string[];
    approved_by: string | null;
    approved_at: string | null;
    approval_notes?: string | null;
    reopened: { at: string; by: string | null; reason: string | null } | null;
    superseded_at: string | null;
    superseded_by_version_id?: number | null;
}

/** Historial de versiones del periodo con su bitácora. */
export interface VersionHistory {
    locked: boolean;
    current: VersionSummary | null;
    /** Número de la versión en preparación (null si no hay). */
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

/** Metadatos de la versión mostrada: estado, bloqueos, aprobación y sello. */
export interface ReportMeta {
    status: string;
    status_label: string;
    /** en_vivo | snapshot */
    source: string;
    version: number;
    /** Id de la versión guardada (null en vivo sin versión). */
    version_id: number | null;
    locked: boolean;
    /** Es la versión vigente del periodo. */
    current: boolean;
    /** Las cifras aún pueden cambiar (ver `preliminary_reasons`). */
    preliminary: boolean;
    preliminary_reasons: string[];
    blockers: ReportBlocker[];
    /** No hay bloqueos: se puede aprobar. */
    approvable: boolean;
    approved_by: string | null;
    approved_at: string | null;
    snapshot_hash: string | null;
    /** Criterio de reconocimiento aplicado. */
    criterion: string;
    rules_version: string;
    generated_at: string;
    /** Fecha de emisión del reporte. */
    issued_at: string;
    reopened: { at: string; by: string | null; reason: string | null } | null;
    /** Versión aprobada anterior cuando esta se abrió como nueva. */
    previous_version: VersionSummary | null;
    superseded_at: string | null;
}

/** Qué se ocultó por permisos (nómina, Dirección General) y cuántos movimientos. */
export interface Redactions {
    nomina: boolean;
    dg: boolean;
    hidden_movements: { nomina: number; dg: number };
    redacted_references?: number;
    message: string | null;
}

/** Movimiento del banco que no concilió limpio con el ERP. */
export interface DiffItem {
    /** Huella del movimiento (null = no se puede abrir). */
    fingerprint: string | null;
    date: string | null;
    folio: string | null;
    concept: string | null;
    account_slot: string;
    bank_amount: string;
    erp_amounts: string[];
    /** Diferencia de importe banco vs ERP (null si no aplica). */
    difference: string | null;
    delta_days: number | null;
    status: string;
    status_label: string;
    message: string | null;
    out_of_period?: string | null;
    redacted?: boolean;
}

/** Grupo de diferencias de un tipo, con su conteo e importe. */
export interface DiffGroup {
    count: number;
    amount: string;
    items: DiffItem[];
}

/** Comprobación de que el total se reparte sin diferencia entre los destinos. */
export interface PartitionCheck {
    total: string;
    sum_parts: string;
    difference: string;
    ok: boolean;
}

/** Bloque de la pestaña Validación: totales, particiones, diferencias e integridad. */
export interface Validation {
    /** ok | con_pendientes | con_diferencias */
    status: string;
    scope_note: string | null;
    /** Embudo: lo leído del archivo, lo importado y su reparto por destino. */
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
    /** Particiones que deben cuadrar en $0.00. */
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
    /** Cuadre contra la Radiografía. */
    radiography: {
        er: string;
        radiografia: string;
        difference: string;
        base_difference?: string;
        ok: boolean;
        base_ok?: boolean;
        explained: Array<{ label: string; amount: string }>;
    };
    /** Integridad de los archivos (control del banco vs leído) y vigencia del procesamiento. */
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
    /** Eliminaciones: transferencias internas, reversos y flujos internos. */
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
    /** Resumen del NOI y lo que queda sin asignar. */
    noi: {
        employees: number;
        total: string;
        unassigned_total: string;
        unassigned_employees: number;
        linked_payments: CountAmount;
        recognizing_rule: string;
    };
    /** Partidas sin resolver. */
    unresolved: Array<{
        key: string;
        label: string;
        count: number;
        amount: string;
        /** ¿Impide aprobar? */
        blocking?: boolean;
        open_count?: number;
        open_amount?: string;
    }>;
    /** Validaciones que no cuadran. */
    failed_checks: Array<{ key: string; label: string; difference: string }>;
}

/** Permisos del usuario en el módulo para el periodo/alcance actual. */
export interface Permissions {
    cargar: boolean;
    procesar: boolean;
    clasificar: boolean;
    ajustes: boolean;
    exportar: boolean;
    /** Administrar el catálogo de conceptos (global). */
    catalogo?: boolean;
    consultar?: boolean;
    conciliar?: boolean;
    reclasificar?: boolean;
    editar_ajustes?: boolean;
    aprobar?: boolean;
    ver_nomina?: boolean;
    ver_dg?: boolean;
    /** Puede consultar el historial de clasificación (sólo acceso general). */
    historial_clasificacion?: boolean;
    /** false = acceso limitado a sus sucursales (no ve el consolidado ni opera el periodo completo). */
    acceso_general?: boolean;
    /** Explicación para mostrar en lugar de los controles ocultos por alcance. */
    motivo_restriccion?: string | null;
}

/** Reglas de carga: tamaño máximo (MB), extensiones y valor del atributo accept. */
export interface UploadRules {
    max_mb: number;
    extensions: string[];
    accept: string;
}

/** Error por fila de una carga rechazada. */
export interface RowError {
    row: number | null;
    column: string | null;
    message: string;
}

/** Intento de carga de un archivo (aceptado o rechazado). */
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
    /** Se conservó el archivo original en el servidor. */
    original_kept: boolean;
    user: string | null;
    at: string | null;
}

// ── Formato ────────────────────────────────────────────────────────────

/** Formateador de moneda MXN en es-MX. */
const currency = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
});

/**
 * Formatea un importe en pesos.
 * @param v importe (texto o número).
 * @returns importe formateado, o '—' si está vacío o es nulo.
 */
export function mxn(v: string | number | null | undefined): string {
    if (v === null || v === undefined || v === '') {
        return '—';
    }

    return currency.format(Number(v));
}

/**
 * Formato compacto para ejes y etiquetas: $1.23M, $4.5K o $678 (nulo = $0).
 * @param v importe.
 */
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

/**
 * Formatea un porcentaje con 2 decimales.
 * @param v valor ya en porcentaje (12.5 = 12.50 %).
 * @returns texto, o '—' si está vacío o es nulo.
 */
export function pct(v: string | number | null | undefined): string {
    if (v === null || v === undefined || v === '') {
        return '—';
    }

    return `${Number(v).toFixed(2)}%`;
}

/** true si el valor es menor que cero (nulo = 0). */
export function isNegative(v: string | number | null | undefined): boolean {
    return Number(v ?? 0) < 0;
}

/** Número para proporciones visuales (anchos, alturas). Nunca para mostrar dinero. */
export function num(v: string | number | null | undefined): number {
    const n = Number(v ?? 0);

    return Number.isFinite(n) ? n : 0;
}

/**
 * Escapa caracteres HTML para insertar texto en el html de SweetAlert.
 * @param s texto sin escapar.
 */
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

/** true si el importe redondea a $0.00 (tolerancia de medio centavo). */
export function isZero(v: string | number | null | undefined): boolean {
    return Math.abs(Number(v ?? 0)) < 0.005;
}

/** Etiqueta y clases de la insignia por estado de fuente (StatusBadge). */
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

/** Clases de insignia por tono semántico. */
export const TONE_CLS: Record<string, string> = {
    success:
        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
    warning:
        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    danger: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300',
    info: 'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-900 dark:bg-cyan-950/40 dark:text-cyan-300',
    muted: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300',
};

/** Estados de conciliación que cuentan como conciliado con el ERP. */
const CONCILIATED = [
    'conciliado_erp_exacto',
    'conciliado_erp_tolerancia',
    'conciliado_erp_agregado',
    'erp_con_pago_bancario',
];
/** Estados de conciliación que requieren revisión (diferencias de folio, importe o fecha). */
const REVIEW = [
    'folio_monto_diferente',
    'folio_fecha_diferente',
    'folio_ya_conciliado',
    'folio_ambiguo',
    'folio_otro_periodo',
    'posible_duplicado_erp',
];

/**
 * Indica si un movimiento quedó conciliado con el ERP.
 * @param m movimiento (sólo se usa su estado de conciliación).
 */
export function isConciliated(
    m: Pick<Movement, 'reconciliation_status'>,
): boolean {
    return CONCILIATED.includes(m.reconciliation_status);
}

/**
 * Clases de insignia para un estado de conciliación: verde conciliado, ámbar
 * en revisión, cian transferencia y gris el resto.
 * @param status estado de conciliación.
 */
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

/** Encabezados para peticiones AJAX a Laravel, con el token XSRF tomado de la cookie. */
export function xsrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    };
}

/**
 * Error de una petición al API con mensaje ya legible, detalles, código HTTP y
 * el cuerpo JSON completo.
 */
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

    /** Bloqueos de aprobación que devolvió el servidor. */
    get blockers(): ReportBlocker[] {
        return Array.isArray(this.body.blockers)
            ? (this.body.blockers as ReportBlocker[])
            : [];
    }
}

/**
 * Mensajes técnicos que el servidor/Laravel manda en inglés o con códigos HTTP: nunca se
 * muestran tal cual a un usuario de finanzas.
 */
const TECHNICAL_MESSAGE =
    /^(No query results|Not Found|The route|CSRF token mismatch|Unauthenticated|This action is unauthorized|Server Error|Too Many Attempts|Service Unavailable|Error \d+|SQLSTATE|Call to|Undefined|Class ")/i;

/** Mensaje claro en español para un estado HTTP, usando el del servidor si ya es legible. */
export function friendlyError(status: number, raw?: string | null): string {
    if (status === 419) {
        return 'Tu sesión expiró. Recarga la página para continuar.';
    }

    if (status === 401) {
        return 'Tu sesión terminó. Vuelve a iniciar sesión para continuar.';
    }

    const usable = raw && !TECHNICAL_MESSAGE.test(raw.trim()) ? raw : null;

    if (usable) {
        return usable;
    }

    if (status === 403) {
        return 'No tienes permiso para hacer esto. Si lo necesitas, pídelo al administrador del sistema.';
    }

    if (status === 404) {
        return 'No encontramos lo que buscas: es posible que el periodo o el registro ya no exista.';
    }

    if (status === 413) {
        return 'El archivo es demasiado grande para el servidor.';
    }

    if (status === 429) {
        return 'Hiciste muchas solicitudes seguidas. Espera un minuto y vuelve a intentarlo.';
    }

    if (status === 503) {
        return 'El sistema está en mantenimiento. Intenta de nuevo en unos minutos.';
    }

    return 'Ocurrió un problema en el servidor. Intenta de nuevo en un momento; si continúa, avisa a Sistemas.';
}

/**
 * Convierte una respuesta fallida en ApiError: toma el primer error de
 * validación de Laravel o el `message`, traducidos con friendlyError.
 * @param res respuesta HTTP no exitosa.
 */
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
            friendlyError(res.status, first ?? body.message),
            body.details ?? [],
            res.status,
            body,
        );
    } catch {
        return new ApiError(friendlyError(res.status), [], res.status);
    }
}

/** true si el error es una cancelación (petición vieja abortada a propósito). */
export function isAbortError(e: unknown): boolean {
    return e instanceof DOMException && e.name === 'AbortError';
}
/**
 * fetch JSON con credenciales y XSRF; pone Content-Type JSON salvo en FormData.
 * @param url dirección a consultar.
 * @param init opciones de fetch.
 * @returns el cuerpo JSON tipado.
 * @throws ApiError ante error HTTP o de conexión; re-lanza las cancelaciones (AbortError).
 */
export async function apiJson<T>(
    url: string,
    init: RequestInit = {},
): Promise<T> {
    const isForm = init.body instanceof FormData;
    let res: Response;

    try {
        res = await fetch(url, {
            credentials: 'same-origin',
            ...init,
            headers: {
                Accept: 'application/json',
                ...xsrfHeaders(),
                ...(init.body && !isForm
                    ? { 'Content-Type': 'application/json' }
                    : {}),
                ...((init.headers as Record<string, string> | undefined) ?? {}),
            },
        });
    } catch (e) {
        if (isAbortError(e)) {
            throw e;
        }

        throw new ApiError(
            'No pudimos conectar con el servidor. Revisa tu conexión a internet y vuelve a intentarlo.',
        );
    }

    if (!res.ok) {
        throw await parseError(res);
    }

    // 204 / cuerpo vacío: no hay JSON que leer (res.json() lanzaría).
    if (res.status === 204) {
        return undefined as T;
    }

    const text = await res.text();

    return (text ? JSON.parse(text) : undefined) as T;
}
