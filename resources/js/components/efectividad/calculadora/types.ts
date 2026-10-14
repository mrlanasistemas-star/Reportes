export type Modo = 'DIARIO' | 'SEMANAL' | 'MENSUAL';

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

export type OpcionCorte = {
    valor: string;
    etiqueta: string;
    ingresos_ok: boolean;
    historial_previo?: boolean;
    semanas?: number;
};

export type ResumenFuentes = {
    carteras: ArchivoCargado[];
    ingresos: ArchivoCargado[];
    semana: {
        titulo: string;
        ingresos_ok: boolean;
        dias: Array<{
            dia: string;
            fecha: string;
            disponible: boolean;
            archivo: string | null;
            contratos: number | null;
        }>;
    } | null;
    cortes: Record<Modo, OpcionCorte[]>;
    expira_minutos: number;
};

export type Rechazado = { nombre: string; error: string };

export type FilaEfectividad = {
    tipo: 'promotor' | 'subtotal' | 'gran_total';
    oficina: string | null;
    oficina_titulo: string | null;
    promotor: string;
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
};

export type Incidencia = {
    id: string;
    codigo: string;
    contrato: string | null;
    referencia: string | null;
    fecha: string | null;
    importe: number | null;
    descripcion: string;
};

export type Resultado = {
    modo: Modo;
    titulo: string;
    leyenda: string;
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
    semanas: Array<{ titulo: string; corte: string; gran_total: FilaEfectividad | null }>;
    catalogo: Array<{ oficina: string; promotores: string[] }>;
};

export function xsrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    };
}

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

const money = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
});

export const fmtMoney = (v: number) => money.format(v);
export const fmtPct = (v: number) =>
    `${(v * 100).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
export const fmtFecha = (ymd: string | null) =>
    ymd ? ymd.split('-').reverse().join('/') : '—';

/** Semáforo de la macro: ≥95% verde, ≥90% ámbar, resto rojo (solo con Sano por cobrar > 0). */
export function semaforo(f: FilaEfectividad): string {
    if (f.sano_pc <= 0) {
        return '';
    }

    if (f.pct_total >= 0.95) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    return f.pct_total >= 0.9
        ? 'text-amber-600 dark:text-amber-400'
        : 'text-red-600 dark:text-red-400';
}
