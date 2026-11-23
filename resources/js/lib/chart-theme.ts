/**
 * Configuración base de gráficas ApexCharts del sistema: paleta financiera,
 * constructores de options por tipo de gráfica (barras, columnas, donas,
 * anillos) y adaptación al tema claro/oscuro (themedChartOptions + useChartDarkMode).
 */
import { useMutationObserver } from '@vueuse/core';
import type { Ref } from 'vue';
import { onMounted, ref } from 'vue';
import { moneyCompact, percent } from '@/lib/format';

/** Paleta financiera compartida — coincide con la del Excel/PDF de la radiografía. */
export const chartColors = {
    teal: '#106A59',
    tealLight: '#1DC1A2',
    blue: '#5B9BD5',
    red: '#DC2626',
    amber: '#D97706',
    gray: '#94A3B8',
    green: '#16A34A',
} as const;

/** Paleta rotativa para gráficas con varias categorías (ranking, productos, etc.). */
export const categoryPalette = [
    '#106A59',
    '#5B9BD5',
    '#1DC1A2',
    '#D97706',
    '#94A3B8',
    '#0EA5E9',
    '#7C3AED',
    '#DC2626',
];

/** Tipografía de todas las gráficas. */
const fontFamily = 'ui-sans-serif, system-ui, -apple-system, sans-serif';

/**
 * Neutros del modo claro que usan los builders de abajo. Son solo el valor por
 * defecto: `themedChartOptions()` los sustituye por los tokens `--chart-text`,
 * `--chart-grid`, etc. del tema activo (claro u oscuro).
 */
const NEUTRAL = {
    text: '#64748b',
    textStrong: '#334155',
    legend: '#475569',
    grid: '#eef2f7',
} as const;

/**
 * Options comunes (sin toolbar, animación corta, rejilla punteada, sin etiquetas
 * de datos, leyenda pequeña). `extra` se mezcla en cada sección.
 */
function baseChart(extra: Record<string, any> = {}) {
    return {
        chart: {
            toolbar: { show: false },
            fontFamily,
            animations: { speed: 250 },
            ...extra.chart,
        },
        grid: { borderColor: NEUTRAL.grid, strokeDashArray: 3, ...extra.grid },
        dataLabels: { enabled: false, ...extra.dataLabels },
        legend: {
            fontFamily,
            fontSize: '11px',
            labels: { colors: NEUTRAL.legend },
            ...extra.legend,
        },
        tooltip: { ...extra.tooltip },
        states: { hover: { filter: { type: 'lighten' } } },
    };
}

/** Ranking en barras horizontales (sucursales, gestores, top conceptos…); eje y tooltip en moneda. */
export function horizontalBarOptions(
    categories: string[],
    colors: string[] = [chartColors.teal],
) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: {
            bar: { horizontal: true, borderRadius: 4, barHeight: '62%' },
        },
        colors,
        xaxis: {
            categories,
            labels: {
                style: { colors: NEUTRAL.text, fontSize: '10px' },
                formatter: (v: number) => moneyCompact(v),
            },
        },
        yaxis: {
            labels: {
                style: { colors: NEUTRAL.textStrong, fontSize: '10.5px' },
            },
        },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    };
}

/** Columnas verticales comparativas en moneda (recuperación vs colocación, etc.). */
export function columnOptions(
    categories: string[],
    colors: string[] = [chartColors.teal, chartColors.blue],
) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 6 } },
        colors,
        xaxis: {
            categories,
            labels: { style: { colors: NEUTRAL.text, fontSize: '10.5px' } },
        },
        yaxis: {
            labels: {
                style: { colors: NEUTRAL.text, fontSize: '10px' },
                formatter: (v: number) => moneyCompact(v),
            },
        },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    };
}

