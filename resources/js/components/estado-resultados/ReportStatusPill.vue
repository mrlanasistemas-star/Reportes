<script setup lang="ts">
import { BadgeCheck, CircleDashed, ShieldAlert } from 'lucide-vue-next';
import { computed, inject } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { ReportMeta } from './types';

const props = defineProps<{
    report: ReportMeta | null;
    complete: boolean;
    /** Motivos por los que las cifras aún pueden cambiar. */
    blockers: string[];
}>();

/** Preliminar o definitivo: lo decide el backend (meta.report.preliminary). */
const view = computed(() => {
    const r = props.report;
    // Una versión aprobada o sustituida está congelada: nunca se presenta como preliminar.
    const preliminary =
        r?.source === 'snapshot' ? false : r ? r.preliminary : !props.complete;

    if (preliminary) {
        return {
            tone: 'amber',
            label: 'Preliminar',
            sub: `${props.blockers.length} motivo(s)`,
            icon: ShieldAlert,
        };
    }

    if (r?.source === 'snapshot') {
        return {
            tone: r.status === 'aprobado' ? 'emerald' : 'slate',
            label:
                r.status === 'aprobado'
                    ? 'Cifras aprobadas'
                    : 'Versión sustituida',
            sub: `v${r.version}`,
            icon: r.status === 'aprobado' ? BadgeCheck : CircleDashed,
        };
    }

    return {
        tone: 'emerald',
        label: 'Cifras completas',
        sub: null,
        icon: BadgeCheck,
    };
});

/** Acceso a Fuentes provisto por la página (sin pasar por la barra de comandos). */
const goFuentes = inject<(() => void) | null>('er:goFuentes', null);

const TONES: Record<string, string> = {
    emerald:
        'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
    amber: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
    cyan: 'border-cyan-300 bg-cyan-50 text-cyan-900 dark:border-cyan-800 dark:bg-cyan-950/50 dark:text-cyan-300',
    slate: 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
};
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="group inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-bold transition hover:shadow-sm focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none"
                :class="TONES[view.tone]"
                :aria-label="`Estado del reporte: ${view.label}. Ver detalle`"
            >
                <span class="relative flex size-2">
                    <span
                        v-if="view.tone === 'amber'"
                        class="absolute inline-flex size-full animate-ping rounded-full bg-amber-400 opacity-60 motion-reduce:hidden"
                    />
                    <span
                        class="relative inline-flex size-2 rounded-full"
                        :class="
                            view.tone === 'amber'
                                ? 'bg-amber-500'
                                : view.tone === 'emerald'
                                  ? 'bg-emerald-500'
                                  : 'bg-current'
                        "
                    />
                </span>
                {{ view.label }}
                <span v-if="view.sub" class="font-semibold opacity-75">{{
                    view.sub
                }}</span>
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-96 rounded-2xl p-0" align="center">
            <div class="border-b px-4 py-3">
                <p class="flex items-center gap-2 text-sm font-bold">
                    <component :is="view.icon" class="size-4" />
                    {{
                        report?.source === 'snapshot'
                            ? report.status === 'aprobado'
                                ? 'Cifras aprobadas: ya no cambian'
                                : 'Versión sustituida por una posterior'
                            : complete
                              ? 'Todas las fuentes y revisiones están completas'
                              : 'Por qué las cifras aún pueden cambiar'
                    }}
                </p>
                <p
                    v-if="report?.approved_by"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Aprobado por {{ report.approved_by }} ·
                    {{ report.approved_at }}
                </p>
            </div>
            <ul
                v-if="blockers.length"
                class="max-h-72 space-y-2 overflow-y-auto px-4 py-3 text-xs leading-relaxed"
            >
                <li v-for="(b, i) in blockers" :key="i" class="flex gap-2">
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full bg-amber-500"
                    />
                    <span>{{ b }}</span>
                </li>
            </ul>
            <p
                v-else-if="report?.source === 'snapshot'"
                class="px-4 py-3 text-xs text-muted-foreground"
            >
                Se muestra tal como quedó al aprobarse. Para corregir algo hay
                que abrir una versión nueva.
            </p>
            <p v-else class="px-4 py-3 text-xs text-muted-foreground">
                Fuentes cargadas, conciliación procesada, sin pendientes,
                ajustes capturados y validaciones en $0.00.
            </p>
            <div
                v-if="
                    goFuentes &&
                    blockers.length &&
                    report?.source !== 'snapshot'
                "
                class="border-t px-4 py-2.5"
            >
                <button
                    type="button"
                    class="text-xs font-bold text-emerald-700 hover:underline focus-visible:ring-2 focus-visible:ring-emerald-500/40 focus-visible:outline-none dark:text-emerald-400"
                    @click="goFuentes()"
                >
                    Ver qué falta y cómo resolverlo →
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
