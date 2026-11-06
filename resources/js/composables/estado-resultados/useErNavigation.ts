import { nextTick, ref } from 'vue';

export type ErTabKey =
    | 'resumen'
    | 'estado'
    | 'conciliacion'
    | 'revision'
    | 'fuera'
    | 'ajustes'
    | 'indicadores'
    | 'fuentes'
    | 'control';

/**
 * Navegación entre vistas del módulo: pestaña activa, filtro de la tabla de
 * movimientos, sección resaltada del estado y paso fijado de la cascada. Así un
 * clic en cualquier cifra lleva al detalle que la explica.
 */
export function useErNavigation() {
    const tab = ref<ErTabKey | string>('resumen');
    const movementFilter = ref<string | null>(null);
    const statementFocus = ref<string | null>(null);
    const selectedStep = ref<string | null>(null);

    function scrollToTabs(): void {
        document
            .getElementById('er-tabs-anchor')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function go(next: string, filter?: string | null): void {
        tab.value = next;

        if (filter !== undefined) {
            movementFilter.value = filter;
        }

        nextTick(scrollToTabs);
    }

    function openMovements(filterKey: string | null): void {
        go('conciliacion', filterKey);
    }

    async function openStatement(key: string): Promise<void> {
        tab.value = 'estado';
        // Reinicia el foco para que el mismo bloque pueda resaltarse dos veces.
        statementFocus.value = null;
        await nextTick();
        statementFocus.value = key;
    }

    function selectStep(key: string): void {
        selectedStep.value = key;

        if (tab.value !== 'resumen') {
            tab.value = 'resumen';
        }

        nextTick(() =>
            document
                .querySelector<HTMLElement>(`[data-er-step="${key}"]`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
        );
    }

    return {
        tab,
        movementFilter,
        statementFocus,
        selectedStep,
        go,
        openMovements,
        openStatement,
        selectStep,
    };
}