/** Barras apiladas horizontales o verticales en moneda (mora por bucket, nómina por concepto…). */
export function stackedBarOptions(
    categories: string[],
    colors: string[] = categoryPalette,
    horizontal = false,
) {
    return {
        ...baseChart({ chart: { type: 'bar', stacked: true } }),
        plotOptions: { bar: { horizontal, borderRadius: 4 } },
        colors,
        xaxis: {
            categories,
            labels: horizontal
                ? {
                      style: { colors: NEUTRAL.text, fontSize: '10px' },
                      formatter: (v: number) => moneyCompact(v),
                  }
                : { style: { colors: NEUTRAL.text, fontSize: '10.5px' } },
        },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    };
}

/** Columnas verticales para porcentajes (mora, margen EBITDA, rotación…) — nunca en el mismo eje que cifras en millones. */
export function percentColumnOptions(
    categories: string[],
    colors: string[] = [chartColors.teal, chartColors.blue],
) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 6 } },
        colors,
        xaxis: {
            categories,
            labels: { style: { colors: NEUTRAL.text, fontSize: '10.5px' } },
        },
        yaxis: {
            labels: {
                style: { colors: NEUTRAL.text, fontSize: '10px' },
                formatter: (v: number) => `${Number(v ?? 0).toFixed(0)}%`,
            },
        },
        tooltip: { y: { formatter: (v: number) => percent(v) } },
    };
}

/** Columnas verticales para conteos (plantilla, altas/bajas…) — sin formato de moneda. */
export function countColumnOptions(
    categories: string[],
    colors: string[] = [chartColors.teal, chartColors.blue],
) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 6 } },
        colors,
        xaxis: {
            categories,
            labels: { style: { colors: NEUTRAL.text, fontSize: '10.5px' } },
        },
        yaxis: {
            labels: {
                style: { colors: NEUTRAL.text, fontSize: '10px' },
                formatter: (v: number) => Math.round(v).toString(),
            },
        },
        tooltip: { y: { formatter: (v: number) => Math.round(v).toString() } },
    };
}

/** Dona para conteos (altas vs bajas…) — el total central y las etiquetas muestran cantidades, no dinero. */
export function countDonutOptions(
    labels: string[],
    colors: string[] = categoryPalette,
) {
    return {
        ...baseChart({ chart: { type: 'donut' } }),
        labels,
        colors,
        legend: {
            position: 'bottom',
            fontFamily,
            fontSize: '11px',
            labels: { colors: NEUTRAL.legend },
        },
        dataLabels: {
            enabled: true,
            formatter: (v: number) => percent(v),
            style: { fontSize: '10px', fontWeight: 700 },
        },
        plotOptions: {
            pie: {
                donut: {
                    size: '68%',
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: 'Total',
                            formatter: (w: any) =>
                                Math.round(
                                    w.globals.seriesTotals.reduce(
                                        (a: number, b: number) => a + b,
                                        0,
                                    ),
                                ).toString(),
                        },
                    },
                },
            },
        },
        tooltip: { y: { formatter: (v: number) => Math.round(v).toString() } },
    };
}

/** Dona en moneda (cartera sana vs vencida, categoría EBITDA…): % en rebanadas y total compacto al centro. */
export function donutOptions(
    labels: string[],
    colors: string[] = categoryPalette,
) {
    return {
        ...baseChart({ chart: { type: 'donut' } }),
        labels,
        colors,
        legend: {
            position: 'bottom',
            fontFamily,
            fontSize: '11px',
            labels: { colors: NEUTRAL.legend },
        },
        dataLabels: {
            enabled: true,
            formatter: (v: number) => percent(v),
            style: { fontSize: '10px', fontWeight: 700 },
        },
        plotOptions: {
            pie: {
                donut: {
                    size: '68%',
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: 'Total',
                            formatter: (w: any) =>
                                moneyCompact(
                                    w.globals.seriesTotals.reduce(
                                        (a: number, b: number) => a + b,
                                        0,
                                    ),
                                ),
                        },
                    },
                },
            },
        },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    };
}

