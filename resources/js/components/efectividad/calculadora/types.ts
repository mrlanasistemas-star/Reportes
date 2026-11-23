/**
 * Tipos y utilidades de la calculadora de Efectividad de Cobranza (cálculo
 * efímero, sin guardar en BD). Los comparten la página EfectividadCobranza/Index
 * y los componentes de esta carpeta.
 *
 * Modo: modalidad del cálculo (diario, acumulado, semanal o mensual).
 */
export type Modo = 'DIARIO' | 'ACUMULADO' | 'SEMANAL' | 'MENSUAL';

/** Archivo leído en la sesión (cartera «Saldos por Cliente» o «Ingresos Cobranza») con su resumen. */
export type ArchivoCargado = {
    id: string;
    nombre: string;
    tipo: 'cartera' | 'ingresos';
    fecha: string | null;
    desde: string | null;
    hasta: string | null;
    resumen: {
        registros: number;
        contratos: number;
        oficinas: number;
        columnas_archivo: number;
    };
};

/** Requisito de un corte: una cartera, unos ingresos o una semana, y si ya está disponible. */
export type RequisitoCorte = {
    tipo: 'cartera' | 'ingresos' | 'semana';
    etiqueta: string;
    fecha?: string;
    disponible: boolean;
    detalle?: string | null;
};

/** Un corte calculable con sus requisitos exactos (solo los que usa esa modalidad). */
export type OpcionCorte = {
    valor: string;
    etiqueta: string;
    titulo: string;
    desde?: string;
    requisitos: RequisitoCorte[];
    carteras_ok: boolean;
    ingresos_ok: boolean;
    listo: boolean;
    estado: 'listo' | 'faltan_ingresos' | 'faltan_carteras';
    falta: string | null;
    historial_previo?: boolean;
    semanas?: number;
};

/**
 * Estado de las fuentes cargadas: carteras, ingresos, cortes calculables por
 * modalidad, sucursales detectadas y minutos antes de que expire la sesión.
 */
export type ResumenFuentes = {
    carteras: ArchivoCargado[];
    ingresos: ArchivoCargado[];
    cortes: Record<Modo, OpcionCorte[]>;
    sucursales?: Array<{
        sucursal: string;
        rutas: string[];
        en_catalogo: boolean;
    }>;
    expira_minutos: number;
};

/** Archivo rechazado al subir, con el motivo. */
export type Rechazado = { nombre: string; error: string };

/** Fila de la tabla de efectividad: promotor, subtotal de ruta, subtotal de sucursal o gran total. */
export type FilaEfectividad = {
    tipo: 'promotor' | 'subtotal' | 'subtotal_sucursal' | 'gran_total';
    /** Sucursal (catálogo de rutas); null en el GRAN TOTAL. */
    sucursal?: string | null;
    /** RUTA de Lendus (columna "Oficina" del Excel). */
    oficina: string | null;
    oficina_titulo: string | null;
    promotor: string;
    sano_pc: number;
    sano_cob: number;
    /** Porcentajes sobre Sano por cobrar; null = sin base (Sano por cobrar = 0). */
    sano_pct: number | null;
    atraso_pc: number;
    atraso_cob: number;
    atraso_pct: number | null;
    vencido_pc: number;
    vencido_cob: number;
    vencido_pct: number | null;
    cobranza_total: number;
    /** Efectividad total sin tope (puede superar 100%); null = sin base. */
    pct_total: number | null;
    /** Puede ser negativa cuando la efectividad supera el 100%. */
    mora: number;
};

/** Estado del control: PRELIMINAR (hay pendientes) o CONCILIADO. */
export type EstadoAuditoria = 'PRELIMINAR' | 'CONCILIADO';

/** Control y trazabilidad del cálculo (opcional: resultados anteriores no lo traen). */
export type Auditoria = {
    estado: EstadoAuditoria;
    estado_texto: string;
    contratos_ajustados: number;
    cuotas_inferidas: number;
    importe_inferido: number;
    contratos_con_diferencia: number;
    diferencia_sin_asignar: number;
    desapariciones_sin_soporte: number;
    saldo_sin_soporte: number;
    cierres_refi_comprobados: number;
};

