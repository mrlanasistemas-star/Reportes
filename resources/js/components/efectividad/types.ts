/**
 * Tipos del módulo de Efectividad de Cobranza para las ejecuciones en
 * segundo plano (historial de reportes, etapas, fuentes y diagnóstico).
 * Los consumen la página EfectividadCobranza/Index, la campana de actividad
 * (BackgroundActivityBell) y los composables de filtros y notificaciones.
 *
 * RunStatus: estado persistido de una ejecución en el servidor.
 */
export type RunStatus =
    | 'queued'
    | 'running'
    | 'retrying'
    | 'success'
    | 'blocked'
    | 'failed'
    | 'cancelled';

/**
 * Estado visible en pantalla: el estado persistido más los estados derivados
 * por el servidor (retrasado, sin actividad del worker, obsoleto, cancelando).
 */
export type DisplayState =
    | RunStatus
    | 'delayed'
    | 'stalled'
    | 'obsolete'
    | 'cancelling';

/** Fila del resultado: efectividad por tramo (sano, atraso, vencido) de un promotor o total. */
export type ResultRow = {
    /** Motor V4: promotor | subtotal (TOTAL OFICINA) | gran_total. */
    tipo?: 'promotor' | 'subtotal' | 'gran_total';
    oficina_titulo?: string | null;
    promotor: string;
    oficina?: string | null;
    sano_pc: number;
    sano_cob: number;
    sano_pct: number;
    atraso_pc: number;
    atraso_cob: number;
    atraso_pct: number;
    vencido_pc: number;
    vencido_cob: number;
    vencido_pct: number;
    cobranza_total: number;
    pct_total: number;
    mora: number;
    es_total?: boolean;
};

/** Cobertura de datos de una ejecución del motor anterior (cartera e ingresos usados). */
export type Coverage = {
    status: string;
    message: string;
    cartera_rows_total: number;
    ingresos_rows_total: number;
    ingresos_rows_whitelist_pass: number;
    ingresos_rows_usables: number;
    cartera_rows_excluidas_cerrado_inactivo?: number;
    cartera_rows_sin_contrato?: number;
};

/** Cobertura de una ejecución del motor V4. */
export type V4Coverage = {
    fotos: Array<{ nombre: string; fecha: string; filas: number }>;
    filas_fotos: number;
    ingresos_corte: number;
    ingresos_historial: number;
    fechas_con_cargas_solapadas: Record<string, number> | [];
    estadisticas?: Record<string, number>;
    sin_movimientos?: boolean;
};

/** Ventana de cálculo: modo, domingo base, fecha de corte, rango de ingresos y fechas de foto. */
export type V4Window = {
    modo: 'SEMANAL' | 'DIARIO';
    domingo_base: string;
    fecha_corte: string;
    inicio: string;
    fin: string;
    semana_inicio: string;
    semana_fin: string;
    fechas_foto: string[];
};

/** Disponibilidad de una foto de cartera (un día de la semana) con sus archivos cargados. */
export type PhotoAvailability = {
    indice: number;
    nombre: string;
    dia: string;
    fecha: string;
    estado: 'disponible' | 'pendiente' | 'fallida' | 'faltante';
    contratos: number;
    oficinas: string[];
    archivos: Array<{
        snapshot_id: number;
        upload_id: number;
        filas: number;
        importado: string | null;
        archivo: string | null;
    }>;
    pendientes: number;
    error: string | null;
};

/** Disponibilidad de movimientos de ingresos para el corte y su historial previo. */
export type IngresosAvailability = {
    movimientos_corte: number;
    dias_con_movimientos: number;
    primer_dia: string | null;
    ultimo_dia: string | null;
    cargas: number[];
    historial_movimientos: number;
    historial_desde: string | null;
    historial_hasta: string | null;
    semana_previa_movimientos: number;
    requiere_confirmacion: boolean;
};

/** Fuentes del motor actual: ventana, fotos de cartera e ingresos. */
export type V4Sources = {
    ventana: V4Window;
    fotos: PhotoAvailability[];
    ingresos: IngresosAvailability;
};

/**
 * Respuesta de disponibilidad previa al cálculo: si el periodo es compatible,
 * trae ventana, fotos, ingresos e incidencias; si no, un código y mensaje.
 */
export type Availability =
    | {
          supported: true;
          engine_version: string;
          ventana: V4Window;
          fotos: PhotoAvailability[];
          fotos_disponibles: number;
          fotos_requeridas: number;
          ingresos: IngresosAvailability;
          issues: Issue[];
          bloqueante: boolean;
          requiere_confirmacion: boolean;
      }
    | { supported: false; code: string; message: string };

/** Resumen de incidencias agrupadas por código, con cantidad, importe y tratamiento. */
export type IncidentSummary = {
    codigo: string;
    etiqueta: string;
    cantidad: number;
    importe: number;
    tratamiento: string;
};

/** Incidencia individual detectada durante el cálculo (contrato, referencia, importe, acción). */
export type IncidentRow = {
    id: number;
    position: number;
    code: string;
    label: string;
    key: string;
    contract: string | null;
    reference: string | null;
    date: string | null;
    amount: number | null;
    description: string;
    status: string;
    action: string;
};

/** Definición de una etapa del proceso (clave, etiqueta, orden y si su avance es medible). */
export type StageInfo = {
    key: string;
    label: string;
    description: string;
    position: number;
    measurable: boolean;
};