/** Radial gauge (uno o más anillos concéntricos) — para una sola métrica porcentual comparada entre 2+ series (ej. Mora Periodo A vs B). */
export function radialBarOptions(
    labels: string[],
    colors: string[] = [chartColors.blue, chartColors.teal],
) {
    return {
        ...baseChart({ chart: { type: 'radialBar' } }),
        labels,
        colors,
        legend: { show: false },
        plotOptions: {
            radialBar: {
                hollow: { size: '42%' },
                track: { background: NEUTRAL.grid },
                dataLabels: {
                    name: { fontSize: '11px', color: NEUTRAL.text },
                    value: {
                        fontSize: '15px',
                        fontWeight: 700,
                        formatter: (v: number) => percent(v),
                    },
                    total: {
                        show: labels.length > 1,
                        label: 'Promedio',
                        fontSize: '11px',
                        color: NEUTRAL.text,
                        formatter: (w: any) =>
                            percent(
                                w.globals.seriesTotals.reduce(
                                    (a: number, b: number) => a + b,
                                    0,
                                ) / w.globals.seriesTotals.length,
                            ),
                    },
                },
            },
        },
        tooltip: { y: { formatter: (v: number) => percent(v) } },
    };
}

/** Moneda MXN completa para tooltips (local a este módulo). */
function money(v: number) {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(v);
}

// ════════════════════════════════════════════════════════════════════════════
// TEMA CLARO / OSCURO — una sola puerta para todas las gráficas ApexCharts.
// ApexCharts no lee variables CSS ni la clase `.dark` de Tailwind, así que aquí
// se traducen los tokens del tema (styles/theme.css) a colores explícitos.
// ════════════════════════════════════════════════════════════════════════════

/** Colores del tema activo leídos de las variables CSS (theme.css). */
export interface ChartThemeTokens {
    mode: 'light' | 'dark';
    text: string;
    textStrong: string;
    grid: string;
    track: string;
    surface: string;
    /** --chart-1 … --chart-8 */
    palette: string[];
}

/** Valores de respaldo de los tokens cuando no hay DOM o la variable CSS no existe. */
const FALLBACK_TOKENS: Record<
    'light' | 'dark',
    Omit<ChartThemeTokens, 'mode'>
> = {
    light: {
        text: '#64748b',
        textStrong: '#334155',
        grid: '#eef2f7',
        track: '#eef2f7',
        surface: '#ffffff',
        palette: [...categoryPalette],
    },
    dark: {
        text: '#a1a1aa',
        textStrong: '#e4e4e7',
        grid: 'rgba(255,255,255,0.08)',
        track: 'rgba(255,255,255,0.1)',
        surface: '#141414',
        palette: [
            '#3fb59c',
            '#7cb4e8',
            '#2dd4bf',
            '#f59e0b',
            '#a1a1aa',
            '#38bdf8',
            '#a78bfa',
            '#f87171',
        ],
    },
};

/** Lee los tokens de gráfica del documento; sin DOM (SSR/tests) usa valores de respaldo. */
export function readChartTokens(isDark: boolean): ChartThemeTokens {
    const mode = isDark ? 'dark' : 'light';
    const fallback = FALLBACK_TOKENS[mode];

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return { mode, ...fallback };
    }

    const css = window.getComputedStyle(document.documentElement);
    const read = (name: string, fb: string) =>
        css.getPropertyValue(name).trim() || fb;

    return {
        mode,
        text: read('--chart-text', fallback.text),
        textStrong: read('--chart-text-strong', fallback.textStrong),
        grid: read('--chart-grid', fallback.grid),
        track: read('--chart-track', fallback.track),
        surface: read('--card', fallback.surface),
        palette: fallback.palette.map((fb, i) => read(`--chart-${i + 1}`, fb)),
    };
}

// Neutros "de modo claro" que una página pudo escribir a mano en sus options.
// Si un color de texto/rejilla es uno de estos (o no viene), se sustituye por el
// token del tema; un color de marca puesto a propósito se respeta.
const STRONG_NEUTRALS = new Set([
    '#334155',
    '#1e293b',
    '#0f172a',
    '#374151',
    '#111827',
    '#18181b',
    '#27272a',
]);
const SOFT_NEUTRALS = new Set([
    '#64748b',
    '#475569',
    '#94a3b8',
    '#6b7280',
    '#4b5563',
    '#9ca3af',
    '#71717a',
    '#a1a1aa',
    '#cbd5e1',
]);
const SURFACE_NEUTRALS = new Set([
    '#eef2f7',
    '#f1f5f9',
    '#e2e8f0',
    '#f8fafc',
    '#e5e7eb',
    '#f3f4f6',
    '#ffffff',
    '#fff',
    'white',
]);

