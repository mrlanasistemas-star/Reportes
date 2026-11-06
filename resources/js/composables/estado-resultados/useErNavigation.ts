import { nextTick, ref, watch } from 'vue';
import type { CompMode } from '@/components/estado-resultados/comparativas';

export type ErTabKey =
    | 'resumen'
    | 'comparativas'
    | 'estado'
    | 'conciliacion'
    | 'revision'
    | 'fuera'
    | 'ajustes'
    | 'indicadores'
    | 'fuentes'
    | 'validacion'
    | 'control';

const TABS: string[] = [
    'resumen',
    'comparativas',
    'estado',
    'conciliacion',
    'revision',
    'fuera',
    'ajustes',
    'indicadores',
    'fuentes',
    'validacion',
    'control',
];
const MODES: CompMode[] = [
    'anterior',
    'anio_anterior',
    'evolucion',
    'acumulado',
    'personalizado',
    'sucursales',
];

function fromUrl() {
    if (typeof window === 'undefined') {
        return { tab: 'resumen', mode: 'anterior' as CompMode, contra: null };
    }

    const q = new URLSearchParams(window.location.search);
    const tab = q.get('vista') ?? 'resumen';
    const mode = q.get('comparar') as CompMode | null;
    const contra = Number(q.get('contra'));

    return {
        tab: TABS.includes(tab) ? tab : 'resumen',
        mode: mode && MODES.includes(mode) ? mode : ('anterior' as CompMode),
        contra: Number.isInteger(contra) && contra > 0 ? contra : null,
    };
}

/**
 * Navegación entre vistas del módulo: pestaña activa, filtro de la tabla de
 * movimientos, sección resaltada del estado, paso fijado de la cascada y la
 * comparación elegida. Pestaña y comparación quedan en la URL: recargar o
 * compartir el enlace no pierde la vista.
 */
export function useErNavigation() {
    const initial = fromUrl();
    const tab = ref<ErTabKey | string>(initial.tab);
    const movementFilter = ref<string | null>(null);
    const statementFocus = ref<string | null>(null);
    const selectedStep = ref<string | null>(null);
    const compMode = ref<CompMode>(initial.mode);
    const compContra = ref<number | null>(initial.contra);

    function syncUrl(): void {
        if (typeof window === 'undefined') {
            return;
        }

        const u = new URL(window.location.href);
        const set = (k: string, v: string | null) =>
            v ? u.searchParams.set(k, v) : u.searchParams.delete(k);
        set('vista', tab.value === 'resumen' ? null : tab.value);
        set('comparar', tab.value === 'comparativas' ? compMode.value : null);
        set(
            'contra',
            tab.value === 'comparativas' &&
                compMode.value === 'personalizado' &&
                compContra.value
                ? String(compContra.value)
                : null,
        );
        window.history.replaceState(window.history.state, '', u.toString());
    }

    watch([tab, compMode, compContra], syncUrl);

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
        compMode,
        compContra,
        go,
        openMovements,
        openStatement,
        selectStep,
    };
}
