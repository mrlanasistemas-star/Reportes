import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';

import type { Resultado } from '@/components/efectividad/calculadora/types';

/**
 * Filtros en cascada de la calculadora de efectividad: Sucursal → Ruta → Promotor
 * (una ruta pertenece a una sucursal). Al cambiar un nivel se limpian los de abajo.
 */
export function useEfectividadFiltros(resultado: Ref<Resultado | null>) {
    const sucursal = ref<string>('');
    const oficina = ref<string>(''); // RUTA de Lendus
    const promotor = ref<string>('');

    const catalogo = computed(() => resultado.value?.catalogo ?? []);

    const opcionesSucursal = computed(() => [
        { value: '', label: 'Todas las sucursales' },
        ...catalogo.value.map((s) => ({
            value: s.sucursal,
            label: s.sucursal,
        })),
    ]);

    const rutasVisibles = computed(() =>
        catalogo.value
            .filter((s) => !sucursal.value || s.sucursal === sucursal.value)
            .flatMap((s) => s.rutas),
    );

    const opcionesOficina = computed(() => [
        { value: '', label: 'Todas las rutas' },
        ...rutasVisibles.value.map((r) => ({ value: r.ruta, label: r.ruta })),
    ]);

    const opcionesPromotor = computed(() => {
        const lista = oficina.value
            ? rutasVisibles.value.filter((r) => r.ruta === oficina.value)
            : rutasVisibles.value;

        return [
            { value: '', label: 'Todos los promotores' },
            ...lista.flatMap((r) =>
                r.promotores.map((p) => ({
                    value: p,
                    label: oficina.value ? p : `${p} · ${r.ruta}`,
                })),
            ),
        ];
    });

    const hayFiltros = computed(
        () => !!(sucursal.value || oficina.value || promotor.value),
    );

    /** Filtros activos para mostrarlos como etiquetas removibles. */
    const activos = computed(() =>
        [
            {
                clave: 'sucursal' as const,
                titulo: 'Sucursal',
                valor: sucursal.value,
            },
            { clave: 'oficina' as const, titulo: 'Ruta', valor: oficina.value },
            {
                clave: 'promotor' as const,
                titulo: 'Promotor',
                valor: promotor.value,
            },
        ].filter((f) => f.valor),
    );

    watch(sucursal, () => {
        oficina.value = '';
        promotor.value = '';
    });
    watch(oficina, () => {
        promotor.value = '';
    });

    function quitar(clave: 'sucursal' | 'oficina' | 'promotor') {
        ({ sucursal, oficina, promotor })[clave].value = '';
    }

    function limpiar() {
        sucursal.value = '';
        oficina.value = '';
        promotor.value = '';
    }

    function query(): string {
        const q = new URLSearchParams();

        if (sucursal.value) {
            q.set('sucursal', sucursal.value);
        }

        if (oficina.value) {
            q.set('oficina', oficina.value);
        }

        if (promotor.value) {
            q.set('promotor', promotor.value);
        }

        return q.toString();
    }

    return {
        sucursal,
        oficina,
        promotor,
        opcionesSucursal,
        opcionesOficina,
        opcionesPromotor,
        hayFiltros,
        activos,
        quitar,
        limpiar,
        query,
    };
}
