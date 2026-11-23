/**
 * Control total de la pestaña Validación (20-nov-2026): tipos y utilidades puras.
 *
 * Los movimientos del periodo vienen en dataset.movements (ya redactados por permisos) con
 * `control` (estado + señales), `placement` y `duplicate_of`. Las filas de los archivos que
 * se fueron a OTRO periodo o que se leyeron y no se guardaron llegan del endpoint
 * GET /estado-resultados/{period}/control-movimientos.
 */
import type { Movement, Validation } from './types';

/** Estado de control de un movimiento: completado, pendiente, duplicado, fuera del periodo o no guardado. */
export type ControlStatus =
    | 'completado'
    | 'pendiente'
    | 'duplicado'
    | 'fuera'
    | 'no_guardado';

/** Filtro de la lista: un estado, «revisar concepto» o todos. */
export type ControlFilter = ControlStatus | 'revisar' | 'todos';

/** Señal de revisión sobre un movimiento (p. ej. concepto a revisar) con su severidad. */
export interface ControlFlag {
    key: string;
    label: string;
    /** alta | media | baja */
    severity: string;
}

/** Control de un movimiento: estado, etiqueta, subtítulo, explicación y señales. */
export interface ItemControl {
    status: ControlStatus;
    label: string;
    sub: string | null;
    explain: string;
    flags: ControlFlag[];
}

/** Fila original de la que un movimiento es duplicado (archivo, fila, periodo, cuenta y fecha). */
export interface DuplicateOf {
    upload_id: number;
    file: string | null;
    source_row: number;
    period_id: number;
    period_label: string | null;
    account_slot: string | null;
    date: string | null;
}

/** Movimiento con los datos de control (estado, archivo de origen, ubicación y duplicado). */
export type ControlledMovement = Movement & {
    control?: ItemControl;
    upload_id?: number;
    upload_period_label?: string | null;
    placement?: string | null;
    placement_label?: string | null;
    duplicate_of?: DuplicateOf | null;
};

/** Conteo con totales de cargos, abonos y suma. */
export interface Triple {
    count: number;
    cargos: string;
    abonos: string;
    total: string;
}

/** Parte de la partición de un archivo: a dónde fue cada peso, con conteo, montos y choques con otros archivos. */
export interface FilePart {
    /** entra | otro_periodo | duplicado | sin_periodo | periodo_cerrado | periodo_existente | fuera_periodo | no_guardado */
    key: string;
    label: string;
    period_id: number | null;
    period_label: string | null;
    here: boolean;
    count: number;
    cargos: string;
    abonos: string;
    total: string;
    against: Array<{
        upload_id: number;
        file: string | null;
        period_label: string | null;
        count: number;
        total: string;
    }>;
}

/**
 * Control de un archivo cargado: cuenta, periodo de carga, total leído, control
 * del banco y su diferencia, totales guardados/aquí/en otro periodo/no
 * guardados y su partición.
 */
export interface FileControlUpload {
    upload_id: number;
    slot: string;
    file: string;
    account_label: string | null;
    file_part?: number | null;
    upload_period: { id: number; label: string | null };
    own: boolean;
    control_ok: boolean | null;
    control_total: string | null;
    control_difference: string | null;
    read_total: string;
    movements: number;
    saved: Triple;
    here: Triple;
    elsewhere: Triple;
    period_share: Triple;
    not_saved: Triple;
    saved_ok: boolean;
    parts: FilePart[];
}

/** Control de todos los archivos que aportan al periodo, con totales y la regla de la partición. */
export interface FileControl {
    uploads: FileControlUpload[];
    totals: {
        files: number;
        own_files: number;
        period_share: Triple;
        not_saved: { count: number; total: string; files: number };
        rule: string;
    };
}

/** Referencia a un movimiento (huella y monto) dentro de un pendiente o una validación fallida. */
export interface ControlRef {
    fingerprint: string | null;
    amount: string;
    redacted?: boolean;
}

/** Archivo relacionado con un pendiente o una validación fallida (conteo y monto). */
export interface ControlFileRef {
    upload_id: number;
    file: string;
    count: number;
    amount: string;
}

/** Pendiente de la validación con movimientos, archivos y nota opcionales. */
export type ControlUnresolved = Validation['unresolved'][number] & {
    items?: ControlRef[];
    open_items?: ControlRef[] | null;
    files?: ControlFileRef[];
    note?: string | null;
};

/** Validación fallida con movimientos, archivos y nota opcionales. */
export type ControlFailedCheck = Validation['failed_checks'][number] & {
    items?: ControlRef[];
    files?: ControlFileRef[];
    note?: string | null;
};

