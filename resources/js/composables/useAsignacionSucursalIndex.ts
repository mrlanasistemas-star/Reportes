/**
 * Lógica de filtrado y formato para una vista de asignación de colaboradores a
 * sucursal (lista de coincidencias nombre → sucursal con su estado de revisión).
 */
import { computed, ref } from 'vue';

/** Una asignación colaborador → sucursal tal como llega del backend. */
type AssignmentItem = {
    id: number;
    employee_name: string;
    normalized_name?: string | null;
    branch_name?: string | null;
    source_name?: string | null;
    source_reference?: string | null;
    match_type?:
        | 'exact'
        | 'normalized'
        | 'manual'
        | 'unmatched'
        | string
        | null;
    confidence?: number | null;
    was_manual_reviewed?: boolean;
    ui_status: 'matched' | 'pending' | 'manual' | 'unmatched';
    period_label?: string | null;
    updated_at?: string | null;
    notes?: string | null;
};

/** Props que recibe la página: asignaciones y catálogo de sucursales. */
type Props = {
    assignments: AssignmentItem[];
    branches: Array<{
        id: number;
        name: string;
    }>;
};

/**
 * Composable con filtros (texto y estado), contadores y helpers de formato.
 * @param props props de la página (reactivas).
 */
export function useAsignacionSucursalIndex(props: Props) {
    /** Texto de búsqueda libre. */
    const filters = ref({
        query: '',
    });

    /** Estado seleccionado en el filtro ('all' = todos). */
    const selectedStatus = ref<
        'all' | 'matched' | 'pending' | 'manual' | 'unmatched'
    >('all');

    /** Asignaciones que coinciden con el texto (nombre, nombre normalizado, sucursal o fuente) y el estado. */
    const filteredAssignments = computed(() => {
        const query = filters.value.query.trim().toLowerCase();

        return props.assignments.filter((item) => {
            const matchesQuery =
                !query ||
                item.employee_name.toLowerCase().includes(query) ||
                (item.normalized_name ?? '').toLowerCase().includes(query) ||
                (item.branch_name ?? '').toLowerCase().includes(query) ||
                (item.source_name ?? '').toLowerCase().includes(query);

            const matchesStatus =
                selectedStatus.value === 'all' ||
                item.ui_status === selectedStatus.value;

            return matchesQuery && matchesStatus;
        });
    });

    /** Contadores para las tarjetas resumen (pendientes incluye las manuales). */
    const totalAssignments = computed(() => props.assignments.length);
    const matchedAssignments = computed(
        () => props.assignments.filter((a) => a.ui_status === 'matched').length,
    );
    const pendingAssignments = computed(
        () =>
            props.assignments.filter(
                (a) => a.ui_status === 'pending' || a.ui_status === 'manual',
            ).length,
    );
    const unmatchedAssignments = computed(
        () =>
            props.assignments.filter((a) => a.ui_status === 'unmatched').length,
    );

    /** Clases Tailwind de la etiqueta según el estado de la asignación. */
    const statusClass = (status: AssignmentItem['ui_status']) => {
        if (status === 'matched') {
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
        }

        if (status === 'manual') {
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300';
        }

        if (status === 'pending') {
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300';
        }

        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
    };

    /** Traduce el tipo de coincidencia a texto en español. */
    const formatMatchType = (matchType?: AssignmentItem['match_type']) => {
        if (matchType === 'exact') {
            return 'Exacto';
        }

        if (matchType === 'normalized') {
            return 'Normalizado';
        }

        if (matchType === 'manual') {
            return 'Manual';
        }

        if (matchType === 'unmatched') {
            return 'Sin match';
        }

        return 'Sin definir';
    };

    /** Formatea la confianza (0–1) como porcentaje entero; '—' si no hay dato. */
    const formatConfidence = (confidence?: number | null) => {
        if (confidence === null || confidence === undefined) {
            return '—';
        }

        return `${Math.round(confidence * 100)}%`;
    };

    return {
        filters,
        selectedStatus,
        filteredAssignments,
        totalAssignments,
        matchedAssignments,
        pendingAssignments,
        unmatchedAssignments,
        statusClass,
        formatMatchType,
        formatConfidence,
    };
}
