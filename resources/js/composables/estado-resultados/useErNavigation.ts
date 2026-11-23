/**
 * Módulo: navegación entre pestañas del Estado de Resultados.
 *
 * Lee y escribe en la URL los parámetros `vista` (pestaña), `comparar` (modo de
 * comparación) y `contra` (periodo personalizado) para que la vista sobreviva a
 * recargas y enlaces compartidos. No pide nada al servidor.
 */
import { nextTick, ref, watch } from 'vue';
import type { CompMode } from '@/components/estado-resultados/comparativas';

/** Claves de las pestañas del módulo. */
export type ErTabKey =
    | 'resumen'
    | 'comparativas'
    | 'estado'
    | 'conciliacion'
    | 'revision'
    | 'fuera'
    | 'ajustes'
    | 'gastos_financieros'
    | 'indicadores'
    | 'fuentes'
    | 'validacion'
    | 'control'
    | 'catalogo';

/** Pestañas válidas en la URL (cualquier otra vuelve a «resumen»). */
const TABS: string[] = [
    'resumen',
    'comparativas',
    'estado',
    'conciliacion',
    'revision',
    'fuera',
    'ajustes',
    'gastos_financieros',
    'indicadores',
    'fuentes',
    'validacion',
    'control',
    'catalogo',
];
/** Modos de comparación válidos en la URL (cualquier otro vuelve a «anterior»). */
const MODES: CompMode[] = [
    'anterior',
    'anio_anterior',
    'evolucion',
    'acumulado',
    'personalizado',
    'sucursales',
];

/**
 * Estado inicial a partir de la URL (`vista`, `comparar`, `contra`), validando
 * cada valor; en SSR devuelve los valores por defecto.
 */
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
    /** Pestaña activa. */
    const tab = ref<ErTabKey | string>(initial.tab);
    /** Filtro aplicado a la tabla de movimientos de conciliación. */
    const movementFilter = ref<string | null>(null);
    /** Bloque del estado de resultados a resaltar. */
    const statementFocus = ref<string | null>(null);
    /** Paso fijado de la cascada del resumen. */
    const selectedStep = ref<string | null>(null);
    /** Modo de comparación elegido. */
    const compMode = ref<CompMode>(initial.mode);
    /** Periodo contra el que se compara en modo personalizado. */
    const compContra = ref<number | null>(initial.contra);

    /**
     * Escribe pestaña/comparación en la URL sin recargar; omite los valores por
     * defecto para que la URL quede limpia.
     */
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

    /** Cualquier cambio de pestaña o comparación se refleja en la URL. */
    watch([tab, compMode, compContra], syncUrl);

    /** Desplaza suavemente la vista hasta el ancla de las pestañas (`#er-tabs-anchor`). */
    function scrollToTabs(): void {
        document
            .getElementById('er-tabs-anchor')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /**
     * Cambia de pestaña y baja hasta ella.
     * @param next Pestaña destino.
     * @param filter Filtro de movimientos; `undefined` conserva el actual, `null` lo quita.
     */
    function go(next: string, filter?: string | null): void {
        tab.value = next;

        if (filter !== undefined) {
            movementFilter.value = filter;
        }

        nextTick(scrollToTabs);
    }

    /** Abre la pestaña de conciliación con el filtro de movimientos indicado. */
    function openMovements(filterKey: string | null): void {
        go('conciliacion', filterKey);
    }

    /** Abre la pestaña del estado de resultados y resalta el bloque `key`. */
    async function openStatement(key: string): Promise<void> {
        tab.value = 'estado';
        // Reinicia el foco para que el mismo bloque pueda resaltarse dos veces.
        statementFocus.value = null;
        await nextTick();
        statementFocus.value = key;
    }

    /**
     * Fija un paso de la cascada: vuelve al resumen si hace falta y centra en
     * pantalla el elemento con `data-er-step="{key}"`.
     */
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
