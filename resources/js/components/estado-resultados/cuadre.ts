/**
 * Cuadre de gastos e ingresos (pestaña «Cuadre»): utilidades puras.
 *
 * Las cifras de cada categoría vienen del servidor (dataset.cuadre, Σ por destino = universo,
 * diferencia $0.00). Las filas salen de `movement.parts` (cada parte del importe de un movimiento
 * en su destino; un «Ya considerado» parcial aparece en DOS categorías con su importe). Las partes
 * `overlap` (pago del banco del mismo gasto que una fila ERP) no se pintan: ese gasto cuenta una
 * sola vez, desde el ERP. Aquí sólo se agrupa y se ordena: nunca se recalcula un importe.
 */
import { conceptKey } from './movementGroups';
import { num } from './types';
import type { Cuadre, CuadreCategory, Movement, TreatmentMeta } from './types';

/** Una parte de un movimiento dentro de una categoría. */
export interface CuadreRow {
    id: string;
    fingerprint: string;
    movement: Movement;
    amount: number;
    side: 'cargo' | 'abono';
    /** El movimiento está repartido en más de una categoría (p. ej. «Ya considerado» parcial). */
    split: boolean;
}

/** Movimientos del mismo concepto dentro de una categoría. */
export interface CuadreBundle {
    key: string;
    label: string;
    rows: CuadreRow[];
    total: number;
}

/** Categoría lista para pintarse. */
export interface CuadreCategoryView extends CuadreCategory {
    rows: CuadreRow[];
    bundles: CuadreBundle[];
    /** Importe de la categoría que no se ve por permisos (nómina / DG). */
    hiddenAmount: number;
}

/** Orden de las filas. */
export type CuadreSort = 'importe' | 'fecha' | 'concepto';

/** Preferencias del usuario (localStorage). */
export interface CuadrePrefs {
    order: string[];
    hidden: string[];
    collapsed: string[];
    sort: CuadreSort;
    showEmpty: boolean;
}

/** Orden por defecto: ER → retiros → pendiente → fuera del ER. */
const KIND_ORDER: Record<CuadreCategory['kind'], number> = {
    er: 0,
    retiros: 1,
    pendiente: 2,
    fuera: 3,
};

/** Preferencias por defecto. */
export const DEFAULT_PREFS: CuadrePrefs = {
    order: [],
    hidden: [],
    collapsed: [],
    sort: 'importe',
    showEmpty: false,
};

const PREFS_KEY = 'er:cuadre:prefs:v1';

/** Lee las preferencias (sin almacenamiento o con datos inválidos: las de fábrica). */
export function loadPrefs(): CuadrePrefs {
    try {
        const raw =
            typeof window === 'undefined'
                ? null
                : window.localStorage.getItem(PREFS_KEY);
        const p = raw ? (JSON.parse(raw) as Partial<CuadrePrefs>) : {};

        return {
            order: Array.isArray(p.order) ? p.order.map(String) : [],
            hidden: Array.isArray(p.hidden) ? p.hidden.map(String) : [],
            collapsed: Array.isArray(p.collapsed)
                ? p.collapsed.map(String)
                : [],
            sort: (['importe', 'fecha', 'concepto'] as const).includes(
                p.sort as CuadreSort,
            )
                ? (p.sort as CuadreSort)
                : 'importe',
            showEmpty: !!p.showEmpty,
        };
    } catch {
        return { ...DEFAULT_PREFS };
    }
}

/** Guarda las preferencias (si el navegador lo permite). */
export function savePrefs(p: CuadrePrefs): void {
    try {
        window.localStorage.setItem(PREFS_KEY, JSON.stringify(p));
    } catch {
        // Sin almacenamiento (modo privado o bloqueado): sólo dura esta visita.
    }
}

/**
 * Compara dos filas según el orden elegido (importe desc., fecha asc., concepto A-Z).
 * @param sort orden.
 */
function comparer(sort: CuadreSort): (a: CuadreRow, b: CuadreRow) => number {
    if (sort === 'fecha') {
        return (a, b) =>
            (a.movement.date ?? '').localeCompare(b.movement.date ?? '') ||
            b.amount - a.amount;
    }

    if (sort === 'concepto') {
        return (a, b) =>
            (a.movement.concept ?? '').localeCompare(
                b.movement.concept ?? '',
                'es',
            ) || b.amount - a.amount;
    }

    return (a, b) => b.amount - a.amount;
}

