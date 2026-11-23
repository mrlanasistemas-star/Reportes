/**
 * Revisión de gastos (opcional) — tipos y HTTP compartidos por los componentes del apartado.
 *
 * Este módulo define:
 * - Los tipos de datos que devuelve el backend (`ExpenseReviewController`) para el listado,
 *   el resumen, las opciones (colaboradores/sucursales) y el catálogo de conceptos.
 * - Las etiquetas visibles de cada filtro del listado.
 * - Un cliente HTTP mínimo (`fetch`) que envía las cabeceras de Laravel (XSRF, AJAX) y
 *   convierte las respuestas con error en `ReviewHttpError` con un mensaje en español.
 *
 * Rutas base: `/historico-general/{periodo}/revision-gastos` (ver `useExpenseReview`).
 */

/**
 * Filtro del listado de gastos:
 * - `pendientes`: gastos que aún requieren revisión (sin colaborador o sin sucursal).
 * - `sin_empleado` / `sin_sucursal`: gastos sin atribución de colaborador / de sucursal.
 * - `revisados`: gastos con decisión manual guardada.
 * - `corporativo`: gastos atribuidos a Corporativo.
 * - `todos`: sin filtro.
 */
export type ReviewFilter =
    | 'pendientes'
    | 'sin_empleado'
    | 'sin_sucursal'
    | 'revisados'
    | 'corporativo'
    | 'todos';

/**
 * Acción sobre el colaborador de un gasto:
 * `asignar` (a un colaborador concreto), `ninguno` (confirmar que no tiene colaborador)
 * o `automatico` (quitar la decisión manual y volver a la atribución automática).
 */
export type EmployeeAction = 'asignar' | 'ninguno' | 'automatico';

/**
 * Acción sobre la sucursal de un gasto:
 * `asignar` (sucursal concreta), `corporativo`, `ninguna` (confirmar sin sucursal)
 * o `automatico` (volver a la atribución automática).
 */
export type BranchAction = 'asignar' | 'corporativo' | 'ninguna' | 'automatico';

/** Referencia mínima a una entidad (colaborador o sucursal): id y nombre visible. */
export interface Ref2 {
    id: number;
    name: string;
}

/** Clasificación contable de un gasto/concepto (OPEX, nómina, etc.) calculada en backend. */
export interface ExpenseClassification {
    /** Tipo interno de clasificación. */
    type: string;
    /** `true` si el gasto cuenta como OPEX en el Estado de Resultados. */
    is_opex: boolean;
    /** Línea del reporte a la que va el gasto (si aplica). */
    line: string | null;
    /** Etiqueta legible de la clasificación. */
    label: string;
    /** Texto de ayuda opcional que explica la clasificación. */
    hint: string | null;
}

/** Fila del listado de gastos del periodo, con su atribución actual y decisión manual (si existe). */
export interface ExpenseRow {
    /** Id del gasto en BD. */
    id: number;
    /** Huella estable del movimiento (sobrevive a reimportaciones). */
    fingerprint: string | null;
    /** Código de la fuente de origen (p. ej. Lendus, ERP). */
    source_code: string;
    /** Nombre visible de la fuente. */
    source_label: string;
    /** Fecha del movimiento (ISO) o `null`. */
    date: string | null;
    concept: string | null;
    /** Categoría efectiva (tras aplicar reglas de concepto). */
    category: string | null;
    /** Categoría original antes de aplicar una regla de concepto. */
    category_before_rule: string | null;
    observations: string | null;
    amount: number;
    /** Colaborador atribuido actualmente (automático o manual). */
    employee: Ref2 | null;
    /** Sucursal atribuida actualmente (automática o manual). */
    branch: Ref2 | null;
    /** Método de atribución (código interno) y su etiqueta visible. */
    attribution_method: string | null;
    attribution_label: string;
    /** `true` si el gasto sigue pendiente de revisión. */
    needs_review: boolean;
    employee_review_status: 'asignado' | 'sin_empleado' | null;
    branch_review_status: 'asignada' | 'corporativo' | 'sin_sucursal' | null;
    classification: ExpenseClassification;
    /** Decisión manual guardada para este gasto, o `null` si usa la atribución automática. */
    decision: {
        employee_decision: string | null;
        employee: Ref2 | null;
        branch_decision: string | null;
        branch: Ref2 | null;
        notes: string | null;
        /** Nombre del usuario que guardó la decisión y fecha de la última modificación. */
        updated_by: string | null;
        updated_at: string | null;
    } | null;
}

/** Conteo de gastos y suma de montos de un grupo. */
export interface GroupTotal {
    count: number;
    total: number;
}

/** Resumen del periodo que alimenta las tarjetas superiores del apartado. */
export interface ReviewSummary {
    total: GroupTotal;
    sin_empleado: GroupTotal;
    sin_sucursal: GroupTotal;
    corporativo: GroupTotal;
    /** Gastos confirmados manualmente como «sin colaborador» / «sin sucursal». */
    confirmados_sin_empleado: number;
    confirmados_sin_sucursal: number;
    /** Gastos con decisión manual. */
    revisados: number;
    /** Desglose por fuente de origen. */
    by_source: Array<{
        code: string;
        label: string;
        count: number;
        total: number;
    }>;
}

