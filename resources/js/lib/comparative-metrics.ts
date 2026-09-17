/**
 * Metadata semántica por métrica del comparativo (Parte B9 del cierre, 17-sep-2026).
 *
 * BUG DE DISEÑO evitado aquí (visto en el PDF comparativo actual — NO se toca, ver
 * radiography-pdf-comparative.blade.php — pero no se replica en la Web nueva): ese
 * PDF colorea var_pct > 0 = verde / < 0 = rojo para TODA fila, sin importar la
 * métrica. Eso es incorrecto para Mora, OPEX, Cartera vencida, Rotación (donde BAJAR
 * es la mejora) — un aumento de Mora se pintaría verde igual que un aumento de
 * EBITDA. La cifra numérica de la diferencia NUNCA cambia; solo el color semántico.
 */
export type MetricDirection = 'increase_good' | 'decrease_good' | 'neutral'

const DIRECTIONS: Record<string, MetricDirection> = {
    'Recuperación': 'increase_good',
    'Ingreso base EBITDA': 'increase_good',
    'Colocación': 'increase_good',
    'Valor cartera': 'increase_good',
    'Cartera vencida': 'decrease_good',
    'Mora %': 'decrease_good',
    'OPEX': 'decrease_good',
    'Gastos': 'decrease_good', // alias usado en el comparativo scope=employee
    'Nómina y Capital Humano': 'neutral',
    'Gastos Totales': 'decrease_good',
    'EBITDA': 'increase_good',
    'Margen EBITDA': 'increase_good',
    'Préstamos activos (contratos)': 'increase_good',
    'IMSS': 'neutral',
    'Percepciones': 'neutral',
    'Deducciones (informativo)': 'neutral',
    'Neto pagado a trabajadores': 'neutral',
    'Plantilla': 'neutral',
    'Altas del periodo': 'neutral',
    'Bajas del periodo': 'decrease_good',
    'Rotación %': 'decrease_good',
}

export function directionFor(label: string): MetricDirection {
    return DIRECTIONS[label] ?? 'neutral'
}

/** 'good' | 'bad' | 'neutral' — nunca cambia la cifra, solo el tono semántico a aplicar. */
export function toneFor(label: string, varPct: number): 'good' | 'bad' | 'neutral' {
    if (varPct === 0) return 'neutral'
    const dir = directionFor(label)
    if (dir === 'neutral') return 'neutral'
    const isIncrease = varPct > 0
    if (dir === 'increase_good') return isIncrease ? 'good' : 'bad'
    return isIncrease ? 'bad' : 'good'
}

/** Métricas principales para las cards ejecutivas (B8) — subconjunto, nunca oculta el resto (tabla completa las conserva todas). */
export const HEADLINE_METRICS = ['Recuperación', 'Colocación', 'EBITDA', 'Margen EBITDA', 'OPEX', 'Mora %', 'Valor cartera', 'Cartera vencida']

/** Grupo de métricas monetarias para el bar chart agrupado principal (B11) — nunca mezclado con porcentajes. */
export const CHART_CURRENCY_METRICS = ['Recuperación', 'Colocación', 'EBITDA', 'OPEX']

/** Grupo de métricas porcentuales para su propio chart (B11) — eje separado del anterior. */
export const CHART_PERCENT_METRICS = ['Margen EBITDA', 'Mora %', 'Rotación %']
