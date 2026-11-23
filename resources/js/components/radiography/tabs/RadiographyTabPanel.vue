<script setup lang="ts">
/**
 * RadiographyTabPanel — panel (role=tabpanel) de una pestaña de la radiografía.
 *
 * Se usa en ReportesMensuales/Preview junto con RadiographyTabBar.
 *
 * Props:
 * - tabKey: clave de la pestaña a la que pertenece.
 * - idPrefix: prefijo de ids compartido con la barra de pestañas.
 * - active: si la pestaña está seleccionada.
 */
// Panel de pestaña con montaje diferido: el contenido (y sus gráficas) NO se
// monta hasta la primera vez que se abre la pestaña; después se conserva
// montado y solo se oculta (mismo efecto que <KeepAlive> para las ya
// visitadas) — volver a una pestaña es instantáneo y no pierde su estado.
import { ref, watch } from 'vue';
import { tabDomId, tabPanelDomId } from './tabIds';

const props = defineProps<{
    tabKey: string;
    idPrefix: string;
    active: boolean;
}>();

/** true desde la primera vez que la pestaña se activa (a partir de ahí queda montada). */
const visited = ref(props.active);

/** Marca el panel como visitado cuando se activa. */
watch(
    () => props.active,
    (isActive) => {
        if (isActive) {
            visited.value = true;
        }
    },
);
</script>

<template>
    <div
        v-if="visited"
        v-show="active"
        :id="tabPanelDomId(idPrefix, tabKey)"
        role="tabpanel"
        :aria-labelledby="tabDomId(idPrefix, tabKey)"
        tabindex="0"
        class="focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
    >
        <slot />
    </div>
</template>
