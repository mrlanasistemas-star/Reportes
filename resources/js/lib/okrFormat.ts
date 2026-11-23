// Módulo OKR — helpers de formato reutilizables (sección AJ del pedido):
// antes cada .vue repetía su propio Intl.NumberFormat/toFixed a mano, con
// pequeñas inconsistencias entre pantallas. Ahora TODO el módulo formatea
// desde aquí — una sola fuente de verdad para moneda/porcentaje/enteros/pp.

/** Moneda MXN con 2 decimales; '—' si viene vacío o no es numérico. */
export function formatCurrency(
    value: number | string | null | undefined,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = Number(value);

    if (Number.isNaN(n)) {
        return '—';
    }

    return n.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** Porcentaje con `digits` decimales (valor en escala 0–100); '—' si vacío/no numérico. */
export function formatPercentage(
    value: number | string | null | undefined,
    digits = 2,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = Number(value);

    if (Number.isNaN(n)) {
        return '—';
    }

    return `${n.toFixed(digits)}%`;
}

/** Entero redondeado con separadores de miles; '—' si vacío/no numérico. */
export function formatInteger(
    value: number | string | null | undefined,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = Number(value);

    if (Number.isNaN(n)) {
        return '—';
    }

    return Math.round(n).toLocaleString('es-MX');
}

/** Decimal con `digits` decimales fijos y separadores es-MX; '—' si vacío/no numérico. */
export function formatDecimal(
    value: number | string | null | undefined,
    digits = 2,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = Number(value);

    if (Number.isNaN(n)) {
        return '—';
    }

    return n.toLocaleString('es-MX', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

/** Puntos porcentuales (desviación) con 1 decimal y signo '+' explícito si es positivo. */
export function formatPp(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = Number(value);

    if (Number.isNaN(n)) {
        return '—';
    }

    const sign = n > 0 ? '+' : '';

    return `${sign}${n.toFixed(1)} pp`;
}

/** Formatea según la unidad declarada del KPI (currency|percentage|integer|decimal); otra unidad → texto tal cual. */
export function formatByUnit(
    value: number | string | null | undefined,
    unit?: string | null,
): string {
    switch (unit) {
        case 'currency':
            return formatCurrency(value);
        case 'percentage':
            return formatPercentage(value);
        case 'integer':
            return formatInteger(value);
        case 'decimal':
            return formatDecimal(value);
        default:
            return value === null || value === undefined ? '—' : String(value);
    }
}

/** Fecha amigable en UI (ej. "08 sep 2026") — el backend sigue mandando ISO; si no se puede interpretar se devuelve el texto original. */
export function formatFriendlyDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    // 'YYYY-MM-DD' es una fecha de calendario: se arma en hora LOCAL (con
    // new Date('YYYY-MM-DD') el navegador la toma en UTC y en México mostraría el
    // día anterior). Un timestamp completo ('…T17:42:22.000000Z', como serializa
    // Laravel) es un instante: se muestra el día que corresponde en hora local,
    // nunca el de UTC que daría recortarlo con slice(0, 10).
    const parsed = /^\d{4}-\d{2}-\d{2}$/.test(value)
        ? new Date(`${value}T00:00:00`)
        : new Date(value.includes('T') ? value : value.replace(' ', 'T'));

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    })
        .format(parsed)
        .replace('.', '');
}

/** 'YYYY-MM-DD' del día LOCAL de `date` (no de UTC, como haría toISOString()). */
export function toLocalIsoDate(date: Date = new Date()): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

/**
 * Fecha + hora amigable (ej. "08 sep 2026, 5:42 p.m.") — para timestamps CON hora
 * (historial de auditoría, bitácora), a diferencia de formatFriendlyDate() que
 * asume una fecha pura. Cierre 17-sep-2026 ronda 5: antes el historial del OKR
 * mostraba el timestamp crudo tal cual venía de la BD ("2026-09-08 17:42:22").
 */
export function formatFriendlyDateTime(
    value: string | null | undefined,
): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(
        value.includes('T') ? value : value.replace(' ', 'T'),
    );

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
        .format(parsed)
        .replace('.', '');
}
