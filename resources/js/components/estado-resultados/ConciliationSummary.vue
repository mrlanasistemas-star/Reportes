<script setup lang="ts">
import { ArrowDownToLine, Landmark } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { SEGMENT_FILTERS } from './movementFilters';
import { mxn, num } from './types';
import type { Dataset, Movement } from './types';

const props = defineProps<{
    c: Dataset['conciliation'];
    movements: Movement[];
    activeFilter: string | null;
    compact?: boolean;
}>();

const emit = defineEmits<{ filter: [key: string] }>();

const SWATCH: Record<string, string> = {
    reconocidos: 'bg-emerald-600 dark:bg-emerald-500',
    conciliados: 'bg-cyan-500',
    transferencias: 'bg-slate-400 dark:bg-slate-500',
    fuera: 'bg-slate-600 dark:bg-slate-400',
    pendientes:
        'bg-[repeating-linear-gradient(135deg,#f59e0b_0_6px,#fbbf24_6px_12px)]',
};

/** Segmentos con importes tal cual vienen del dataset. */
const segments = computed(() => {
    const c = props.c;
    const reconocidosCount = props.movements.filter(
        SEGMENT_FILTERS.reconocidos.test,
    ).length;
    const list = [
        {
            key: 'reconocidos',
            count: reconocidosCount,
            amount: c.incorporado_bancos,
        },
        {
            key: 'conciliados',
            count: c.conciliados_erp.count,
            amount: c.conciliados_erp.amount,
        },
        {
            key: 'transferencias',
            count: c.transferencias.count,
            amount: c.transferencias.amount,
        },
        { key: 'fuera', count: c.fuera_er.count, amount: c.fuera_er.amount },
        {
            key: 'pendientes',
            count: c.pendientes.count,
            amount: c.pendientes.amount,
        },
    ].map((s) => ({ ...s, ...SEGMENT_FILTERS[s.key], swatch: SWATCH[s.key] }));
    const total = list.reduce((acc, s) => acc + Math.abs(num(s.amount)), 0);

    return list.map((s) => ({
        ...s,
        // Proporción visual; los segmentos con movimientos nunca desaparecen.
        width: total
            ? Math.max(
                  (Math.abs(num(s.amount)) / total) * 100,
                  s.count ? 1.5 : 0,
              )
            : 0,
    }));
});

const hovered = ref<string | null>(null);

/** Casos especiales que trae el dataset (sólo se muestran si existen). */
const extras = computed(() => {
    const c = props.c;
    const out: Array<{
        label: string;
        value: string;
        hint: string;
        warn?: boolean;
    }> = [];

    for (const m of c.por_tipo_emparejamiento ?? []) {
        out.push({
            label: 'Emparejamiento ' + m.key,
            value: m.count + ' · ' + mxn(m.applied),
            hint: 'Pagos del banco conciliados con el ERP por tipo de emparejamiento.',
        });
    }

    if (c.parciales?.count) {
        out.push({
            label: 'Conciliaciones parciales',
            value:
                c.parciales.count +
                ' · excedente ' +
                mxn(c.parciales.excedente_pendiente),
            hint: 'Parte del pago concilió con el ERP; el excedente queda por clasificar.',
            warn: true,
        });
    }

    if (c.reversos?.count) {
        out.push({
            label: 'Reversos',
            value: c.reversos.count + ' · ' + mxn(c.reversos.amount),
            hint: 'Cargos y su devolución en el mismo periodo: se anulan entre sí.',
        });
    }

    if (c.reembolsos_vinculados?.count) {
        out.push({
            label: 'Reembolsos vinculados',
            value:
                c.reembolsos_vinculados.count +
                ' · ' +
                mxn(c.reembolsos_vinculados.amount),
            hint: 'Reembolsos que reducen la misma línea del gasto original.',
        });
    }

    if (c.erp_saldo_pendiente_pago && num(c.erp_saldo_pendiente_pago)) {
        out.push({
            label: 'Saldo ERP sin pago',
            value: mxn(c.erp_saldo_pendiente_pago),
            hint: 'Requisiciones ERP pagadas sólo en parte según el banco.',
            warn: true,
        });
    }

    return out;
});
const reviewed = computed(() => {
    const total = props.c.movimientos_en_periodo || 0;

    return total === 0
        ? 100
        : Math.round(((total - props.c.pendientes.count) / total) * 100);
});
</script>

