import { useResizeObserver } from '@vueuse/core'
import { nextTick, onMounted, ref, watch } from 'vue'
import type { Ref, WatchSource } from 'vue'

/**
 * Indicador deslizante para una fila de pestañas (27-oct-2026).
 *
 * Mide la pestaña activa (`[role="tab"][aria-selected="true"]`) dentro de
 * `container` y devuelve el estilo de un elemento `.app-tab-indicator` de 1px
 * de ancho: se mueve con translateX y se estira con scaleX, así la animación
 * es solo de transform (sin animar left/width). El contenedor debe tener
 * `position: relative`. `data-ready` activa la transición después de la
 * primera medición, para que al montar no "vuele" desde la izquierda.
 *
 * Uso:
 *   const tabsRef = ref<HTMLElement | null>(null)
 *   const { indicatorStyle, indicatorReady } = useTabIndicator(tabsRef, () => section.value)
 *   <nav ref="tabsRef" class="relative" role="tablist">…
 *       <span class="app-tab-indicator" :style="indicatorStyle" :data-ready="indicatorReady" aria-hidden="true" />
 */
export function useTabIndicator(container: Ref<HTMLElement | null>, active: WatchSource<unknown>) {
    const indicatorStyle = ref<Record<string, string>>({ opacity: '0' })
    const indicatorReady = ref(false)

    function update() {
        const tab = container.value?.querySelector<HTMLElement>('[role="tab"][aria-selected="true"]')

        if (!tab) {
            indicatorStyle.value = { opacity: '0' }

            return
        }

        indicatorStyle.value = {
            opacity: '1',
            transform: `translateX(${tab.offsetLeft}px) scaleX(${tab.offsetWidth})`,
        }
    }

    onMounted(() => {
        update()
        requestAnimationFrame(() => {
            indicatorReady.value = true
        })
    })

    useResizeObserver(container, update)

    watch(active, () => nextTick(update))

    return { indicatorStyle, indicatorReady }
}
