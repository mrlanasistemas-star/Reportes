import type { Ref } from 'vue';
import { computed, ref } from 'vue';
import {
    CATEGORY_META,
    CATEGORY_ORDER,
    normalizeText,
    searchTokens,
} from '@/lib/hardData';
import type {
    HardDataFlag,
    HardDataRow,
    HardDataSortKey,
} from '@/types/hardData';

/** Texto en el que se busca cada palabra: nombre, sucursal, rutas, categoría, estado y códigos. */
function haystack(r: HardDataRow): string {
    const cat =
        CATEGORY_META[(r.category ?? '').toUpperCase()]?.label ?? r.category;

    return normalizeText(
        [
            r.name,
            r.branch,
            r.region,
            ...(r.routes ?? []),
            r.category,
            cat,
            r.status,
            r.code,
            r.promoter_code,
            r.position,
        ].join(' '),
    );
}

export const SIN_PUESTO = 'Sin puesto';

/** Puesto con el que se agrupa/filtra (viene de People cuando hay conexión). */
export function positionOf(r: HardDataRow): string {
    return r.position?.trim() || SIN_PUESTO;
}

export type HardDataSuggestion =
    | { kind: 'branch'; value: string; count: number }
    | { kind: 'category'; value: string; count: number }
    | { kind: 'position'; value: string; count: number }
    | { kind: 'route'; value: string; count: number }
    | { kind: 'person'; row: HardDataRow };

export const FLAG_LABELS: Record<HardDataFlag, string> = {
    mora_alta: 'Mora ≥ 10 %',
    ebitda_negativo: 'EBITDA negativo',
    sin_colocacion: 'Sin colocación',
    baja: 'Bajas',
};

const FLAG_TEST: Record<HardDataFlag, (r: HardDataRow) => boolean> = {
    mora_alta: (r) => Number(r.mora_pct ?? 0) >= 10,
    // El personal sin cartera (gerencia, coordinación) no cuenta como alerta.
    ebitda_negativo: (r) => !r.support && r.ebitda < 0,
    sin_colocacion: (r) => !r.support && !r.placement,
    baja: (r) => (r.status ?? '').toUpperCase() === 'BAJA',
};

/**
 * Búsqueda libre + filtros por chips + orden + totales del conjunto filtrado.
 * Todo corre en el navegador sobre las filas ya cargadas: cada tecla filtra al instante.
 */