/** Normaliza un color (sin espacios, minúsculas) para compararlo. */
const norm = (c: unknown) =>
    typeof c === 'string' ? c.replace(/\s+/g, '').toLowerCase() : '';

/** true si el color (o todos los de un arreglo) es un neutro conocido, vacío o blanco translúcido. */
function isNeutral(c: unknown): boolean {
    if (Array.isArray(c)) {
        return c.every((x) => isNeutral(x));
    }

    const n = norm(c);

    return (
        n === '' ||
        STRONG_NEUTRALS.has(n) ||
        SOFT_NEUTRALS.has(n) ||
        SURFACE_NEUTRALS.has(n) ||
        n.startsWith('rgba(255,255,255')
    );
}

/** Color de texto: neutro o ausente → token (fuerte o suave); de marca → se queda. */
function textColor(c: unknown, t: ChartThemeTokens, fallback: string): any {
    if (Array.isArray(c)) {
        return c.length ? c.map((x) => textColor(x, t, fallback)) : fallback;
    }

    if (c === undefined || c === null || c === '') {
        return fallback;
    }

    if (STRONG_NEUTRALS.has(norm(c))) {
        return t.textStrong;
    }

    return isNeutral(c) ? t.text : c;
}

/** Color de línea/fondo neutro (rejilla, pista, bordes) → token. */
function lineColor(c: unknown, token: string): any {
    return c === undefined || c === null || isNeutral(c) ? token : c;
}

/** Paleta de la radiografía (claro) → índice del token --chart-N. */
const PALETTE_INDEX = new Map(
    categoryPalette.map((c, i) => [c.toLowerCase(), i]),
);

/** Color de serie: si es de la paleta base se traduce al token --chart-N del tema; si no, se respeta. */
function seriesColor(c: unknown, t: ChartThemeTokens): any {
    if (typeof c !== 'string') {
        return c;
    }

    const i = PALETTE_INDEX.get(c.toLowerCase());

    return i === undefined ? c : t.palette[i];
}

/**
 * Aplica el tema a un eje (etiquetas, título, borde y marcas).
 * @param strongLabels usar texto fuerte (p. ej. nombres en el eje Y de barras horizontales).
 */
function themeAxis(axis: any, t: ChartThemeTokens, strongLabels = false): any {
    const fallback = strongLabels ? t.textStrong : t.text;

    if (!axis || typeof axis !== 'object') {
        return { labels: { style: { colors: fallback } } };
    }

    const labels = axis.labels ?? {};
    const style = labels.style ?? {};

    return {
        ...axis,
        labels: {
            ...labels,
            style: { ...style, colors: textColor(style.colors, t, fallback) },
        },
        ...(axis.title
            ? {
                  title: {
                      ...axis.title,
                      style: {
                          ...axis.title.style,
                          color: textColor(axis.title.style?.color, t, t.text),
                      },
                  },
              }
            : {}),
        ...(axis.axisBorder
            ? {
                  axisBorder: {
                      ...axis.axisBorder,
                      color: lineColor(axis.axisBorder.color, t.grid),
                  },
              }
            : {}),
        ...(axis.axisTicks
            ? {
                  axisTicks: {
                      ...axis.axisTicks,
                      color: lineColor(axis.axisTicks.color, t.grid),
                  },
              }
            : {}),
    };
}

/** Aplica el tema a las etiquetas centrales (name/value/total) de donas y anillos. */
function themeCenterLabels(labels: any, t: ChartThemeTokens): any {
    if (!labels || typeof labels !== 'object') {
        return labels;
    }

    const out: any = { ...labels };

    for (const key of ['name', 'value', 'total'] as const) {
        if (labels[key] && typeof labels[key] === 'object') {
            out[key] = {
                ...labels[key],
                color: textColor(
                    labels[key].color,
                    t,
                    key === 'value' ? t.textStrong : t.text,
                ),
            };
        }
    }

    return out;
}

