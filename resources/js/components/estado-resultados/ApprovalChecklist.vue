<script setup lang="ts">
import {
    CheckCircle2,
    CircleAlert,
    FileSpreadsheet,
    Info,
    XCircle,
} from 'lucide-vue-next';
import { computed } from 'vue';
import FileLifecycle from './FileLifecycle.vue';
import { mxn } from './types';
import type { Dataset } from './types';

const props = defineProps<{ dataset: Dataset }>();
defineEmits<{ go: [tab: string] }>();

type Tone = 'ok' | 'warn' | 'bad';

/** Fuentes del periodo: completas, con observaciones informativas o bloqueantes. */
const sources = computed(() => {
    const s = props.dataset.sources;
    const simple = [s.radiografia, s.erp, s.nomina, s.ajustes].map((x) => ({
        key: x.label,
        label: x.label,
        detail: x.detail,
        tone: (x.status === 'procesado' || x.status === 'cargado'
            ? 'ok'
            : x.status === 'pendiente'
              ? 'bad'
              : 'warn') as Tone,
        lifecycle: null as string | null,
        info: [] as string[],
        blocking: [] as string[],
    }));
    const accounts = s.cuentas.map((c) => {
        const detail = c.upload?.warnings_detail ?? [];
        const blocking = detail
            .filter((w) => w.severity === 'bloqueante')
            .map((w) => w.message);
        const info = detail
            .filter((w) => w.severity !== 'bloqueante')
            .map((w) => w.message);

        return {
            key: c.key,
            label: c.label,
            detail: c.upload ? c.upload.name : 'Sin archivo cargado',
            tone: (!c.upload || blocking.length
                ? 'bad'
                : c.status === 'con_incidencias' || info.length
                  ? 'warn'
                  : 'ok') as Tone,
            lifecycle: c.upload?.lifecycle ?? null,
            info,
            blocking,
        };
    });

    return [...simple, ...accounts];
});

const open = computed(() =>
    (props.dataset.validation?.unresolved ?? []).filter(
        (u) => (u.open_count ?? u.count) > 0,
    ),
);

const ICON = { ok: CheckCircle2, warn: CircleAlert, bad: XCircle };
const TONE = {
    ok: 'text-emerald-600 dark:text-emerald-400',
    warn: 'text-amber-600 dark:text-amber-400',
    bad: 'text-rose-600 dark:text-rose-400',
};
</script>

<template>
    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-xl border p-3">
            <div class="flex items-center justify-between gap-2">
                <h4 class="text-sm font-bold">Fuentes del periodo</h4>
                <button
                    type="button"
                    class="text-xs font-semibold text-cyan-700 hover:underline dark:text-cyan-300"
                    @click="$emit('go', 'fuentes')"
                >
                    Ver fuentes
                </button>
            </div>
            <ul class="mt-2 space-y-2">
                <li
                    v-for="s in sources"
                    :key="s.key"
                    class="flex gap-2 text-xs"
                >
                    <component
                        :is="ICON[s.tone]"
                        class="mt-0.5 size-4 shrink-0"
                        :class="TONE[s.tone]"
                    />
                    <span class="min-w-0 flex-1">
                        <span
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <b>{{ s.label }}</b>
                            <FileLifecycle
                                v-if="s.lifecycle"
                                :lifecycle="s.lifecycle"
                                compact
                            />
                        </span>
                        <span
                            class="block truncate text-muted-foreground"
                            :title="s.detail"
                        >
                            <FileSpreadsheet
                                v-if="s.lifecycle"
                                class="mr-0.5 inline size-3"
                            />{{ s.detail }}
                        </span>
                        <span
                            v-for="(w, i) in s.blocking"
                            :key="`b${i}`"
                            class="mt-0.5 flex gap-1 font-semibold text-rose-700 dark:text-rose-300"
                            ><XCircle class="mt-0.5 size-3 shrink-0" />
                            Bloqueante: {{ w }}</span
                        >
                        <span
                            v-for="(w, i) in s.info"
                            :key="`i${i}`"
                            class="mt-0.5 flex gap-1 text-muted-foreground"
                            ><Info class="mt-0.5 size-3 shrink-0" />
                            Informativo: {{ w }}</span
                        >
                    </span>
                </li>
            </ul>
        </section>

        <section class="rounded-xl border p-3">
            <div class="flex items-center justify-between gap-2">
                <h4 class="text-sm font-bold">Diferencias abiertas</h4>
                <button
                    type="button"
                    class="text-xs font-semibold text-cyan-700 hover:underline dark:text-cyan-300"
                    @click="$emit('go', 'validacion')"
                >
                    Abrir validación
                </button>
            </div>
            <p
                v-if="!dataset.validation"
                class="mt-2 text-xs text-muted-foreground"
            >
                Esta versión no trae el detalle de validación.
            </p>
            <p
                v-else-if="!open.length"
                class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
            >
                <CheckCircle2 class="size-4" /> Sin diferencias abiertas.
            </p>
            <ul v-else class="mt-2 space-y-1.5">
                <li
                    v-for="u in open"
                    :key="u.key"
                    class="flex items-start gap-2 text-xs"
                >
                    <XCircle
                        class="mt-0.5 size-4 shrink-0"
                        :class="
                            u.blocking === false
                                ? 'text-amber-500'
                                : 'text-rose-600'
                        "
                    />
                    <span class="min-w-0 flex-1">
                        {{ u.label }}
                        <span class="block text-muted-foreground">
                            {{
                                (u.open_count ?? u.count).toLocaleString(
                                    'es-MX',
                                )
                            }}
                            abiertas · {{ mxn(u.open_amount ?? u.amount) }} ·
                            {{
                                u.blocking === false
                                    ? 'informativa'
                                    : 'bloquea la aprobación'
                            }}
                        </span>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
