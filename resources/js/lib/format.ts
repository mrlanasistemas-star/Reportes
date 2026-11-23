/** Formateadores compartidos de número, moneda y porcentaje para el tablero de Radiografía (es-MX, MXN). */

/** Formateadores Intl reutilizados (crearlos una sola vez es más barato). */
const fullCurrency = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
});
const integer = new Intl.NumberFormat('es-MX');

/** Moneda con centavos, p. ej. $1,234,567.89 (null/undefined → $0.00). */
export function money(v: number | null | undefined): string {
    return fullCurrency.format(Number(v ?? 0));
}

/** Moneda compacta para tarjetas y gráficas, p. ej. $1.2M / $850K / $930 (sin decimales a partir de 10M / 10K). */
export function moneyCompact(v: number | null | undefined): string {
    const n = Number(v ?? 0);
    const sign = n < 0 ? '-' : '';
    const abs = Math.abs(n);

    if (abs >= 1_000_000) {
        return `${sign}$${(abs / 1_000_000).toFixed(abs >= 10_000_000 ? 0 : 1)}M`;
    }

    if (abs >= 1_000) {
        return `${sign}$${(abs / 1_000).toFixed(abs >= 10_000 ? 0 : 1)}K`;
    }

    return `${sign}$${abs.toFixed(0)}`;
}

/** Porcentaje con 2 decimales, p. ej. 35.04% (el valor ya viene en escala 0–100). */
export function percent(v: number | null | undefined): string {
    return `${Number(v ?? 0).toFixed(2)}%`;
}

/** Número con separadores de miles. */
export function num(v: number | null | undefined): string {
    return integer.format(Number(v ?? 0));
}

/**
 * Moneda que distingue un $0 real de "no atribuible en este alcance" (null).
 * Nunca convertir null en $0.00: es justo el respaldo silencioso que el tablero
 * con alcance (sucursal/colaborador) no debe hacer (ver RadiographySnapshotBuilder::
 * applyScope()/summaryFromRow(), que emite null en campos no atribuibles).
 * @param label texto a mostrar cuando el valor es null/undefined.
 */
export function moneyOrNa(
    v: number | null | undefined,
    label = 'No atribuible',
): string {
    return v === null || v === undefined ? label : money(v);
}

/** Equivalente de moneyOrNa() para porcentajes. */
export function percentOrNa(
    v: number | null | undefined,
    label = 'No atribuible',
): string {
    return v === null || v === undefined ? label : percent(v);
}
