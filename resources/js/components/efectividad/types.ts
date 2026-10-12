export type RunStatus =
    | 'queued'
    | 'running'
    | 'retrying'
    | 'success'
    | 'blocked'
    | 'failed';

export type DisplayState = RunStatus | 'delayed' | 'stalled' | 'obsolete';

export type ResultRow = {
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

export type StageInfo = {
    key: string;
    label: string;
    description: string;
    position: number;
    measurable: boolean;
};

export type StageEntry = StageInfo & {
    status: 'pending' | 'running' | 'done' | 'blocked' | 'failed' | 'unknown';
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
    source: 'ingresos' | 'saldos' | null;
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
    sources: Sources | null;
    columns: ColumnsReport | null;
    issues: Issue[];
    coverage: Coverage | null;
    reconciliation: {
        ok: boolean;
        offices: number;
        promoters: number;
        summary: string;
    } | null;
    rows: ResultRow[];
    is_stale: boolean | null;
    technical_reference: string | null;
};

export type HistoryFilters = {
    f_period: number | null;
    f_branch: string | null;
    f_status: string | null;
    f_from: string | null;
    f_to: string | null;
    f_user: number | null;
};