/**
 * Arma las categorías del cuadre: totales del servidor + filas de los movimientos visibles,
 * agrupadas por concepto y ordenadas. Incluye los tratamientos asignables sin movimientos
 * (destinos para mover) marcados con count 0.
 * @param cuadre bloque del dataset.
 * @param movements movimientos visibles (ya redactados).
 * @param treatments catálogo.
 * @param sort orden de filas.
 */
export function buildCategories(
    cuadre: Cuadre,
    movements: Movement[],
    treatments: TreatmentMeta[],
    sort: CuadreSort,
): CuadreCategoryView[] {
    const rows = new Map<string, CuadreRow[]>();

    for (const m of movements) {
        const parts = (m.parts ?? []).filter((p) => !p.overlap);
        const split = new Set(parts.map((p) => p.key)).size > 1;

        parts.forEach((p, i) => {
            const list = rows.get(p.key) ?? [];
            list.push({
                id: `${m.fingerprint}|${p.key}|${i}`,
                fingerprint: m.fingerprint,
                movement: m,
                amount: num(p.amount),
                side: p.side,
                split,
            });
            rows.set(p.key, list);
        });
    }

    const byKey = new Map(cuadre.categories.map((c) => [c.key, c]));

    // Destinos vacíos: tratamientos asignables que hoy no tienen nada (para poder mover a ellos).
    for (const t of treatments) {
        if (t.assignable && !byKey.has(t.key)) {
            byKey.set(t.key, {
                key: t.key,
                label: t.label,
                kind:
                    t.key === 'pendiente'
                        ? 'pendiente'
                        : t.line === 'retiros'
                          ? 'retiros'
                          : t.group === 'er'
                            ? 'er'
                            : 'fuera',
                outside: null,
                line: t.line,
                assignable: true,
                count: 0,
                cargos: '0.00',
                abonos: '0.00',
                total: '0.00',
            });
        }
    }

    const cmp = comparer(sort);

    return [...byKey.values()]
        .map((c) => {
            const list = (rows.get(c.key) ?? []).slice().sort(cmp);
            const visible = list.reduce((s, r) => s + r.amount, 0);

            return {
                ...c,
                rows: list,
                bundles: bundleRows(list, cmp),
                hiddenAmount: Math.max(
                    0,
                    Math.round((num(c.total) - visible) * 100) / 100,
                ),
            };
        })
        .sort(
            (a, b) =>
                KIND_ORDER[a.kind] - KIND_ORDER[b.kind] ||
                num(b.total) - num(a.total),
        );
}

/**
 * Junta las filas del mismo concepto; los conceptos de mayor importe primero.
 * @param rows filas de una categoría (ya ordenadas).
 * @param cmp comparador de filas.
 */
function bundleRows(
    rows: CuadreRow[],
    cmp: (a: CuadreRow, b: CuadreRow) => number,
): CuadreBundle[] {
    const map = new Map<string, CuadreBundle>();

    for (const r of rows) {
        const key = conceptKey(r.movement.concept);
        const b = map.get(key) ?? {
            key,
            label: r.movement.concept ?? '—',
            rows: [],
            total: 0,
        };
        b.rows.push(r);
        b.total += r.amount;
        map.set(key, b);
    }

    return [...map.values()]
        .map((b) => ({
            ...b,
            label: b.rows.length > 1 ? conceptKey(b.label) : b.label,
            rows: b.rows.slice().sort(cmp),
        }))
        .sort((a, b) => b.total - a.total);
}

/**
 * Aplica el orden y la visibilidad elegidos por el usuario.
 * @param cats categorías.
 * @param prefs preferencias.
 */
export function arrange(
    cats: CuadreCategoryView[],
    prefs: CuadrePrefs,
): CuadreCategoryView[] {
    const pos = new Map(prefs.order.map((k, i) => [k, i]));

    return cats
        .filter(
            (c) =>
                !prefs.hidden.includes(c.key) &&
                (prefs.showEmpty || c.count > 0 || c.rows.length > 0),
        )
        .map((c, i) => ({ c, i }))
        .sort(
            (a, b) =>
                (pos.get(a.c.key) ?? 1000 + a.i) -
                (pos.get(b.c.key) ?? 1000 + b.i),
        )
        .map(({ c }) => c);
}