/** Página del listado: filas, paginación, total monetario del filtro y resumen general. */
export interface ReviewPage {
    rows: ExpenseRow[];
    pagination: {
        page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
    /** Suma de montos de todos los gastos que cumplen el filtro actual (no solo la página). */
    filtered_total: number;
    summary: ReviewSummary;
}

/** Opciones del apartado (catálogos y permisos), cargadas una vez por periodo. */
export interface ReviewOptions {
    /** `false` si las tablas de revisión aún no existen (migración pendiente). */
    installed: boolean;
    /** Colaboradores elegibles para asignar, con su sucursal de referencia. */
    employees: Array<{
        id: number;
        name: string;
        branch_id: number | null;
        branch_name: string | null;
    }>;
    branches: Ref2[];
    /** `true` si existe la sucursal Corporativo para asignarla. */
    corporate_available: boolean;
    sources: Array<{ code: string; label: string }>;
    /** `true` si el usuario puede guardar decisiones en este periodo. */
    can_edit: boolean;
    /** `true` si el periodo es el general (consolidado). */
    is_general: boolean;
    /** `true` si el periodo está cerrado (solo lectura). */
    is_closed: boolean;
}

/** Concepto de gasto agrupado (catálogo de conceptos) con su categoría y regla manual. */
export interface ConceptRow {
    /** Clave única del concepto (fuente + concepto). */
    key: string;
    source_type: 'lendus' | 'erp';
    source_label: string;
    concept: string;
    /** Categoría efectiva y la que se asignaría automáticamente sin regla. */
    category: string;
    category_auto: string | null;
    count: number;
    total: number;
    classification: ExpenseClassification;
    /** `true` si el concepto no tiene destino claro en el reporte y conviene asignarle categoría. */
    needs_destination: boolean;
    /** Regla manual guardada para el concepto, o `null`. */
    rule: { category: string; updated_at: string | null } | null;
}

/** Fila informativa de ingresos agrupados por transacción/operación y su destino en el reporte. */
export interface IncomeRow {
    transaction: string;
    operation: string;
    count: number;
    total: number;
    /** Línea del reporte a la que va el ingreso. */
    destination: string;
    /** `true` si el ingreso cuenta en el Estado de Resultados. */
    counts: boolean;
}

/** Respuesta del catálogo de conceptos: gastos, ingresos y lista de categorías disponibles. */
export interface ConceptsPayload {
    gastos: ConceptRow[];
    ingresos: IncomeRow[];
    categories: string[];
}

/** Cuerpo de la decisión que se envía al guardar (colaborador y/o sucursal y notas). */
export interface DecisionPayload {
    employee_action?: EmployeeAction | null;
    employee_id?: number | null;
    branch_action?: BranchAction | null;
    branch_id?: number | null;
    notes?: string | null;
}

/** Etiquetas visibles de cada filtro del listado (pestañas de la barra de herramientas). */
export const FILTER_LABELS: Record<ReviewFilter, string> = {
    pendientes: 'Pendientes',
    sin_empleado: 'Sin colaborador',
    sin_sucursal: 'Sin sucursal',
    revisados: 'Revisados',
    corporativo: 'Corporativo',
    todos: 'Todos',
};

/**
 * Error HTTP del apartado: lleva el mensaje listo para mostrar al usuario y el código HTTP.
 */
export class ReviewHttpError extends Error {
    /**
     * @param message Mensaje en español (errores de validación unidos o mensaje del servidor).
     * @param status  Código HTTP de la respuesta (0 si no aplica).
     */
    constructor(
        message: string,
        public readonly status = 0,
    ) {
        super(message);
    }
}

/**
 * Construye las cabeceras de la petición: JSON/AJAX y el token XSRF leído de la cookie de Laravel.
 *
 * @param json `true` para añadir `Content-Type: application/json` (peticiones con cuerpo).
 * @returns Mapa de cabeceras.
 */
function headers(json = false): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(json ? { 'Content-Type': 'application/json' } : {}),
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    };
}

/**
 * Lee el JSON de la respuesta. Si la respuesta no es 2xx lanza `ReviewHttpError` con los
 * errores de validación concatenados, el `message` del servidor o un mensaje genérico.
 *
 * @param res Respuesta de `fetch`.
 * @returns Cuerpo tipado.
 * @throws ReviewHttpError si la respuesta no es exitosa.
 */
async function parse<T>(res: Response): Promise<T> {
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
        const errors = body?.errors
            ? Object.values(body.errors).flat().join(' ')
            : '';

        throw new ReviewHttpError(
            errors ||
                body?.message ||
                'No se pudo completar la operación. Intenta de nuevo.',
            res.status,
        );
    }

    return body as T;
}

/**
 * GET con parámetros de consulta; omite los parámetros `undefined` o vacíos.
 *
 * @param url    Ruta del backend.
 * @param params Parámetros de la query string.
 * @returns Cuerpo JSON tipado.
 * @throws ReviewHttpError si la respuesta no es exitosa.
 */
export async function reviewGet<T>(
    url: string,
    params: Record<string, string | number | undefined> = {},
): Promise<T> {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== '') {
            qs.set(k, String(v));
        }
    });

    return parse<T>(
        await fetch(qs.toString() ? `${url}?${qs}` : url, {
            headers: headers(),
        }),
    );
}

/**
 * POST con cuerpo JSON.
 *
 * @param url  Ruta del backend.
 * @param body Cuerpo a serializar.
 * @returns Cuerpo JSON tipado.
 * @throws ReviewHttpError si la respuesta no es exitosa.
 */
export async function reviewPost<T>(url: string, body: unknown): Promise<T> {
    return parse<T>(
        await fetch(url, {
            method: 'POST',
            headers: headers(true),
            body: JSON.stringify(body),
        }),
    );
}
