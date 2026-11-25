<script setup lang="ts">
/**
 * ValidationDifferences — «Diferencias banco ↔ ERP» de la pestaña Validación.
 *
 * Vive dentro del modal de ValidationPanel. Barra fija con tres pestañas (por importe,
 * por fecha, por folio) con su conteo, la explicación del tipo (una sola vez), el total y
 * una búsqueda. Debajo, con desplazamiento interno, una fila limpia por movimiento del
 * banco que no concilió limpio (fecha, concepto, cuenta, banco, ERP, diferencia y chip de
 * lo que pasó; el mensaje largo va en el tooltip). Se abre con clic o Enter si tiene
 * huella. Muestra 50 y «Mostrar más» agrega de 100 en 100.
 *
 * Props:
 * - differences: grupos by_amount / by_date / by_folio de la validación.
 *
 * Emits:
 * - open(fingerprint): abrir el detalle del movimiento.
 */
import { ArrowUpRight, CheckCircle2, Lock, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { mxn, num } from './types';
import type { DiffGroup, DiffItem, Validation } from './types';

const props = defineProps<{ differences: Validation['differences'] }>();
const emit = defineEmits<{ open: [fingerprint: string] }>();

/** Pestañas con su explicación de qué significa cada tipo de diferencia. */
const TABS: Array<{
    key: keyof Validation['differences'];
    label: string;
    explain: string;
}> = [
    {
        key: 'by_amount',
        label: 'Por importe',
        explain:
            'El folio existe en el ERP, pero el banco pagó un importe distinto.',
    },
    {
        key: 'by_date',
        label: 'Por fecha',
        explain:
            'Mismo folio e importe, pero la fecha cae fuera de la tolerancia.',
    },
    {
        key: 'by_folio',
        label: 'Por folio',
        explain:
            'El folio no está en el ERP, es de otro periodo, ya se usó o es ambiguo.',
    },
];

/** Primera pestaña con diferencias (si no hay ninguna, «Por importe»). */
const firstWithDifferences = (): keyof Validation['differences'] =>
    TABS.find((t) => props.differences[t.key].count)?.key ?? 'by_amount';
/** Pestaña activa: la primera con diferencias (si no, «Por importe»). */
const tab = ref<keyof Validation['differences']>(firstWithDifferences());
/** Grupo de diferencias de la pestaña activa. */
const group = computed<DiffGroup>(() => props.differences[tab.value]);
/** Texto de búsqueda (concepto, folio, cuenta o fecha). */
const search = ref('');
/** Cantidad de renglones visibles. */
const limit = ref(50);
/** Renglones de la pestaña que pasan la búsqueda. */
const matching = computed<DiffItem[]>(() => {
    const q = search.value.trim().toLowerCase();

    return q
        ? group.value.items.filter((r) =>
              [r.concept, r.folio, r.account_slot, r.date, r.status_label]
                  .filter(Boolean)
                  .join(' ')
                  .toLowerCase()
                  .includes(q),
          )
        : group.value.items;
});
/** Renglones visibles (recortados a `limit`). */
const rows = computed<DiffItem[]>(() => matching.value.slice(0, limit.value));

/**
 * Cambia de pestaña y reinicia el recorte.
 * @param key pestaña elegida.
 */
function pick(key: keyof Validation['differences']): void {
    tab.value = key;
    limit.value = 50;
}

// Al llegar una validación nueva (otro periodo, recálculo), vuelve a elegir la
// pestaña con diferencias si la actual quedó vacía y reinicia el recorte.
watch(
    () => props.differences,
    () => {
        if (!props.differences[tab.value].count) {
            tab.value = firstWithDifferences();
        }

        limit.value = 50;
    },
);
watch(search, () => (limit.value = 50));
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <!-- Barra fija: pestañas, explicación y búsqueda -->
        <div class="shrink-0 space-y-3 border-b bg-muted/25 px-5 py-3 sm:px-6">
            <div class="flex flex-wrap items-center gap-2">
                <div
                    class="inline-flex rounded-2xl border bg-background p-1"
                    role="tablist"
                    aria-label="Tipo de diferencia"
                >
                    <button
                        v-for="t in TABS"
                        :key="t.key"
                        type="button"
                        role="tab"
                        :aria-selected="tab === t.key"
                        class="inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold transition focus-visible:ring-2 focus-visible:ring-rose-400 focus-visible:outline-none"
                        :class="
                            tab === t.key
                                ? 'bg-foreground text-background shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="pick(t.key)"
                    >
                        {{ t.label }}
                        <span
                            class="rounded-full px-1.5 text-xs font-bold tabular-nums"
                            :class="
                                differences[t.key].count
                                    ? 'bg-rose-500 text-white'
                                    : 'bg-muted text-muted-foreground'
                            "
                            >{{ differences[t.key].count }}</span
                        >
                    </button>
                </div>
                <label class="relative min-w-52 flex-1">
                    <span class="sr-only">Buscar diferencias</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Buscar concepto, folio, cuenta…"
                        class="h-10 w-full rounded-xl border bg-background pr-3 pl-9 text-sm shadow-xs outline-none focus-visible:border-rose-400 focus-visible:ring-2 focus-visible:ring-rose-400/30"
                    />
                </label>
            </div>
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                <span class="text-muted-foreground">{{
                    TABS.find((t) => t.key === tab)?.explain
                }}</span>
                <span v-if="group.count" class="font-semibold">
                    Total en banco:
                    <b class="text-rose-700 tabular-nums dark:text-rose-400">{{
                        mxn(group.amount)
                    }}</b>
                </span>
            </p>
        </div>

        <!-- Lista con desplazamiento interno -->
        <div
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain scroll-smooth px-4 py-4 sm:px-6"
        >
            <div
                v-if="!group.count"
                class="flex items-center justify-center gap-2 rounded-2xl border border-dashed border-emerald-300 bg-emerald-50/60 p-8 text-[15px] font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-300"
            >
                <CheckCircle2 class="size-5" /> Sin diferencias de este tipo.
            </div>
            <p
                v-else-if="!matching.length"
                class="rounded-2xl border border-dashed p-8 text-center text-[15px] text-muted-foreground"
            >
                Ninguna diferencia coincide con la búsqueda.
            </p>
            <ul
                v-else
                class="divide-y divide-border/70 overflow-hidden rounded-2xl border bg-card shadow-soft-xs"
            >
                <!-- Encabezado de columnas (pantallas anchas) -->
                <li
                    class="hidden grid-cols-[6.5rem_minmax(0,1fr)_8.5rem_8.5rem_8rem_1.25rem] gap-3 bg-muted/40 px-4 py-2 text-xs font-semibold text-muted-foreground md:grid"
                    aria-hidden="true"
                >
                    <span>Fecha</span>
                    <span>Concepto · qué pasó</span>
                    <span class="text-right">Banco</span>
                    <span class="text-right">ERP</span>
                    <span class="text-right">Diferencia</span>
                    <span />
                </li>
                <li
                    v-for="(r, i) in rows"
                    :key="r.fingerprint ?? i"
                    :tabindex="r.fingerprint ? 0 : -1"
                    :role="r.fingerprint ? 'button' : undefined"
                    class="group grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1 px-4 py-3 md:grid-cols-[6.5rem_minmax(0,1fr)_8.5rem_8.5rem_8rem_1.25rem] md:items-center"
                    :class="
                        r.fingerprint
                            ? 'cursor-pointer transition-colors hover:bg-rose-50/60 focus-visible:bg-rose-50 focus-visible:outline-none dark:hover:bg-rose-950/20 dark:focus-visible:bg-rose-950/30'
                            : ''
                    "
                    @click="r.fingerprint && emit('open', r.fingerprint)"
                    @keydown.enter="
                        r.fingerprint && emit('open', r.fingerprint)
                    "
                >
                    <span
                        class="hidden text-sm text-muted-foreground tabular-nums md:block"
                        >{{ r.date }}</span
                    >
                    <span class="min-w-0">
                        <span
                            class="flex items-center gap-1.5 text-[15px] font-semibold"
                        >
                            <Lock
                                v-if="r.redacted"
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                            <span class="truncate" :title="r.concept ?? ''">{{
                                r.concept ?? '—'
                            }}</span>
                        </span>
                        <span
                            class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span class="tabular-nums md:hidden">{{
                                r.date
                            }}</span>
                            <span>{{ r.account_slot }}</span>
                            <span v-if="r.folio" class="font-mono"
                                >Folio {{ r.folio }}</span
                            >
                            <span
                                class="inline-block max-w-full truncate rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-800 dark:bg-rose-950/60 dark:text-rose-300"
                                :title="r.message ?? r.status_label"
                                >{{ r.status_label }}</span
                            >
                        </span>
                    </span>
                    <span
                        class="text-right text-[15px] font-bold whitespace-nowrap tabular-nums"
                    >
                        {{ mxn(r.bank_amount) }}
                        <span
                            class="block text-xs font-normal text-muted-foreground md:hidden"
                            >banco</span
                        >
                    </span>
                    <span
                        class="col-span-2 text-sm whitespace-nowrap text-muted-foreground tabular-nums md:col-span-1 md:text-right"
                    >
                        <span class="md:hidden">ERP </span>
                        {{
                            r.erp_amounts.length
                                ? r.erp_amounts.map((a) => mxn(a)).join(' + ')
                                : '—'
                        }}
                    </span>
                    <span
                        class="col-span-2 text-sm font-bold whitespace-nowrap tabular-nums md:col-span-1 md:text-right"
                        :class="
                            r.difference && num(r.difference)
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-muted-foreground'
                        "
                    >
                        <span class="font-normal md:hidden">Diferencia </span>
                        {{ r.difference ? mxn(r.difference) : '—' }}
                        <span
                            v-if="r.delta_days !== null"
                            class="text-xs font-semibold md:block"
                            >{{ r.delta_days }} día(s)</span
                        >
                    </span>
                    <span class="hidden text-right md:block">
                        <ArrowUpRight
                            v-if="r.fingerprint"
                            class="inline size-4 text-muted-foreground transition group-hover:text-rose-600"
                        />
                    </span>
                </li>
            </ul>
            <button
                v-if="matching.length > limit"
                type="button"
                class="mt-4 w-full rounded-2xl border border-dashed py-3 text-sm font-semibold text-muted-foreground transition hover:border-rose-300 hover:text-foreground"
                @click="limit += 100"
            >
                Mostrar más ({{ matching.length - limit }} restantes)
            </button>
        </div>
    </div>
</template>
