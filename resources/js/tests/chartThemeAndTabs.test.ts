// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';
import type { App } from 'vue';
import RadiographyTabBar from '../components/radiography/tabs/RadiographyTabBar.vue';
import RadiographyTabPanel from '../components/radiography/tabs/RadiographyTabPanel.vue';
import {
    categoryPalette,
    columnOptions,
    donutOptions,
    horizontalBarOptions,
    readChartTokens,
    themedChartOptions,
} from '../lib/chart-theme';

// Tema de gráficas (themedChartOptions) y pestañas de la radiografía
// (montaje diferido + patrón ARIA tabs). Solo presentación: ningún dato cambia.

describe('themedChartOptions', () => {
    const dark = readChartTokens(true);

    it('en oscuro sustituye los neutros claros de chart-theme (rejilla, ejes, leyenda, tooltip)', () => {
        const o = themedChartOptions(horizontalBarOptions(['A', 'B']), true);

        expect(o.theme.mode).toBe('dark');
        expect(o.tooltip.theme).toBe('dark');
        expect(o.grid.borderColor).toBe(dark.grid);
        expect(o.legend.labels.colors).toBe(dark.text);
        expect(o.xaxis.labels.style.colors).toBe(dark.text);
        // Barras horizontales: nombres en el eje Y con texto fuerte.
        expect(o.yaxis.labels.style.colors).toBe(dark.textStrong);
        expect(o.chart.background).toBe('transparent');
    });

    it('conserva datos y formateadores', () => {
        const base = columnOptions(['Ene', 'Feb']);
        const o = themedChartOptions(base, true);

        expect(o.xaxis.categories).toEqual(['Ene', 'Feb']);
        expect(o.yaxis.labels.formatter).toBe(base.yaxis.labels.formatter);
        expect(o.tooltip.y.formatter).toBe(base.tooltip.y.formatter);
    });

    it('mapea la paleta de marca a --chart-N y respeta colores explícitos ajenos a la paleta', () => {
        const o = themedChartOptions(
            { colors: [categoryPalette[0], '#ff00aa'] },
            true,
        );

        expect(o.colors).toEqual([dark.palette[0], '#ff00aa']);
    });

    it('en claro deja los mismos valores que antes', () => {
        const o = themedChartOptions(columnOptions(['X']), false);

        expect(o.theme.mode).toBe('light');
        expect(o.xaxis.labels.style.colors).toBe('#64748b');
        expect(o.colors[0]).toBe(categoryPalette[0]);
    });

    it('donas: borde entre rebanadas del color de la tarjeta y etiquetas centrales con token', () => {
        const o = themedChartOptions(
            {
                ...donutOptions(['a', 'b']),
                stroke: { width: 2, colors: ['#fff'] },
            },
            true,
            'donut',
        );

        expect(o.stroke.colors).toEqual([dark.surface]);
        expect(o.stroke.width).toBe(2);
    });
});

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountTabs() {
    const root = document.createElement('div');
    document.body.appendChild(root);
    const active = ref<'a' | 'b' | 'c'>('a');
    const mounts: string[] = [];
    const Probe = defineComponent({
        props: { name: { type: String, required: true } },
        mounted() {
            mounts.push(this.name);
        },
        render() {
            return h('p', `contenido ${this.name}`);
        },
    });

    app = createApp({
        render: () =>
            h('div', [
                h(RadiographyTabBar as any, {
                    modelValue: active.value,
                    'onUpdate:modelValue': (v: 'a' | 'b' | 'c') =>
                        (active.value = v),
                    tabs: [
                        { key: 'a', label: 'A' },
                        { key: 'b', label: 'B' },
                        { key: 'c', label: 'C' },
                    ],
                    idPrefix: 't',
                    label: 'Secciones',
                }),
                ...(['a', 'b', 'c'] as const).map((k) =>
                    h(
                        RadiographyTabPanel,
                        {
                            tabKey: k,
                            idPrefix: 't',
                            active: active.value === k,
                        },
                        () => h(Probe, { name: k }),
                    ),
                ),
            ]),
    });
    app.mount(root);

    return { root, active, mounts };
}

describe('Pestañas de la radiografía', () => {
    it('solo monta la pestaña activa y conserva las ya visitadas', async () => {
        const { root, active, mounts } = mountTabs();

        expect(mounts).toEqual(['a']);
        active.value = 'c';
        await nextTick();
        expect(mounts).toEqual(['a', 'c']);
        active.value = 'a';
        await nextTick();
        // 'a' no se vuelve a montar (se conservó), 'b' nunca se abrió.
        expect(mounts).toEqual(['a', 'c']);
        expect(root.querySelectorAll('[role="tabpanel"]').length).toBe(2);
    });

    it('expone role=tablist/tab/tabpanel con aria-selected y aria-controls', async () => {
        const { root } = mountTabs();
        const tabs = root.querySelectorAll<HTMLElement>('[role="tab"]');

        expect(
            root.querySelector('[role="tablist"]')?.getAttribute('aria-label'),
        ).toBe('Secciones');
        expect(tabs[0].getAttribute('aria-selected')).toBe('true');
        expect(tabs[1].getAttribute('aria-selected')).toBe('false');
        expect(tabs[0].getAttribute('tabindex')).toBe('0');
        expect(tabs[1].getAttribute('tabindex')).toBe('-1');
        expect(tabs[0].getAttribute('aria-controls')).toBe('t-panel-a');
        expect(
            root.querySelector('#t-panel-a')?.getAttribute('aria-labelledby'),
        ).toBe('t-tab-a');
    });

    it('flechas, Inicio y Fin cambian de pestaña', async () => {
        const { root, active } = mountTabs();
        const list = root.querySelector('[role="tablist"]') as HTMLElement;
        const press = async (key: string) => {
            list.dispatchEvent(
                new KeyboardEvent('keydown', { key, bubbles: true }),
            );
            await nextTick();
        };

        await press('ArrowRight');
        expect(active.value).toBe('b');
        await press('End');
        expect(active.value).toBe('c');
        await press('ArrowRight');
        expect(active.value).toBe('a');
        await press('ArrowLeft');
        expect(active.value).toBe('c');
        await press('Home');
        expect(active.value).toBe('a');
    });
});
