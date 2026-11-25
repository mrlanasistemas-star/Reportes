/**
 * Pila global de capas para diálogos/modales: el ÚLTIMO que se abre queda siempre encima,
 * aunque su portal se haya montado antes en el DOM (p. ej. el detalle de un movimiento o
 * «Clasificar» abiertos desde la lista de un modal). Sin esto todos comparten z-50 y gana
 * el orden del DOM: se ve el fondo oscuro pero la ventana nueva queda detrás.
 *
 * Se reinicia a la base cuando no queda ninguno abierto, así nunca supera a SweetAlert2 (1060).
 */
import { onBeforeUnmount, ref, watch } from 'vue';

const BASE = 50;
let openCount = 0;
let top = BASE;

/**
 * @param isOpen getter del estado abierto del diálogo
 * @returns zIndex reactivo para el overlay (zIndex) y el contenido (zIndex + 1)
 */
export function useModalLayer(isOpen: () => boolean) {
    const zIndex = ref(BASE);
    let held = false;

    const release = () => {
        if (!held) {
            return;
        }

        held = false;
        openCount = Math.max(0, openCount - 1);

        if (openCount === 0) {
            top = BASE;
        }
    };

    watch(
        isOpen,
        (open) => {
            if (open && !held) {
                held = true;
                openCount++;
                top += 2;
                zIndex.value = top;
            } else if (!open) {
                release();
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(release);

    return { zIndex };
}