/** Etapa con su avance real: estado, tiempos, procesados/total y resumen. */
export type StageEntry = StageInfo & {
    status:
        | 'pending'
        | 'running'
        | 'done'
        | 'blocked'
        | 'failed'
        | 'cancelled'
        | 'unknown';
    started_at: string | null;
    finished_at: string | null;
    processed: number | null;
    total: number | null;
    summary: string | null;
    details: Record<string, unknown> | null;
};

/** Problema de datos detectado (crítico, advertencia o informativo) con la acción sugerida. */
export type Issue = {
    code: string;
    severity: 'critical' | 'warning' | 'info';
    category: string;
    message: string;
    source: 'ingresos' | 'saldos' | 'fotos' | null;
    file: string | null;
    upload_id: number | null;
    period: string | null;
    sheet: string | null;
    original_header: string | null;
    expected_column: string | null;
    affected_rows: number[] | string | null;
    record_count: number | null;
    action: string;
};

/** Resumen de un archivo cargado y de su último procesamiento. */
export type UploadSummary = {
    id: number;
    file: string;
    period_id: number;
    uploaded_at: string | null;
    status: string | null;
    file_exists: boolean;
    rows: number;
    last_process: {
        status: string | null;
        rows_read: number | null;
        rows_inserted: number | null;
        finished_at: string | null;
        log: string | null;
    } | null;
};

/** Fuentes del motor anterior: saldos del periodo e ingresos con su historial. */
export type Sources = {
    saldos: {
        label: string;
        period_id: number | null;
        period_label: string;
        rows_in_db: number;
        uploads: UploadSummary[];
    };
    ingresos: {
        label: string;
        period_label: string;
        rows_in_period: number;
        uploads: UploadSummary[];
        history_rows: number;
        history_uploads: number;
        history_from: string | null;
        history_to: string | null;
    };
};

/** Estado de una columna esperada en el archivo (encontrada, faltante o desconocida). */
export type ColumnField = {
    field: string;
    label: string;
    critical: boolean;
    original_header: string | null;
    status: 'found' | 'missing' | 'unknown';
    empty_rows: number | null;
    total_rows: number;
};

/** Reporte de columnas por fuente (saldos e ingresos). */
export type ColumnsReport = Record<
    'saldos' | 'ingresos',
    { label: string; total_rows: number; fields: ColumnField[] }
>;

/**
 * Resumen de una ejecución tal como llega en el historial y en el sondeo:
 * estado, etapa actual, progreso, tiempos, autor, errores y notificación.
 */
export type RunSummary = {
    id: number;
    report: string;
    engine_version: string | null;
    engine_label: string;
    is_current_engine: boolean;
    is_periodica_engine: boolean;
    mode: 'SEMANAL' | 'DIARIO' | null;
    base_date: string | null;
    cutoff_date: string | null;
    incidents_count: number | null;
    period_id: number;
    period_label: string | null;
    branch_id: number | null;
    branch_label: string;
    status: RunStatus;
    display_state: DisplayState;
    display_label: string;
    health_message: string | null;
    queue_state: string;
    is_active: boolean;
    current_stage: StageInfo | null;
    stage_count: number;
    progress: {
        current: number | null;
        total: number | null;
        percent: number | null;
        determinate: boolean;
    };
    log: string | null;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    heartbeat_at: string | null;
    duration_seconds: number | null;
    attempts: number;
    requested_by: { id: number; name: string } | null;
    has_excel: boolean;
    failure_kind: string | null;
    error_message: string | null;
    can_cancel: boolean;
    cancel_requested_at: string | null;
    cancelled_at: string | null;
    superseded_by_id: number | null;
    issue_counts: { critical: number; warning: number };
    notification: {
        status: string | null;
        notified_at: string | null;
        error: string | null;
        attempts: number;
    };
};

/** Detalle completo de una ejecución: etapas, fuentes, columnas, incidencias y filas. */
export type RunDetail = RunSummary & {
    stages: StageEntry[];
    sources: Sources | V4Sources | null;
    columns: ColumnsReport | null;
    issues: Issue[];
    coverage: Coverage | V4Coverage | null;
    reconciliation: {
        ok: boolean;
        offices: number;
        promoters: number;
        summary: string;
    } | null;
    rows: ResultRow[];
    is_stale: boolean | null;
    technical_reference: string | null;
    parameters: {
        modo: string;
        domingo_base: string;
        fecha_corte: string;
        inicio_ingresos: string;
        fin_ingresos: string;
    } | null;
    incidents_summary: IncidentSummary[];
};

/** Indica si las fuentes corresponden al motor actual (tienen fotos de cartera). */
export function isV4Sources(s: Sources | V4Sources | null): s is V4Sources {
    return !!s && 'fotos' in s;
}

/** Indica si la cobertura corresponde al motor actual (tiene filas de fotos). */
export function isV4Coverage(c: Coverage | V4Coverage | null): c is V4Coverage {
    return !!c && 'filas_fotos' in c;
}

/** Filtros del historial: periodo, sucursal, estado, rango de fechas y usuario. */
export type HistoryFilters = {
    f_period: number | null;
    f_branch: string | null;
    f_status: string | null;
    f_from: string | null;
    f_to: string | null;
    f_user: number | null;
};
