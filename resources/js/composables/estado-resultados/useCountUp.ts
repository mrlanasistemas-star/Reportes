import { onBeforeUnmount, ref, watch } from 'vue';
import type { WatchSource } from 'vue';

const prefersReducedMotion = () =>
    typeof window !== 'undefined' &&
    window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * Cuenta animada hacia un importe del dataset. El valor final SIEMPRE es el del
 * dataset (sólo los cuadros intermedios se interpolan); con movimiento reducido
 * salta directo al valor.
 */
export function useCountUp(source: WatchSource<number>, duration = 900) {
    const display = ref(0);
    let frame = 0;

    function animate(to: number): void {
        cancelAnimationFrame(frame);

        if (prefersReducedMotion() || duration <= 0) {
            display.value = to;

            return;
        }

        const from = display.value;
        const start = performance.now();

        const tick = (now: number) => {
            const t = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            display.value = t >= 1 ? to : from + (to - from) * eased;

            if (t < 1) {
                frame = requestAnimationFrame(tick);
            }
        };

        frame = requestAnimationFrame(tick);
    }

    watch(source, (v) => animate(Number(v) || 0), { immediate: true });
    onBeforeUnmount(() => cancelAnimationFrame(frame));

    return display;
}
