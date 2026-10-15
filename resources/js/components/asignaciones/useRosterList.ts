import type { Ref } from 'vue'
import { computed, ref, watch } from 'vue'
import type { RosterMovementItem } from '@/types/asignaciones'

/**
 * Búsqueda + filtro por categoría/sucursal + paginación para listas del roster
 * (plantilla, movimientos, pendientes). Nunca oculta registros: solo los reparte
 * en páginas; cualquier cambio de filtro regresa a la página 1.
 */
export function useRosterList(items: Ref<RosterMovementItem[]>, pageSize = 10) {
    const query = ref('')
    const category = ref('')
    const branch = ref('')
    const page = ref(1)

    const normalize = (value: string) => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

    const filtered = computed(() => {
        const q = normalize(query.value.trim())

        return items.value.filter((item) => {
            const matchesQuery = !q || normalize(`${item.employee_name} ${item.branch_name ?? ''}`).includes(q)
            const matchesCategory = !category.value || item.status === category.value
            const matchesBranch = !branch.value || (item.branch_name ?? '') === branch.value

            return matchesQuery && matchesCategory && matchesBranch
        })
    })

    const totalPages = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize)))
    const paged = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize))

    const categoryOptions = computed(() => {
        const counts = new Map<string, { label: string; count: number }>()

        for (const item of items.value) {
            if (!item.status) {
                continue
            }

            const current = counts.get(item.status) ?? { label: item.status_label ?? item.status, count: 0 }
            current.count++
            counts.set(item.status, current)
        }

        return Array.from(counts.entries()).map(([value, { label, count }]) => ({ value, label: `${label} (${count})` }))
    })

    const branchOptions = computed(() =>
        Array.from(new Set(items.value.map((i) => i.branch_name).filter((n): n is string => !!n)))
            .sort()
            .map((n) => ({ value: n, label: n })),
    )

    watch([query, category, branch, () => items.value.length], () => {
        page.value = 1
    })

    return { query, category, branch, page, filtered, paged, totalPages, categoryOptions, branchOptions, pageSize }
}
