<script setup lang="ts">
/**
 * PerHundredBar — barra apilada «de cada $100 que ingresaron…».
 *
 * Se usa en KpiStrip. Cada segmento es una categoría de OPEX (de mayor a menor)
 * y, si sobra, un segmento final de EBITDA en cian. Si el OPEX supera a los
 * ingresos, la barra se escala al gasto y una línea vertical marca hasta dónde
 * alcanzaron los ingresos. No se dibuja si los ingresos no son positivos.
 *
 * Props:
 * - ingresos: ingresos operativos del periodo (texto decimal o null).
 * - ebitda: EBITDA del periodo (texto decimal o null).
 * - categories: categorías de OPEX con clave, etiqueta e importe.
 * - active: clave del segmento resaltado desde fuera (null = ninguno).
 *
 * Emits:
 * - select(key): clic en un segmento o en su leyenda (clave de categoría o 'ebitda').
 */
import { computed, onMounted, ref } from 'vue';
import { opexTone } from './palette';
import { mxn, num } from './types';

/**
 * «De cada $100 que ingresaron…»: reparte los ingresos operativos entre las
 * categorías de OPEX y lo que queda como EBITDA. Sólo es una proporción visual
 * de importes del dataset; las cifras que se leen son las del dataset.
 */
const props = defineProps<{
    ingresos: string | null;
    ebitda: string | null;
    categories: Array<{ key: string; label: string; amount: string | null }>;
    active: string | null;
}>();

const emit = defineEmits<{ select: [key: string] }>();

/** Segmento bajo el cursor o con foco. */
const hovered = ref<string | null>(null);
/** Pasa a true tras el primer cuadro para animar el crecimiento de la barra. */
const grown = ref(false);
/** El escalonado sólo aplica a la entrada; después los cambios son inmediatos. */
const settled = ref(false);

/** Dispara la animación de entrada y, 1.2 s después, desactiva el escalonado. */
onMounted(() =>
    requestAnimationFrame(() => {
        grown.value = true;
        window.setTimeout(() => (settled.value = true), 1200);
    }),
);

/** Ingresos como número. */
const inc = computed(() => num(props.ingresos));
/** Suma absoluta de todas las categorías de OPEX. */
const opexTotal = computed(() =>
    props.categories.reduce((s, c) => s + Math.abs(num(c.amount)), 0),
);
/** Si los gastos superan a los ingresos, la barra mide el gasto y marca dónde llegaron los ingresos. */
const overspent = computed(() => opexTotal.value > inc.value);
/** Referencia del 100 % de la barra: el mayor entre ingresos y OPEX (mínimo 1). */
const base = computed(() => Math.max(inc.value, opexTotal.value) || 1);

/**
 * Convierte un importe en «$X.XX de cada $100 de ingresos».
 * @param v importe (se usa su valor absoluto).
 * @returns texto con 2 decimales, o '—' si no hay ingresos.
 */
const per100 = (v: number) =>
    inc.value > 0 ? `$${((Math.abs(v) / inc.value) * 100).toFixed(2)}` : '—';

/**
 * Segmentos de la barra: categorías con importe > 0 ordenadas de mayor a menor
 * (con su tono de palette) y, si no hay sobregasto y el EBITDA es positivo,
 * el segmento de EBITDA al final.
 */
const segments = computed(() => {
    const cats = props.categories
        .map((c) => ({ ...c, value: Math.abs(num(c.amount)) }))
        .filter((c) => c.value > 0)
        .sort((a, b) => b.value - a.value)
        .map((c, i) => ({
            key: c.key,
            label: c.label,
            value: c.value,
            exact: mxn(c.value),
            width: (c.value / base.value) * 100,
            tone: opexTone(i),
        }));
    const e = num(props.ebitda);

    if (!overspent.value && e > 0) {
        cats.push({
            key: 'ebitda',
            label: 'EBITDA (lo que queda)',
            value: e,
            exact: mxn(props.ebitda),
            width: (e / base.value) * 100,
            tone: 'bg-cyan-600 dark:bg-cyan-500',
        });
    }

    return cats;
});