/** Incidencia detectada durante el cálculo (contrato, referencia, fecha, importe). */
export type Incidencia = {
    id: string;
    codigo: string;
    contrato: string | null;
    referencia: string | null;
    fecha: string | null;
    importe: number | null;
    descripcion: string;
};

/**
 * Resultado completo de un cálculo: parámetros de la ventana, filas, incidencias,
 * notas, avisos, totales por semana, catálogo de rutas y control de trazabilidad.
 */
export type Resultado = {
    modo: Modo;
    titulo: string;
    leyenda: string;
    metodologia: string;
    engine_version?: string | null;
    generado: string;
    parametros: {
        modo: string;
        domingo_base: string;
        fecha_corte: string;
        inicio_ingresos: string;
        fin_ingresos: string;
    };
    filas: FilaEfectividad[];
    incidencias: Incidencia[];
    notas: string[];
    avisos: string[];
    sin_movimientos: boolean;
    semanas: Array<{
        titulo: string;
        corte: string;
        gran_total: FilaEfectividad | null;
    }>;
    catalogo: Array<{
        sucursal: string;
        rutas: Array<{ ruta: string; promotores: string[] }>;
    }>;
    auditoria?: Auditoria | null;
};

/** Encabezados JSON para fetch con el token XSRF leído de la cookie de Laravel. */
export function xsrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    };
}

/**
 * Extrae un mensaje legible de una respuesta fallida (primer error de validación,
 * `message` o código HTTP; 413 = archivo demasiado grande).
 * @param res respuesta HTTP no exitosa
 */
export async function mensajeError(res: Response): Promise<string> {
    try {
        const body = (await res.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const primero = body.errors ? Object.values(body.errors)[0]?.[0] : null;

        return primero ?? body.message ?? `Error ${res.status}`;
    } catch {
        return res.status === 413
            ? 'El archivo es demasiado grande para el servidor.'
            : `Error ${res.status} del servidor.`;
    }
}

/** Mora: positiva en rojo; negativa (efectividad > 100%) en verde. */
export function colorMora(v: number): string {
    if (v > 0) {
        return 'text-red-600 dark:text-red-400';
    }

    return v < 0 ? 'text-emerald-600 dark:text-emerald-400' : '';
}

/** Leyenda del resultado separada en sus frases ("A | B | C" → [A, B, C]). */
export const partesLeyenda = (leyenda: string | null | undefined): string[] =>
    (leyenda ?? '')
        .split('|')
        .map((s) => s.trim())
        .filter(Boolean);

/** Formateador de pesos (MXN) con dos decimales. */
const money = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
});

/** Formatea un importe en pesos con dos decimales. */
export const fmtMoney = (v: number) => money.format(v);

/** Hay base para el porcentaje (no null, no NaN, no Infinity). */
export const tienePct = (v: number | null | undefined): v is number =>
    typeof v === 'number' && Number.isFinite(v);

/** Porcentaje sin tope; sin base se muestra "—" (nunca 0.00%, NaN o Infinity). */
export const fmtPct = (v: number | null | undefined) =>
    tienePct(v)
        ? `${(v * 100).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`
        : '—';
/** Convierte 'AAAA-MM-DD' a 'DD/MM/AAAA'; '—' si no hay fecha. */
export const fmtFecha = (ymd: string | null) =>
    ymd ? ymd.split('-').reverse().join('/') : '—';

/** Semáforo: ≥95% verde, ≥90% ámbar, resto rojo. Sin base (Sano por cobrar = 0) → neutro. */
export function semaforo(f: FilaEfectividad): string {
    if (f.sano_pc <= 0 || !tienePct(f.pct_total)) {
        return '';
    }

    if (f.pct_total >= 0.95) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    return f.pct_total >= 0.9
        ? 'text-amber-600 dark:text-amber-400'
        : 'text-red-600 dark:text-red-400';
}
