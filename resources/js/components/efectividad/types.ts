export type RunStatus =
    | 'queued'
    | 'running'
    | 'retrying'
    | 'success'
    | 'blocked'
    | 'failed'
    | 'cancelled';

export type DisplayState =
    | RunStatus
    | 'delayed'
    | 'stalled'
    | 'obsolete'
    | 'cancelling';

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

export type V4Sources = {
    ventana: V4Window;
    fotos: PhotoAvailability[];
    ingresos: IngresosAvailability;
};

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

export type IncidentSummary = {
    codigo: string;
    etiqueta: string;
    cantidad: number;
    importe: number;
    tratamiento: string;
};

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

export type StageInfo = {
    key: string;
    label: string;
    description: string;
    position: number;
    measurable: boolean;
};

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

export type ColumnField = {
    field: string;
    label: string;
    critical: boolean;
    original_header: string | null;
    status: 'found' | 'missing' | 'unknown';
    empty_rows: number | null;
    total_rows: number;
};

export type ColumnsReport = Record<
    'saldos' | 'ingresos',
    { label: string; total_rows: number; fields: ColumnField[] }
>;

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

export function isV4Sources(s: Sources | V4Sources | null): s is V4Sources {
    return !!s && 'fotos' in s;
}

export function isV4Coverage(c: Coverage | V4Coverage | null): c is V4Coverage {
    return !!c && 'filas_fotos' in c;
}

export type HistoryFilters = {
    f_period: number | null;
    f_branch: string | null;
    f_status: string | null;
    f_from: string | null;
    f_to: string | null;
    f_user: number | null;
};