/** Clave enfocada: la del cursor tiene prioridad sobre la prop `active`. */
const focusKey = computed(() => hovered.value ?? props.active);
/** Segmento enfocado (o null). */
const focusSeg = computed(
    () => segments.value.find((s) => s.key === focusKey.value) ?? null,
);

/** Texto explicativo bajo la barra según el segmento enfocado o el reparto general. */
const caption = computed(() => {
    const s = focusSeg.value;

    if (s) {
        return s.key === 'ebitda'
            ? `Quedan ${per100(s.value)} de EBITDA por cada $100 que ingresaron (${s.exact}).`
            : `${s.label}: ${per100(s.value)} de cada $100 que ingresaron (${s.exact}).`;
    }

    if (overspent.value) {
        return `Los gastos operativos superaron a los ingresos: por cada $100 que ingresaron se gastaron ${per100(opexTotal.value)}.`;
    }

    return `De cada $100 que ingresaron, ${per100(opexTotal.value)} se fueron en gastos operativos y quedaron ${per100(num(props.ebitda))} de EBITDA.`;
});

/** Posición (%) de la marca «hasta aquí alcanzaron los ingresos»; sólo con sobregasto. */
const incomeMark = computed(() =>
    overspent.value ? (inc.value / base.value) * 100 : null,
);
</script>

<template>
    <div v-if="inc > 0" class="space-y-2">
        <div
            class="relative flex h-5 w-full gap-[2px] rounded-full bg-muted/70"
            role="group"
            aria-label="Reparto de cada $100 de ingresos"
            @mouseleave="hovered = null"
        >
            <button
                v-for="(s, i) in segments"
                :key="s.key"
                type="button"
                class="h-full origin-left transition-[transform,opacity,filter] duration-700 ease-[cubic-bezier(0.2,0.8,0.2,1)] first:rounded-l-full last:rounded-r-full focus-visible:z-10 focus-visible:ring-3 focus-visible:ring-cyan-500/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-none motion-reduce:transition-none"
                :class="[
                    s.tone,
                    focusKey && focusKey !== s.key ? 'opacity-35' : '',
                    grown ? 'scale-x-100' : 'scale-x-0',
                ]"
                :style="{
                    width: `${s.width}%`,
                    transitionDelay: settled ? '0ms' : `${i * 70}ms`,
                }"
                :aria-label="`${s.label}: ${per100(s.value)} de cada $100, ${s.exact}`"
                :aria-pressed="active === s.key"
                @mouseenter="hovered = s.key"
                @focus="hovered = s.key"
                @blur="hovered = null"
                @click="emit('select', s.key)"
            />
            <span
                v-if="incomeMark !== null"
                class="pointer-events-none absolute -top-1.5 -bottom-1.5 w-0.5 rounded bg-foreground"
                :style="{ left: `${incomeMark}%` }"
                aria-hidden="true"
                title="Hasta aquí alcanzaron los ingresos"
            />
        </div>
        <p
            class="min-h-5 text-sm text-muted-foreground"
            aria-live="polite"
            :class="
                overspent && !focusSeg ? 'text-rose-700 dark:text-rose-300' : ''
            "
        >
            {{ caption }}
        </p>
        <!-- Leyenda (oculta a lectores de pantalla; la barra ya es accesible) -->
        <ul class="flex flex-wrap gap-x-1 gap-y-1 text-xs" aria-hidden="true">
            <li v-for="s in segments" :key="s.key">
                <button
                    type="button"
                    tabindex="-1"
                    class="inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 transition hover:bg-muted"
                    :class="focusKey && focusKey !== s.key ? 'opacity-50' : ''"
                    @mouseenter="hovered = s.key"
                    @mouseleave="hovered = null"
                    @click="emit('select', s.key)"
                >
                    <span class="size-2.5 rounded-sm" :class="s.tone" />
                    <span class="text-muted-foreground">{{ s.label }}</span>
                    <span class="font-semibold tabular-nums">{{
                        per100(s.value)
                    }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
