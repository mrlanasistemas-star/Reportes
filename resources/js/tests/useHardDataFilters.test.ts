import { describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { useHardDataFilters } from '../composables/useHardDataFilters';
import type { HardDataRow } from '../types/hardData';

// Módulo «Datos duros»: el buscador filtra al instante sin distinguir acentos ni
// mayúsculas, todas las palabras deben coincidir y los totales siguen al filtro.
function row(
    id: number,
    name: string,
    branch: string,
    category: string,
    extra: Partial<HardDataRow> = {},
): HardDataRow {
    return {
        id,
        name,
        branch,
        category,
        code: null,
        promoter_code: null,
        position: null,
        branch_id: id,
        region: null,
        routes: [`${branch} CENTRO`],
        status: 'ACTIVO',
        roster_label: null,
        placement: 100,
        operations: 1,
        placement_by_product: [],
        recovery: 200,
        recovery_components: null,
        portfolio: 1000,
        overdue: 100,
        current: 900,
        mora_pct: 10,
        buckets: {},
        payroll: 50,
        perceptions: 50,
        deductions: 0,
        payroll_concepts: {},
        commissions: 0,
        opex: 10,
        opex_items: [],
        income_base: 300,
        total_expenses: 60,
        ebitda: 240,
        margin_pct: 80,
        ...extra,
    };
}

const rows = ref<HardDataRow[]>([
    row(1, 'MARGARITA NOLASCO DOMÍNGUEZ', 'ORIZABA', 'JUNIOR', { ebitda: 500 }),
    row(2, 'ADRIAN DIAZ MENA', 'TULA', 'MANTENIDO', {
        ebitda: -20,
        status: 'BAJA',
    }),
    row(3, 'MARIO PEREZ', 'ORIZABA', 'MANTENIDO', { ebitda: 90, placement: 0 }),
]);

describe('useHardDataFilters', () => {
    it('busca por sucursal sin importar mayúsculas y por una sola letra del nombre', () => {
        const f = useHardDataFilters(rows);
        f.query.value = 'orizaba';
        expect(f.filtered.value.map((r) => r.id)).toEqual([1, 3]);
        f.query.value = 'dominguez';
        expect(f.filtered.value.map((r) => r.id)).toEqual([1]);
        f.query.value = 'orizaba mario';
        expect(f.filtered.value.map((r) => r.id)).toEqual([3]);
    });

    it('sugiere la sucursal y la persona que coinciden con lo escrito', () => {
        const f = useHardDataFilters(rows);
        f.query.value = 'ORIZ';
        expect(f.suggestions.value[0]).toMatchObject({
            kind: 'branch',
            value: 'ORIZABA',
            count: 2,
        });
        f.query.value = 'adri';
        expect(
            f.suggestions.value.some(
                (s) => s.kind === 'person' && s.row.id === 2,
            ),
        ).toBe(true);
    });

    it('combina chips de sucursal, categoría y alertas, y recalcula totales', () => {
        const f = useHardDataFilters(rows);
        f.toggleBranch('ORIZABA');
        expect(f.totals.value.count).toBe(2);
        expect(f.totals.value.ebitda).toBe(590);
        f.toggleCategory('MANTENIDO');
        expect(f.filtered.value.map((r) => r.id)).toEqual([3]);
        f.clearAll();
        f.toggleFlag('ebitda_negativo');
        expect(f.filtered.value.map((r) => r.id)).toEqual([2]);
        f.clearAll();
        f.toggleFlag('sin_colocacion');
        expect(f.filtered.value.map((r) => r.id)).toEqual([3]);
    });

    it('ordena por EBITDA descendente por defecto y alterna la dirección', () => {
        const f = useHardDataFilters(rows);
        expect(f.filtered.value.map((r) => r.id)).toEqual([1, 3, 2]);
        f.setSort('ebitda');
        expect(f.filtered.value.map((r) => r.id)).toEqual([2, 3, 1]);
        f.setSort('name');
        expect(f.filtered.value[0].name).toBe('ADRIAN DIAZ MENA');
    });
});
