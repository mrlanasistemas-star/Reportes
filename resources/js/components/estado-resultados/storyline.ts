/**
 * Une cada paso de la cascada con el bloque del estado de resultados que lo explica.
 * Modelo de UN solo EBITDA: Ingresos → OPEX (categorías) → EBITDA → D&A → EBIT →
 * Resultado financiero neto → EBT → ISR → Utilidad neta. Sólo reorganiza datos del
 * dataset; no calcula importes.
 */
import type {
    Dataset,
    OpexCategory,
    StatementResult,
    StatementSection,
    WaterfallStep,
} from './types';

export interface StepMeta {
    step: WaterfallStep;
    section: StatementSection | null;
    /** Categoría de OPEX cuando el paso es una de ellas. */
    category: OpexCategory | null;
    result: StatementResult | null;
    formula: string;
    sources: string[];
    complete: boolean;
    missing: string[];
    /** Movimientos de banco/ERP que suman a esta línea (conteo, no dinero). */
    movementCount: number;
    group: number;
    /** Clave de bloque para "Ver en el estado de resultados". */
    blockKey: string;
}

export const STEP_GROUPS: Record<number, { label: string }> = {
    1: { label: 'Ingresos' },
    2: { label: 'OPEX · gastos operativos' },
    3: { label: 'Del EBITDA a la utilidad neta' },
};

/** Paso del modelo (1–9) → grupo visual. Versiones anteriores sin `step` usan `level`. */
export function groupOf(
    s: Pick<WaterfallStep, 'step' | 'level' | 'group'>,
): number {
    const step = s.step ?? s.level;

    if (s.group === 'opex' || step === 2) {
        return 2;
    }

    return step <= 1 ? 1 : 3;
}

/** Paso de la cascada → sección del estado de resultados. */
const STEP_SECTION: Record<string, string> = {
    ingresos_operativos: 'ingresos',
    ingresos: 'ingresos',
    depreciacion_amortizacion: 'da',
    resultado_financiero_neto: 'financiero',
    isr: 'isr',
};

const FORMULAS: Record<string, string> = {
    ingresos_operativos:
        'Punto de partida: ingresos operativos cobrados en el periodo',
    depreciacion_amortizacion:
        'Se resta: depreciación de activos y amortización de sistemas propios',
    resultado_financiero_neto:
        'Con signo: gastos financieros negativos, ingresos financieros / no operativos positivos',
    isr: 'Se resta: impuesto sobre la renta del periodo',
};

export function buildStepMeta(d: Dataset): Record<string, StepMeta> {
    const sections = new Map<string, StatementSection>();
    const results = new Map<string, StatementResult>();
    const categories = new Map<string, OpexCategory>();

    for (const b of d.statement) {
        if (b.type === 'section') {
            sections.set(b.key, b);

            for (const c of b.categories ?? []) {
                categories.set(c.key, c);
            }
        } else {
            results.set(b.key, b);
        }
    }

    const counts = new Map<string, number>();

    for (const m of d.movements) {
        if (m.impact.included && m.impact.line) {
            counts.set(m.impact.line, (counts.get(m.impact.line) ?? 0) + 1);
        }
    }

    const out: Record<string, StepMeta> = {};

    for (const step of d.waterfall) {
        const category = categories.get(step.key) ?? null;
        const sectionKey = category
            ? 'opex'
            : (STEP_SECTION[step.key] ?? step.key);
        const section = sections.get(sectionKey) ?? null;
        const result = results.get(step.key) ?? null;
        const lines = category ? category.lines : (section?.lines ?? []);
        const sources = [
            ...new Set(lines.map((l) => l.source).filter(Boolean)),
        ];
        const pending = step.amount === null || step.pending === true;
        const missing = result
            ? result.missing
            : lines
                  .filter((l) => l.state === 'sin_informacion')
                  .map((l) => l.label);

        let formula = result?.formula ?? FORMULAS[step.key] ?? '';

        if (!formula && category) {
            formula = `Categoría de OPEX: ${category.label}. Se resta de los ingresos para llegar al EBITDA.`;
        }

        out[step.key] = {
            step,
            section,
            category,
            result,
            formula,
            sources,
            complete:
                !pending && (result ? result.complete : missing.length === 0),
            missing,
            movementCount:
                counts.get(category ? category.key : sectionKey) ?? 0,
            group: groupOf(step),
            blockKey: category
                ? `opex:${category.key}`
                : result
                  ? result.key
                  : sectionKey,
        };
    }

    return out;
}
