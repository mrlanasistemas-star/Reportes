import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import type { RosterMovementItem } from '@/types/asignaciones';

/**
 * Búsqueda + filtro por categoría/sucursal + paginación para listas del roster
 * (plantilla, movimientos, pendientes). Nunca oculta registros: solo los reparte
 * en páginas; cualquier cambio de filtro regresa a la página 1.
 *
 * Lo usan RosterOverviewPanel, PeriodMovementsPanel y PendingReconciliationPanel.
 *
 * @param items lista reactiva de movimientos a filtrar
 * @param pageSize registros por página (10 por defecto)
 * @returns texto de búsqueda, filtros, página actual, listas filtrada y paginada,
 *          total de páginas y opciones de los selectores de categoría y sucursal
 */
export function useRosterList(items: Ref<RosterMovementItem[]>, pageSize = 10) {
    /** Texto de búsqueda (nombre del colaborador o sucursal). */
    const query = ref('');
    /** Estado de movimiento seleccionado ('' = todos). */
    const category = ref('');
    /** Sucursal seleccionada ('' = todas). */
    const branch = ref('');
    /** Página actual (empieza en 1). */
    const page = ref(1);

    /** Pasa a minúsculas y quita acentos para comparar sin distinguirlos. */
    const normalize = (value: string) =>
        value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    /** Movimientos que cumplen búsqueda, categoría y sucursal. */
    const filtered = computed(() => {
        const q = normalize(query.value.trim());

        return items.value.filter((item) => {
            const matchesQuery =
                !q ||
                normalize(
                    `${item.employee_name} ${item.branch_name ?? ''}`,
                ).includes(q);
            const matchesCategory =
                !category.value || item.status === category.value;
            const matchesBranch =
                !branch.value || (item.branch_name ?? '') === branch.value;

            return matchesQuery && matchesCategory && matchesBranch;
        });
    });

    /** Total de páginas (mínimo 1). */
    const totalPages = computed(() =>
        Math.max(1, Math.ceil(filtered.value.length / pageSize)),
    );
    /** Movimientos de la página actual. */
    const paged = computed(() =>
        filtered.value.slice(
            (page.value - 1) * pageSize,
            page.value * pageSize,
        ),
    );

    /** Opciones del filtro de categoría con su conteo, p. ej. "Alta confirmada (3)". */
    const categoryOptions = computed(() => {
        const counts = new Map<string, { label: string; count: number }>();

        for (const item of items.value) {
            if (!item.status) {
                continue;
            }

            const current = counts.get(item.status) ?? {
                label: item.status_label ?? item.status,
                count: 0,
            };
            current.count++;
            counts.set(item.status, current);
        }

        return Array.from(counts.entries()).map(
            ([value, { label, count }]) => ({
                value,
                label: `${label} (${count})`,
            }),
        );
    });

    /** Sucursales presentes en la lista, ordenadas alfabéticamente. */
    const branchOptions = computed(() =>
        Array.from(
            new Set(
                items.value
                    .map((i) => i.branch_name)
                    .filter((n): n is string => !!n),
            ),
        )
            .sort()
            .map((n) => ({ value: n, label: n })),
    );

    /** Cualquier cambio de búsqueda, filtro o tamaño de la lista regresa a la página 1. */
    watch([query, category, branch, () => items.value.length], () => {
        page.value = 1;
    });

    return {
        query,
        category,
        branch,
        page,
        filtered,
        paged,
        totalPages,
        categoryOptions,
        branchOptions,
        pageSize,
    };
}
