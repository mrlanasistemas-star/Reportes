// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';
import type { App } from 'vue';
import PeriodRail from '../components/datos-duros/PeriodRail.vue';

// Navegador de periodos de «Datos duros»: ‹ = anterior (más antiguo),
// › = siguiente (más reciente); también con ← → del teclado.

const PERIODS = [
    {
        id: 21,
        name: 'Junio 2026',
        range: null,
        type: 'monthly',
        year: 2026,
        month: 6,
    },
    {
        id: 20,
        name: 'Mayo 2026',
        range: null,
        type: 'monthly',
        year: 2026,
        month: 5,
    },
    {
        id: 19,
        name: 'Abril 2026',
        range: null,
        type: 'monthly',
        year: 2026,
        month: 4,
    },
];

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mount(start: number) {
    const value = ref<number | null>(start);
    const el = document.createElement('div');
    document.body.appendChild(el);
    app = createApp(
        defineComponent(
            () => () =>
                h(PeriodRail, {
                    periods: PERIODS,
                    modelValue: value.value,
                    'onUpdate:modelValue': (v: number) => (value.value = v),
                }),
        ),
    );
    app.mount(el);

    return value;
}

const prevBtn = () =>
    document.querySelector<HTMLButtonElement>(
        '[aria-label^="Periodo anterior"], [aria-label="No hay periodo anterior"]',
    )!;
const nextBtn = () =>
    document.querySelector<HTMLButtonElement>(
        '[aria-label^="Periodo siguiente"], [aria-label="No hay periodo siguiente"]',
    )!;

describe('PeriodRail', () => {
    it('la flecha izquierda va al periodo anterior y la derecha al siguiente', async () => {
        const value = mount(21);
        expect(nextBtn().disabled).toBe(true);
        prevBtn().click();
        await nextTick();
        expect(value.value).toBe(20);
        prevBtn().click();
        await nextTick();
        expect(value.value).toBe(19);
        expect(prevBtn().disabled).toBe(true);
        nextBtn().click();
        await nextTick();
        expect(value.value).toBe(20);
    });

    it('las flechas del teclado cambian de periodo, pero no mientras se escribe', async () => {
        const value = mount(20);
        window.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowRight' }),
        );
        await nextTick();
        expect(value.value).toBe(21);

        const input = document.createElement('input');
        document.body.appendChild(input);
        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }),
        );
        await nextTick();
        expect(value.value).toBe(21);
    });

    it('el carril va del más antiguo al más reciente', () => {
        mount(21);
        const names = [...document.querySelectorAll('[role="radio"]')].map(
            (b) => b.textContent?.trim(),
        );
        expect(names).toEqual(['Abril 2026', 'Mayo 2026', 'Junio 2026']);
    });
});
