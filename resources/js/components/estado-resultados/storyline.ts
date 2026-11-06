/**
 * Une cada paso de la cascada con el bloque del estado de resultados que lo
 * explica (sección con sus líneas, o resultado con su fórmula). Sólo reorganiza
 * datos del dataset; no calcula importes.
 */
import { sectionOfStep } from './types';
import type {
    Dataset,
    StatementResult,
    StatementSection,
    WaterfallStep,
} from './types';

export interface StepMeta {
    step: WaterfallStep;
    section: StatementSection | null;
    result: StatementResult | null;
    /** Fórmula (resultados) o explicación de qué resta/suma (secciones). */
    formula: string;
    sources: string[];
    complete: boolean;
    missing: string[];
    /** Movimientos de banco/ERP que suman a esta sección (conteo, no dinero). */
    movementCount: number;
    group: number;
}

export const STEP_GROUPS: Record<number, { label: string; short: string }> = {
    1: { label: 'Sucursales', short: 'Sucursales' },
    2: { label: 'Corporativo', short: 'Corporativo' },
    3: { label: 'Dirección General', short: 'DG' },
    4: { label: 'Contable, financiero y fiscal', short: 'Contable' },
};

export const groupOfLevel = (level: number) => (level >= 4 ? 4 : level);

/** Hitos del EBITDA: el número que el usuario ve como "Nivel 1/2/3". */
export const EBITDA_TIER: Record<string, string> = {
    ebitda_sucursales: 'Nivel 1',
    ebitda_corporativo: 'Nivel 2',
    ebitda_final: 'Nivel 3',
};

export function buildStepMeta(d: Dataset): Record<string, StepMeta> {
    const sections = new Map<string, StatementSection>();
    const results = new Map<string, StatementResult>();

    for (const b of d.statement) {
        if (b.type === 'section') {
            sections.set(b.key, b);
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
        const sectionKey = sectionOfStep(step.key);
        const section = sections.get(sectionKey) ?? null;
        const result = results.get(step.key) ?? null;
        const sources = section
            ? [...new Set(section.lines.map((l) => l.source).filter(Boolean))]
            : [];
        const complete = result
            ? result.complete
            : !(section?.lines ?? []).some(
                  (l) => l.state === 'sin_informacion',
              );
        const missing = result
            ? result.missing
            : (section?.lines ?? [])
                  .filter((l) => l.state === 'sin_informacion')
                  .map((l) => l.label);

        let formula = result?.formula ?? '';

        if (!formula && step.type === 'delta') {
            formula =
                step.key === 'resultado_financiero_neto'
                    ? 'Gastos financieros − ingresos financieros / no operativos'
                    : `Se resta: ${section?.total.label.toLowerCase() ?? step.label}`;
        }

        if (!formula && step.key === 'ingresos') {
            formula = 'Punto de partida: ingresos operativos del periodo';
        }

        out[step.key] = {
            step,
            section,
            result,
            formula,
            sources,
            complete,
            missing,
            movementCount: counts.get(sectionKey) ?? 0,
            group: groupOfLevel(step.level),
        };
    }

    return out;
}