/**
 * Aplica el tema claro/oscuro a unas options de ApexCharts SIN tocar datos ni
 * formateadores: modo, tooltip, rejilla, leyenda, ejes, etiquetas centrales de
 * donas/anillos, borde entre rebanadas y la paleta de marca (--chart-1…8).
 * Neutros o ausentes → token del tema; colores de marca explícitos se respetan.
 * @param opts options originales (no se mutan).
 * @param isDark si el tema activo es oscuro.
 * @param type tipo de gráfica si no viene en opts.chart.type.
 */
export function themedChartOptions(
    opts: Record<string, any> | null | undefined,
    isDark: boolean,
    type?: string,
): Record<string, any> {
    const o = opts ?? {};
    const t = readChartTokens(isDark);
    const chartType = type ?? o.chart?.type;
    const isPie = chartType === 'donut' || chartType === 'pie';
    const horizontal = !!o.plotOptions?.bar?.horizontal;

    const out: Record<string, any> = {
        ...o,
        chart: { ...o.chart, background: 'transparent', foreColor: t.text },
        theme: { ...o.theme, mode: t.mode },
        tooltip: { ...o.tooltip, theme: t.mode },
        grid: {
            ...o.grid,
            borderColor: lineColor(o.grid?.borderColor, t.grid),
        },
        legend: {
            ...o.legend,
            labels: {
                ...o.legend?.labels,
                colors: textColor(o.legend?.labels?.colors, t, t.text),
            },
        },
        xaxis: themeAxis(o.xaxis, t),
        // En barras horizontales el eje Y lleva los nombres (sucursal, gestor): texto fuerte.
        yaxis: Array.isArray(o.yaxis)
            ? o.yaxis.map((y: any) => themeAxis(y, t, horizontal))
            : themeAxis(o.yaxis, t, horizontal),
    };

    if (Array.isArray(o.colors)) {
        out.colors = o.colors.map((c: unknown) => seriesColor(c, t));
    }

    if (o.plotOptions) {
        const p = o.plotOptions;
        out.plotOptions = { ...p };

        if (p.radialBar) {
            out.plotOptions.radialBar = {
                ...p.radialBar,
                track: {
                    ...p.radialBar.track,
                    background: lineColor(
                        p.radialBar.track?.background,
                        t.track,
                    ),
                },
                dataLabels: themeCenterLabels(p.radialBar.dataLabels, t),
            };
        }

        if (p.pie?.donut?.labels) {
            out.plotOptions.pie = {
                ...p.pie,
                donut: {
                    ...p.pie.donut,
                    labels: themeCenterLabels(p.pie.donut.labels, t),
                },
            };
        }
    }

    // Borde entre rebanadas del color de la tarjeta (blanco en claro, negro en oscuro).
    if (isPie) {
        const sc = o.stroke?.colors;
        const neutralStroke = !sc || isNeutral(sc);
        out.stroke = {
            ...o.stroke,
            ...(neutralStroke ? { colors: [t.surface] } : {}),
        };
    }

    if (
        o.markers &&
        (o.markers.strokeColors === undefined ||
            isNeutral(o.markers.strokeColors))
    ) {
        out.markers = { ...o.markers, strokeColors: t.surface };
    }

    return out;
}

/**
 * `true` mientras el documento tenga la clase `.dark`. Reacciona al cambio de
 * tema (Configuración → Apariencia o "seguir sistema") sin recargar la página.
 */
export function useChartDarkMode(): Ref<boolean> {
    const dark = ref(false);
    const read = () => {
        dark.value =
            typeof document !== 'undefined' &&
            document.documentElement.classList.contains('dark');
    };

    read();
    onMounted(read);
    useMutationObserver(
        typeof document !== 'undefined' ? document.documentElement : null,
        read,
        {
            attributes: true,
            attributeFilter: ['class'],
        },
    );

    return dark;
}
