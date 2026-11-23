import type { DisplayState } from './types';

/**
 * Utilidades de formato y textos del módulo de Efectividad de Cobranza
 * (estados de ejecución, fechas, duraciones, números y errores HTTP).
 *
 * BadgeVariant: variantes del componente Badge usadas por cada estado.
 */
type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

/** Estados visibles — mismos textos que EfectividadCobranzaRunHealthService::label(). */
export const STATE_META: Record<
    DisplayState,
    { label: string; variant: BadgeVariant; tone: string }
> = {
    queued: {
        label: 'En cola',
        variant: 'secondary',
        tone: 'text-sky-700 bg-sky-50 border-sky-200 dark:text-sky-300 dark:bg-sky-950/40 dark:border-sky-900',
    },
    running: {
        label: 'Procesando',
        variant: 'secondary',
        tone: 'text-blue-700 bg-blue-50 border-blue-200 dark:text-blue-300 dark:bg-blue-950/40 dark:border-blue-900',
    },
    retrying: {
        label: 'Reintentando',
        variant: 'secondary',
        tone: 'text-violet-700 bg-violet-50 border-violet-200 dark:text-violet-300 dark:bg-violet-950/40 dark:border-violet-900',
    },
    delayed: {
        label: 'Retrasado',
        variant: 'outline',
        tone: 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-300 dark:bg-amber-950/40 dark:border-amber-900',
    },
    stalled: {
        label: 'Sin actividad del worker',
        variant: 'destructive',
        tone: 'text-orange-700 bg-orange-50 border-orange-200 dark:text-orange-300 dark:bg-orange-950/40 dark:border-orange-900',
    },
    blocked: {
        label: 'Bloqueado por datos',
        variant: 'outline',
        tone: 'text-amber-800 bg-amber-50 border-amber-300 dark:text-amber-200 dark:bg-amber-950/40 dark:border-amber-800',
    },
    failed: {
        label: 'Error',
        variant: 'destructive',
        tone: 'text-red-700 bg-red-50 border-red-200 dark:text-red-300 dark:bg-red-950/40 dark:border-red-900',
    },
    success: {
        label: 'Terminado',
        variant: 'default',
        tone: 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/40 dark:border-emerald-900',
    },
    obsolete: {
        label: 'Obsoleto',
        variant: 'outline',
        tone: 'text-muted-foreground bg-muted border-border',
    },
    cancelling: {
        label: 'Cancelación solicitada',
        variant: 'outline',
        tone: 'text-slate-700 bg-slate-50 border-slate-300 dark:text-slate-300 dark:bg-slate-950/40 dark:border-slate-800',
    },
    cancelled: {
        label: 'Cancelado',
        variant: 'outline',
        tone: 'text-muted-foreground bg-muted border-border',
    },
};

/** Opciones del filtro de estado en el historial de reportes. */
export const STATUS_FILTER_OPTIONS = [
    { value: '', label: 'Todos los estados' },
    { value: 'queued', label: 'En cola' },
    { value: 'running', label: 'Procesando' },
    { value: 'retrying', label: 'Reintentando' },
    { value: 'success', label: 'Terminado' },
    { value: 'blocked', label: 'Bloqueado por datos' },
    { value: 'failed', label: 'Error' },
    { value: 'cancelled', label: 'Cancelado' },
];

/** Textos en español para cada tipo de falla reportado por el servidor. */
export const FAILURE_KIND_LABELS: Record<string, string> = {
    technical: 'Error técnico',
    missing_data: 'Datos faltantes',
    missing_columns: 'Columnas faltantes',
    partial_coverage: 'Cobertura parcial',
    incompatible: 'Información incompatible',
    stale: 'Datos obsoletos',
    file_missing: 'Archivo físico perdido',
    worker_stalled: 'Worker sin actividad',
    timeout: 'Tiempo máximo excedido',
};

/** Estados que indican que la ejecución sigue en curso. */
export const ACTIVE_STATUSES = ['queued', 'running', 'retrying'];

/**
 * Indica si un estado corresponde a una ejecución en curso.
 * @param status estado recibido del servidor
 */
