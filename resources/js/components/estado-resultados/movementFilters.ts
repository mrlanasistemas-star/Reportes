/**
 * Filtros con nombre para la tabla de movimientos. Los usan los chips rápidos,
 * los segmentos del embudo de conciliación, la cascada (por sección) y el panel
 * "Fuera del ER" (por tratamiento): un clic en cualquiera de ellos abre la tabla
 * ya filtrada con exactamente los movimientos que forman esa cifra.
 *
 * Los criterios replican los del EstadoResultadosDatasetBuilder::conciliationSummary
 * para que el conteo de la tabla coincida con el de cada segmento.
 */
import { isConciliated } from './types';
import type { Movement, TreatmentMeta } from './types';

export interface MovementFilter {
    key: string;
    label: string;
    hint: string;
    test: (m: Movement) => boolean;
}

const bankInPeriod = (m: Movement) => m.source === 'banco' && m.in_period;

/** Segmentos del embudo banco → resultado (mismos que el resumen de conciliación). */
export const SEGMENT_FILTERS: Record<string, MovementFilter> = {
    conciliados: {
        key: 'conciliados',
        label: 'Ya registrados en el ERP',
        hint: 'El banco confirma un gasto que ya estaba en el ERP: se cuenta una sola vez, desde el ERP.',
        test: (m) => bankInPeriod(m) && isConciliated(m),
    },
    reconocidos: {
        key: 'reconocidos',
        label: 'Suman al Estado de Resultados',
        hint: 'Movimientos de banco que no estaban en el ERP y se incorporan a una línea del reporte.',
        test: (m) => bankInPeriod(m) && m.impact.included,
    },
    transferencias: {
        key: 'transferencias',
        label: 'Transferencias internas',
        hint: 'Dinero que se mueve entre cuentas o sucursales de MR LANA. No es ingreso ni gasto.',
        test: (m) => bankInPeriod(m) && m.treatment === 'transferencia_interna',
    },
    fuera: {
        key: 'fuera',
        label: 'Fuera del resultado',
        hint: 'Capital, deuda, CAPEX, retenciones, terceros o gastos ya cubiertos por Radiografía.',
        test: (m) =>
            bankInPeriod(m) &&
            m.treatment_group === 'fuera' &&
            !['conciliado_erp', 'transferencia_interna'].includes(m.treatment),
    },
    pendientes: {
        key: 'pendientes',
        label: 'Por revisar',
        hint: 'Aún no se sabe qué son. No suman a ningún resultado hasta que alguien los clasifique.',
        test: (m) => m.in_period && m.treatment === 'pendiente',
    },
};

/** Chips rápidos de la tabla. */
export const QUICK_FILTERS: MovementFilter[] = [
    SEGMENT_FILTERS.pendientes,
    {
        key: 'con_sugerencia',
        label: 'Con sugerencia lista',
        hint: 'Pendientes para los que el sistema ya propone un tratamiento.',
        test: (m) => m.treatment === 'pendiente' && !!m.suggested_treatment,
    },
    SEGMENT_FILTERS.reconocidos,
    SEGMENT_FILTERS.conciliados,
    {
        key: 'nuevos',
        label: 'Sin registro en ERP',
        hint: 'Cargos cuyo folio no aparece en el ERP o que no traen folio.',
        test: (m) =>
            ['folio_sin_erp', 'sin_folio'].includes(m.reconciliation_status),
    },
    SEGMENT_FILTERS.transferencias,
    {
        key: 'corporativo',
        label: 'Corporativo',
        hint: 'Movimientos asignados a Corporativo.',
        test: (m) => m.branch_kind === 'corporativo',
    },
    {
        key: 'dg',
        label: 'Dirección General',
        hint: 'Movimientos clasificados o sugeridos como gasto de Dirección General.',
        test: (m) =>
            (m.rule_code ?? '').includes('dg') || m.treatment.startsWith('dg_'),
    },
    {
        key: 'manual',
        label: 'Clasificados a mano',
        hint: 'Movimientos con una clasificación manual registrada.',
        test: (m) => m.treatment_source === 'manual',
    },
];

/** Movimientos que alimentan una sección del estado de resultados. */
export function lineFilter(sectionKey: string, title: string): MovementFilter {
    return {
        key: `line:${sectionKey}`,
        label: `Suman a ${title}`,
        hint: 'Movimientos de estados de cuenta incorporados a esta sección.',
        test: (m) => m.impact.included && m.impact.line === sectionKey,
    };
}

export function treatmentFilter(
    t: Pick<TreatmentMeta, 'key' | 'label'>,
): MovementFilter {
    return {
        key: `t:${t.key}`,
        label: t.label,
        hint: 'Movimientos con este tratamiento.',
        test: (m) =>
            t.key === 'fuera_periodo'
                ? m.source === 'banco' && !m.in_period
                : m.treatment === t.key &&
                  (m.source !== 'banco' || m.in_period),
    };
}

/** Clave de filtro → filtro (para restaurar el foco desde otras vistas). */
export function resolveFilter(
    key: string,
    ctx: { treatments: TreatmentMeta[]; sectionTitle: (k: string) => string },
): MovementFilter | null {
    if (SEGMENT_FILTERS[key]) {
        return SEGMENT_FILTERS[key];
    }

    const quick = QUICK_FILTERS.find((f) => f.key === key);

    if (quick) {
        return quick;
    }

    if (key.startsWith('line:')) {
        const s = key.slice(5);

        return lineFilter(s, ctx.sectionTitle(s));
    }

    if (key.startsWith('t:')) {
        const k = key.slice(2);
        const meta = ctx.treatments.find((t) => t.key === k) ?? {
            key: k,
            label: k,
        };

        return treatmentFilter(meta);
    }

    return null;
}