export function useHardDataFilters(rows: Ref<HardDataRow[]>) {
    const query = ref('');
    const branches = ref<Set<string>>(new Set());
    const categories = ref<Set<string>>(new Set());
    const positions = ref<Set<string>>(new Set());
    const flags = ref<Set<HardDataFlag>>(new Set());
    const sortKey = ref<HardDataSortKey>('ebitda');
    const sortDir = ref<'asc' | 'desc'>('desc');

    const indexed = computed(() =>
        rows.value.map((r) => ({ row: r, text: haystack(r) })),
    );
    const tokens = computed(() => searchTokens(query.value));

    /** Filtro por texto y alertas (sin chips de sucursal/categoría: sirve para los conteos de esos chips). */
    const textMatched = computed(() =>
        indexed.value
            .filter(
                ({ text, row }) =>
                    tokens.value.every((t) => text.includes(t)) &&
                    [...flags.value].every((f) => FLAG_TEST[f](row)),
            )
            .map(({ row }) => row),
    );

    const inBranch = (r: HardDataRow) =>
        branches.value.size === 0 ||
        branches.value.has(r.branch ?? 'Sin sucursal');
    const inCategory = (r: HardDataRow) =>
        categories.value.size === 0 ||
        categories.value.has((r.category ?? '').toUpperCase());
    const inPosition = (r: HardDataRow) =>
        positions.value.size === 0 || positions.value.has(positionOf(r));

    const filtered = computed(() => {
        const list = textMatched.value.filter(
            (r) => inBranch(r) && inCategory(r) && inPosition(r),
        );
        const dir = sortDir.value === 'asc' ? 1 : -1;
        const key = sortKey.value;

        return [...list].sort((a, b) => {
            if (key === 'name') {
                return dir * a.name.localeCompare(b.name, 'es');
            }

            return dir * (Number(a[key] ?? 0) - Number(b[key] ?? 0));
        });
    });

    /** Sucursales con conteo, respetando texto + categorías activas. */
    const branchFacets = computed(() => {
        const counts = new Map<string, number>();

        for (const r of textMatched.value) {
            if (!inCategory(r) || !inPosition(r)) {
                continue;
            }

            const b = r.branch ?? 'Sin sucursal';
            counts.set(b, (counts.get(b) ?? 0) + 1);
        }

        const all = new Set([
            ...rows.value.map((r) => r.branch ?? 'Sin sucursal'),
        ]);

        return [...all]
            .map((name) => ({ name, count: counts.get(name) ?? 0 }))
            .sort(
                (a, b) =>
                    b.count - a.count || a.name.localeCompare(b.name, 'es'),
            );
    });

    const categoryFacets = computed(() => {
        const counts = new Map<string, number>();

        for (const r of textMatched.value) {
            if (!inBranch(r) || !inPosition(r)) {
                continue;
            }

            const c = (r.category ?? '').toUpperCase();
            counts.set(c, (counts.get(c) ?? 0) + 1);
        }

        return CATEGORY_ORDER.map((key) => ({
            key,
            count: counts.get(key) ?? 0,
        })).filter(
            (c) =>
                c.key !== 'SIN CARTERA' ||
                c.count > 0 ||
                categories.value.has(c.key),
        );
    });

    /** Puestos (de People) con conteo, respetando texto + sucursal + categoría. */
    const positionFacets = computed(() => {
        const counts = new Map<string, number>();

        for (const r of textMatched.value) {
            if (!inBranch(r) || !inCategory(r)) {
                continue;
            }

            const p = positionOf(r);
            counts.set(p, (counts.get(p) ?? 0) + 1);
        }

        const all = new Set(rows.value.map(positionOf));

        return [...all]
            .map((name) => ({ name, count: counts.get(name) ?? 0 }))
            .sort(
                (a, b) =>
                    b.count - a.count || a.name.localeCompare(b.name, 'es'),
            );
    });

    const flagFacets = computed(() =>
        (Object.keys(FLAG_LABELS) as HardDataFlag[]).map((key) => ({
            key,
            label: FLAG_LABELS[key],
            count: rows.value.filter(FLAG_TEST[key]).length,
        })),
    );

    /** Sugerencias del buscador: sucursales, rutas, categorías y personas que coinciden con lo escrito. */
    const suggestions = computed<HardDataSuggestion[]>(() => {
        const t = tokens.value;

        if (t.length === 0) {
            return [];
        }

        const matches = (value: string) => {
            const n = normalizeText(value);

            return t.every((x) => n.includes(x));
        };
        const out: HardDataSuggestion[] = [];

        for (const f of branchFacets.value) {
            if (matches(f.name) && !branches.value.has(f.name)) {
                out.push({
                    kind: 'branch',
                    value: f.name,
                    count: rows.value.filter(
                        (r) => (r.branch ?? 'Sin sucursal') === f.name,
                    ).length,
                });
            }
        }

        for (const f of positionFacets.value) {
            if (
                f.name !== SIN_PUESTO &&
                matches(f.name) &&
                !positions.value.has(f.name)
            ) {
                out.push({
                    kind: 'position',
                    value: f.name,
                    count: rows.value.filter((r) => positionOf(r) === f.name)
                        .length,
                });
            }
        }

        const routes = new Map<string, number>();

        for (const r of rows.value) {
            for (const route of r.routes ?? []) {
                if (matches(route) && !matches(r.branch ?? '')) {
                    routes.set(route, (routes.get(route) ?? 0) + 1);
                }
            }
        }

        for (const [value, count] of [...routes].slice(0, 3)) {
            out.push({ kind: 'route', value, count });
        }

        for (const key of CATEGORY_ORDER) {
            if (
                (matches(key) || matches(CATEGORY_META[key].label)) &&
                !categories.value.has(key)
            ) {
                out.push({
                    kind: 'category',
                    value: key,
                    count: rows.value.filter(
                        (r) => (r.category ?? '').toUpperCase() === key,
                    ).length,
                });
            }
        }

        for (const row of filtered.value
            .filter((r) => matches(r.name))
            .slice(0, 6)) {
            out.push({ kind: 'person', row });
        }

        return out.slice(0, 10);
    });

    const totals = computed(() => {
        const list = filtered.value;
        const sum = (k: keyof HardDataRow) =>
            list.reduce((acc, r) => acc + Number(r[k] ?? 0), 0);
        const portfolio = sum('portfolio');
        const overdue = sum('overdue');
        const income = sum('income_base');
        const ebitda = sum('ebitda');

        return {
            count: list.length,
            active: list.filter(
                (r) => (r.status ?? '').toUpperCase() !== 'BAJA',
            ).length,
            placement: sum('placement'),
            operations: sum('operations'),
            recovery: sum('recovery'),
            portfolio,
            overdue,
            moraPct: portfolio > 0 ? (overdue / portfolio) * 100 : 0,
            payroll: sum('payroll'),
            opex: sum('opex'),
            ebitda,
            marginPct: income > 0 ? (ebitda / income) * 100 : 0,
        };
    });

    const activeCount = computed(
        () =>
            branches.value.size +
            categories.value.size +
            positions.value.size +
            flags.value.size +
            (query.value.trim() ? 1 : 0),
    );

    function toggle<T>(set: Ref<Set<T>>, value: T): void {
        const next = new Set(set.value);

        if (next.has(value)) {
            next.delete(value);
        } else {
            next.add(value);
        }

        set.value = next;
    }

    function setSort(key: HardDataSortKey): void {
        if (sortKey.value === key) {
            sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey.value = key;
            sortDir.value = key === 'name' ? 'asc' : 'desc';
        }
    }

    function clearAll(): void {
        query.value = '';
        branches.value = new Set();
        categories.value = new Set();
        positions.value = new Set();
        flags.value = new Set();
    }

    return {
        query,
        tokens,
        branches,
        categories,
        positions,
        flags,
        sortKey,
        sortDir,
        filtered,
        branchFacets,
        categoryFacets,
        positionFacets,
        flagFacets,
        suggestions,
        totals,
        activeCount,
        toggleBranch: (v: string) => toggle(branches, v),
        toggleCategory: (v: string) => toggle(categories, v),
        togglePosition: (v: string) => toggle(positions, v),
        toggleFlag: (v: HardDataFlag) => toggle(flags, v),
        setSort,
        clearAll,
    };
}