export function isActiveStatus(status: string | null | undefined): boolean {
    return !!status && ACTIVE_STATUSES.includes(status);
}

/** Formateador de fecha y hora (es-MX, fecha media y hora corta). */
const dateTimeFmt = new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
/** Formateador de hora con segundos (es-MX). */
const timeFmt = new Intl.DateTimeFormat('es-MX', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
});

/** Formatea una fecha ISO como fecha y hora; '—' si no hay valor. */
export function formatDateTime(iso: string | null | undefined): string {
    return iso ? dateTimeFmt.format(new Date(iso)) : '—';
}

/** Formatea una fecha ISO solo como hora; '—' si no hay valor. */
export function formatTime(iso: string | null | undefined): string {
    return iso ? timeFmt.format(new Date(iso)) : '—';
}

/**
 * Convierte segundos a texto legible ("1 h 5 min", "3 min 07 s", "12 s").
 * @param seconds duración en segundos; '—' si no hay valor
 */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;

    if (h > 0) {
        return `${h} h ${m} min`;
    }

    if (m > 0) {
        return `${m} min ${sec.toString().padStart(2, '0')} s`;
    }

    return `${sec} s`;
}

/**
 * Segundos transcurridos desde una fecha ISO hasta `now` (nunca negativo).
 * @param iso fecha de referencia
 * @param now instante actual en milisegundos
 */
export function secondsSince(
    iso: string | null | undefined,
    now: number,
): number | null {
    return iso
        ? Math.max(0, Math.floor((now - new Date(iso).getTime()) / 1000))
        : null;
}

/** Formatea un número con separadores es-MX; '—' si no hay valor. */
export function formatNumber(value: number | null | undefined): string {
    return value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('es-MX').format(value);
}

/** Formatea un importe en pesos (MXN) sin decimales; '—' si no hay valor. */
export function formatMoney(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        maximumFractionDigits: 0,
    }).format(value);
}

/** Formatea una fracción (0.25) como porcentaje con un decimal; '—' si no hay valor. */
export function formatPct(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return new Intl.NumberFormat('es-MX', {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(value);
}

/** Lee el token CSRF de la meta etiqueta (mismo mecanismo que el resto del módulo). */
export function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/** Traduce una respuesta HTTP fallida a un mensaje real para el usuario. */
export async function describeHttpFailure(
    res: Response | null,
    error: unknown,
): Promise<string> {
    if (!res) {
        return error instanceof Error && error.name === 'AbortError'
            ? 'La solicitud tardó demasiado y se canceló. Verifica tu conexión e intenta de nuevo.'
            : 'No se pudo conectar con el servidor. Verifica tu conexión a internet e intenta de nuevo.';
    }

    if (res.status === 401 || res.status === 419) {
        return 'Tu sesión expiró por inactividad. Inicia sesión de nuevo para continuar.';
    }

    if (res.status === 403) {
        return 'No tienes permiso para realizar esta acción.';
    }

    if (res.status === 404) {
        return 'El reporte solicitado ya no existe.';
    }

    if (res.status === 409 || res.status === 503) {
        try {
            const body = await res.json();

            return (
                body?.error ??
                'No fue posible iniciar la generación. Intenta de nuevo en unos segundos.'
            );
        } catch {
            return 'No fue posible iniciar la generación. Intenta de nuevo en unos segundos.';
        }
    }

    if (res.status === 422) {
        try {
            const body = await res.json();
            const firstError = body?.errors
                ? Object.values(body.errors)[0]
                : null;

            return Array.isArray(firstError)
                ? String(firstError[0])
                : (body?.message ?? 'Selecciona un periodo válido.');
        } catch {
            return 'Selecciona un periodo válido.';
        }
    }

    if (res.status >= 500) {
        try {
            const body = await res.json();

            if (typeof body?.message === 'string' && body?.reference) {
                return body.message;
            }
        } catch {
            // sin cuerpo JSON: mensaje genérico de error técnico
        }

        return 'No pudimos completar la operación por un error técnico. Intenta nuevamente; si continúa, informa a Sistemas.';
    }

    return `No se pudo completar la solicitud (código ${res.status}).`;
}
