import { moneyCompact, percent } from '@/lib/format'

/** Shared financial color palette — matches the Excel/PDF radiografía palette. */
export const chartColors = {
    teal: '#106A59',
    tealLight: '#1DC1A2',
    blue: '#5B9BD5',
    red: '#DC2626',
    amber: '#D97706',
    gray: '#94A3B8',
    green: '#16A34A',
} as const

/** Rotating palette for multi-category charts (ranking, productos, etc). */
export const categoryPalette = ['#106A59', '#5B9BD5', '#1DC1A2', '#D97706', '#94A3B8', '#0EA5E9', '#7C3AED', '#DC2626']

const fontFamily = 'ui-sans-serif, system-ui, -apple-system, sans-serif'

function baseChart(extra: Record<string, any> = {}) {
    return {
        chart: { toolbar: { show: false }, fontFamily, animations: { speed: 250 }, ...extra.chart },
        grid: { borderColor: '#eef2f7', strokeDashArray: 3, ...extra.grid },
        dataLabels: { enabled: false, ...extra.dataLabels },
        legend: { fontFamily, fontSize: '11px', labels: { colors: '#475569' }, ...extra.legend },
        tooltip: { theme: 'light', ...extra.tooltip },
        states: { hover: { filter: { type: 'lighten' } } },
    }
}

/** Horizontal bar ranking — sucursales, gestores, top conceptos, etc. */
export function horizontalBarOptions(categories: string[], colors: string[] = [chartColors.teal]) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '62%' } },
        colors,
        xaxis: {
            categories,
            labels: { style: { colors: '#64748b', fontSize: '10px' }, formatter: (v: number) => moneyCompact(v) },
        },
        yaxis: { labels: { style: { colors: '#334155', fontSize: '10.5px' } } },
        tooltip: { theme: 'light', y: { formatter: (v: number) => money(v) } },
    }
}

/** Vertical column comparison — recuperación vs colocación, etc. */
export function columnOptions(categories: string[], colors: string[] = [chartColors.teal, chartColors.blue]) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 6 } },
        colors,
        xaxis: { categories, labels: { style: { colors: '#64748b', fontSize: '10.5px' } } },
        yaxis: { labels: { style: { colors: '#64748b', fontSize: '10px' }, formatter: (v: number) => moneyCompact(v) } },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    }
}

/** Stacked horizontal/vertical bars — mora por bucket, nómina por concepto, etc. */
export function stackedBarOptions(categories: string[], colors: string[] = categoryPalette, horizontal = false) {
    return {
        ...baseChart({ chart: { type: 'bar', stacked: true } }),
        plotOptions: { bar: { horizontal, borderRadius: 4 } },
        colors,
        xaxis: {
            categories,
            labels: horizontal
                ? { style: { colors: '#64748b', fontSize: '10px' }, formatter: (v: number) => moneyCompact(v) }
                : { style: { colors: '#64748b', fontSize: '10.5px' } },
        },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    }
}

/** Vertical column comparison for percentages (mora, margen EBITDA, rotación, etc.) — nunca en el mismo eje que cifras en millones. */
export function percentColumnOptions(categories: string[], colors: string[] = [chartColors.teal, chartColors.blue]) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 6 } },
        colors,
        xaxis: { categories, labels: { style: { colors: '#64748b', fontSize: '10.5px' } } },
        yaxis: { labels: { style: { colors: '#64748b', fontSize: '10px' }, formatter: (v: number) => `${Number(v ?? 0).toFixed(0)}%` } },
        tooltip: { y: { formatter: (v: number) => percent(v) } },
    }
}

/** Vertical column comparison for plain counts (headcount, altas/bajas, etc.) — no $ formatting. */
export function countColumnOptions(categories: string[], colors: string[] = [chartColors.teal, chartColors.blue]) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 6 } },
        colors,
        xaxis: { categories, labels: { style: { colors: '#64748b', fontSize: '10.5px' } } },
        yaxis: { labels: { style: { colors: '#64748b', fontSize: '10px' }, formatter: (v: number) => Math.round(v).toString() } },
        tooltip: { y: { formatter: (v: number) => Math.round(v).toString() } },
    }
}

/** Donut for plain counts (altas vs bajas, etc.) — center total and labels show counts, not money. */
export function countDonutOptions(labels: string[], colors: string[] = categoryPalette) {
    return {
        ...baseChart({ chart: { type: 'donut' } }),
        labels,
        colors,
        legend: { position: 'bottom', fontFamily, fontSize: '11px', labels: { colors: '#475569' } },
        dataLabels: {
            enabled: true,
            formatter: (v: number) => percent(v),
            style: { fontSize: '10px', fontWeight: 700 },
        },
        plotOptions: { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: 'Total', formatter: (w: any) => Math.round(w.globals.seriesTotals.reduce((a: number, b: number) => a + b, 0)).toString() } } } } },
        tooltip: { y: { formatter: (v: number) => Math.round(v).toString() } },
    }
}

/** Donut — cartera sana vs vencida, categoría EBITDA, etc. */
export function donutOptions(labels: string[], colors: string[] = categoryPalette) {
    return {
        ...baseChart({ chart: { type: 'donut' } }),
        labels,
        colors,
        legend: { position: 'bottom', fontFamily, fontSize: '11px', labels: { colors: '#475569' } },
        dataLabels: {
            enabled: true,
            formatter: (v: number) => percent(v),
            style: { fontSize: '10px', fontWeight: 700 },
        },
        plotOptions: { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: 'Total', formatter: (w: any) => moneyCompact(w.globals.seriesTotals.reduce((a: number, b: number) => a + b, 0)) } } } } },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    }
}

/** Radial gauge (uno o más anillos concéntricos) — para una sola métrica porcentual comparada entre 2+ series (ej. Mora Periodo A vs B). */
export function radialBarOptions(labels: string[], colors: string[] = [chartColors.blue, chartColors.teal]) {
    return {
        ...baseChart({ chart: { type: 'radialBar' } }),
        labels,
        colors,
        legend: { show: false },
        plotOptions: {
            radialBar: {
                hollow: { size: '42%' },
                track: { background: '#eef2f7' },
                dataLabels: {
                    name: { fontSize: '11px', color: '#64748b' },
                    value: { fontSize: '15px', fontWeight: 700, formatter: (v: number) => percent(v) },
                    total: { show: labels.length > 1, label: 'Promedio', fontSize: '11px', color: '#64748b', formatter: (w: any) => percent(w.globals.seriesTotals.reduce((a: number, b: number) => a + b, 0) / w.globals.seriesTotals.length) },
                },
            },
        },
        tooltip: { y: { formatter: (v: number) => percent(v) } },
    }
}

function money(v: number) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v)
}