<template>
    <section
        class="rounded-2xl border bg-card p-4 shadow-soft-xs md:p-5"
        aria-label="Del banco al resultado"
    >
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="flex items-start gap-3">
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900"
                >
                    <Landmark class="size-5" />
                </span>
                <div>
                    <h3 class="text-base font-extrabold">
                        Del banco al resultado
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        {{ c.movimientos_en_periodo.toLocaleString('es-MX') }}
                        movimientos en el periodo · cargos
                        <b class="text-foreground tabular-nums">{{
                            mxn(c.total_cargos)
                        }}</b>
                        · abonos
                        <b class="text-foreground tabular-nums">{{
                            mxn(c.total_abonos)
                        }}</b>
                        <template v-if="c.fuera_periodo">
                            · {{ c.fuera_periodo }} fuera del periodo</template
                        >
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right">
                    <p class="text-xs text-muted-foreground">Clasificado</p>
                    <p
                        class="text-2xl font-extrabold tabular-nums"
                        :class="
                            reviewed === 100
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : 'text-amber-700 dark:text-amber-400'
                        "
                    >
                        {{ reviewed }}%
                    </p>
                </div>
                <svg
                    viewBox="0 0 36 36"
                    class="size-12 -rotate-90"
                    aria-hidden="true"
                >
                    <circle
                        cx="18"
                        cy="18"
                        r="15.5"
                        fill="none"
                        class="stroke-muted"
                        stroke-width="4"
                    />
                    <circle
                        cx="18"
                        cy="18"
                        r="15.5"
                        fill="none"
                        stroke-width="4"
                        stroke-linecap="round"
                        class="transition-[stroke-dasharray] duration-700"
                        :class="
                            reviewed === 100
                                ? 'stroke-emerald-500'
                                : 'stroke-amber-500'
                        "
                        :stroke-dasharray="`${(reviewed / 100) * 97.4} 97.4`"
                    />
                </svg>
            </div>
        </div>

        <!-- Barra apilada: cada tramo es clicable y filtra la tabla -->
        <div
            class="relative mt-4 flex h-12 w-full gap-0.5 rounded-xl"
            role="group"
            aria-label="Composición de los movimientos del banco por importe"
            @mouseleave="hovered = null"
        >
            <button
                v-for="(s, i) in segments.filter((x) => x.width > 0)"
                :key="s.key"
                type="button"
                class="group/seg relative h-full min-w-2 transition-[transform,filter,opacity] duration-200 first:rounded-l-xl last:rounded-r-xl hover:-translate-y-0.5 hover:brightness-110 focus-visible:z-10 focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none"
                :class="[
                    s.swatch,
                    activeFilter && activeFilter !== s.key ? 'opacity-40' : '',
                    activeFilter === s.key
                        ? 'ring-2 ring-foreground ring-offset-2 ring-offset-card'
                        : '',
                ]"
                :style="{ width: `${s.width}%` }"
                :aria-label="`${s.label}: ${s.count} movimientos, ${mxn(s.amount)}. Filtrar tabla`"
                @mouseenter="hovered = s.key"
                @focus="hovered = s.key"
                @click="emit('filter', s.key)"
            >
                <span
                    v-if="s.width > 9"
                    class="pointer-events-none absolute inset-0 flex items-center justify-center truncate px-2 text-xs font-bold text-white drop-shadow-[0_1px_1px_rgb(0_0_0/0.35)]"
                    >{{ s.label }}</span
                >
                <span
                    class="pointer-events-none absolute top-[calc(100%+8px)] z-20 w-60 rounded-xl border bg-popover p-3 text-left text-xs text-popover-foreground shadow-soft-lg transition duration-150"
                    :class="[
                        hovered === s.key
                            ? 'translate-y-0 opacity-100'
                            : '-translate-y-1 opacity-0',
                        i === 0 ? 'left-0' : 'right-0',
                    ]"
                >
                    <span class="block font-bold">{{ s.label }}</span>
                    <span
                        class="mt-0.5 block text-base font-extrabold tabular-nums"
                        >{{ mxn(s.amount) }}</span
                    >
                    <span class="block text-muted-foreground"
                        >{{
                            s.count.toLocaleString('es-MX')
                        }}
                        movimiento(s)</span
                    >
                    <span class="mt-1.5 block text-muted-foreground">{{
                        s.hint
                    }}</span>
                    <span
                        class="mt-1.5 block font-semibold text-cyan-700 dark:text-cyan-300"
                        >Clic para verlos en la tabla</span
                    >
                </span>
            </button>
            <div
                v-if="!segments.some((x) => x.width > 0)"
                class="flex h-full w-full items-center justify-center rounded-xl border border-dashed text-xs text-muted-foreground"
            >
                Aún no hay movimientos de banco procesados en este periodo.
            </div>
        </div>

        <!-- Leyenda accionable -->
        <div
            class="mt-4 grid gap-2"
            :class="
                compact
                    ? 'sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5'
                    : 'sm:grid-cols-2 lg:grid-cols-5'
            "
        >
            <button
                v-for="s in segments"
                :key="s.key"
                type="button"
                class="group flex flex-col gap-1 rounded-xl border p-3 text-left transition hover:-translate-y-0.5 hover:shadow-soft-sm focus-visible:ring-3 focus-visible:ring-cyan-500/30 focus-visible:outline-none"
                :class="[
                    activeFilter === s.key
                        ? 'border-foreground/40 bg-muted/60'
                        : 'hover:border-foreground/20',
                    hovered === s.key ? 'bg-muted/50' : '',
                    s.key === 'pendientes' && s.count
                        ? 'border-amber-300 dark:border-amber-800'
                        : '',
                ]"
                @mouseenter="hovered = s.key"
                @mouseleave="hovered = null"
                @click="emit('filter', s.key)"
            >
                <span class="flex items-center gap-2 text-xs font-semibold">
                    <span class="size-3 shrink-0 rounded" :class="s.swatch" />
                    {{ s.label }}
                </span>
                <span
                    class="font-extrabold tabular-nums"
                    :class="compact ? 'text-sm' : 'text-lg'"
                    >{{ mxn(s.amount) }}</span
                >
                <span class="text-xs text-muted-foreground"
                    >{{ s.count.toLocaleString('es-MX') }} movimiento(s)</span
                >
                <span
                    v-if="!compact"
                    class="line-clamp-2 text-[11px] leading-snug text-muted-foreground"
                    >{{ s.hint }}</span
                >
            </button>
        </div>

        <div
            v-if="!compact && extras.length"
            class="mt-3 flex flex-wrap gap-2"
            aria-label="Casos especiales de conciliación"
        >
            <span
                v-for="x in extras"
                :key="x.label"
                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                :class="
                    x.warn
                        ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                        : 'bg-muted/40'
                "
                :title="x.hint"
            >
                <b>{{ x.label }}</b>
                <span class="tabular-nums">{{ x.value }}</span>
            </span>
        </div>
        <p
            v-if="
                !compact &&
                (c.erp_fuera_radiografia.count ||
                    num(c.incorporado_erp_fuera_radiografia))
            "
            class="mt-3 flex items-start gap-1.5 text-xs text-muted-foreground"
        >
            <ArrowDownToLine class="mt-0.5 size-3.5 shrink-0" />
            Además, del ERP fuera de Radiografía se incorporan
            <b class="text-foreground tabular-nums">{{
                mxn(c.incorporado_erp_fuera_radiografia)
            }}</b>
            ({{ c.erp_fuera_radiografia.count }} registros por
            {{ mxn(c.erp_fuera_radiografia.amount) }}).
        </p>
    </section>
</template>
