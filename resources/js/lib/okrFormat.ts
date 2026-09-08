// Módulo OKR — helpers de formato reutilizables (sección AJ del pedido):
// antes cada .vue repetía su propio Intl.NumberFormat/toFixed a mano, con
// pequeñas inconsistencias entre pantallas. Ahora TODO el módulo formatea
// desde aquí — una sola fuente de verdad para moneda/porcentaje/enteros/pp.

export function formatCurrency(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—'
    const n = Number(value)
    if (Number.isNaN(n)) return '—'
    return n.toLocaleString('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function formatPercentage(value: number | string | null | undefined, digits = 2): string {
    if (value === null || value === undefined || value === '') return '—'
    const n = Number(value)
    if (Number.isNaN(n)) return '—'
    return `${n.toFixed(digits)}%`
}

export function formatInteger(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—'
    const n = Number(value)
    if (Number.isNaN(n)) return '—'
    return Math.round(n).toLocaleString('es-MX')
}

export function formatDecimal(value: number | string | null | undefined, digits = 2): string {
    if (value === null || value === undefined || value === '') return '—'
    const n = Number(value)
    if (Number.isNaN(n)) return '—'
    return n.toLocaleString('es-MX', { minimumFractionDigits: digits, maximumFractionDigits: digits })
}

/** Puntos porcentuales (desviación) — siempre con signo explícito. */
export function formatPp(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—'
    const n = Number(value)
    if (Number.isNaN(n)) return '—'
    const sign = n > 0 ? '+' : ''
    return `${sign}${n.toFixed(1)} pp`
}

/** Formatea según la unidad declarada del KPI (currency|percentage|integer|decimal). */
export function formatByUnit(value: number | string | null | undefined, unit?: string | null): string {
    switch (unit) {
        case 'currency': return formatCurrency(value)
        case 'percentage': return formatPercentage(value)
        case 'integer': return formatInteger(value)
        case 'decimal': return formatDecimal(value)
        default: return value === null || value === undefined ? '—' : String(value)
    }
}

/** Fecha amigable en UI (ej. "08 sep 2026") — backend sigue mandando ISO. */
export function formatFriendlyDate(value: string | null | undefined): string {
    if (!value) return '—'
    const parsed = new Date(`${value}T00:00:00`)
    if (Number.isNaN(parsed.getTime())) return value
    return new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }).format(parsed).replace('.', '')
}