/** Validación del periodo con pendientes y fallas enriquecidos y el control por archivo. */
export type ControlValidation = Omit<
    Validation,
    'unresolved' | 'failed_checks'
> & {
    unresolved: ControlUnresolved[];
    failed_checks: ControlFailedCheck[];
    file_control?: FileControl;
};

/** Fila del endpoint (fuera de este periodo o no guardada). */
export interface OutsideRow {
    id?: number;
    fingerprint: string | null;
    upload_id: number;
    file: string | null;
    account_slot: string | null;
    account_label?: string | null;
    source_row: number;
    date: string | null;
    concept: string | null;
    reference?: string | null;
    cargo: string;
    abono: string;
    period_label?: string | null;
    placement?: string;
    part?: string;
    status: ControlStatus;
    status_label: string;
    explain: string;
    duplicate_of?: DuplicateOf | null;
    treatment_label?: string | null;
    rule_code?: string | null;
    redacted?: boolean;
}

/** Página del endpoint de control de movimientos, con el resumen por archivo. */
export interface OutsidePage {
    data: OutsideRow[];
    total: number;
    page: number;
    last_page: number;
    files?: Array<{
        upload_id: number;
        file: string;
        count: number;
        amount: string;
        readable: boolean;
        message: string | null;
    }>;
}

/** Etiqueta y clases (insignia y punto) de cada estado de control. */
export const STATUS_META: Record<
    ControlStatus,
    { label: string; cls: string; dot: string }
> = {
    completado: {
        label: 'Guardado y completado',
        cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
        dot: 'bg-emerald-500',
    },
    pendiente: {
        label: 'Pendiente de clasificar',
        cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
        dot: 'bg-amber-500',
    },
    duplicado: {
        label: 'Repetido / duplicado',
        cls: 'bg-violet-100 text-violet-800 dark:bg-violet-950/60 dark:text-violet-300',
        dot: 'bg-violet-500',
    },
    fuera: {
        label: 'Fuera de este periodo',
        cls: 'bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-200',
        dot: 'bg-slate-500',
    },
    no_guardado: {
        label: 'Leído y no guardado',
        cls: 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300',
        dot: 'bg-rose-500',
    },
};

/** Filtros disponibles en la lista de control, en el orden en que se muestran. */
export const FILTERS: Array<{ key: ControlFilter; label: string }> = [
    { key: 'todos', label: 'Todos' },
    { key: 'completado', label: 'Completados' },
    { key: 'pendiente', label: 'Pendientes' },
    { key: 'duplicado', label: 'Repetidos' },
    { key: 'fuera', label: 'Fuera de este periodo' },
    { key: 'no_guardado', label: 'No guardados' },
    { key: 'revisar', label: 'Revisar concepto' },
];

/** Estado de control (snapshots aprobados antes del 20-nov no traen `control`). */
export function controlOf(m: ControlledMovement): ItemControl {
    if (m.control) {
        return m.control;
    }

    const status: ControlStatus =
        m.placement === 'duplicado'
            ? 'duplicado'
            : !m.in_period
              ? 'fuera'
              : m.treatment === 'pendiente'
                ? 'pendiente'
                : 'completado';

    return {
        status,
        label: STATUS_META[status].label,
        sub: null,
        explain: m.rule_reason ?? '',
        flags: [],
    };
}

/**
 * Indica si un movimiento pasa el filtro (todos, revisar = tiene señales, o un estado).
 * @param m movimiento.
 * @param filter filtro elegido.
 */
export function matchesFilter(
    m: ControlledMovement,
    filter: ControlFilter,
): boolean {
    const c = controlOf(m);

    if (filter === 'todos') {
        return true;
    }

    if (filter === 'revisar') {
        return c.flags.length > 0;
    }

    return c.status === filter;
}

/**
 * Monto bruto de una fila: cargo + abono (vacíos o inválidos cuentan como 0).
 * @param x fila con cargo y abono.
 */
export function grossOf(x: { cargo: string; abono: string }): number {
    return (Number(x.cargo) || 0) + (Number(x.abono) || 0);
}

/**
 * Texto en minúsculas con los campos buscables del movimiento (concepto, referencia,
 * folio, archivo, cuenta, beneficiario, tratamiento, fecha y fila).
 * @param m movimiento.
 */
export function searchText(m: ControlledMovement): string {
    return [
        m.concept,
        m.reference,
        m.folio,
        m.file,
        m.account_label,
        m.account_slot,
        m.beneficiary,
        m.treatment_label,
        m.date,
        String(m.source_row ?? ''),
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
}
