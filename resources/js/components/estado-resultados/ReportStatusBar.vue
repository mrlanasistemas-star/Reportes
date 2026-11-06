<script setup lang="ts">
import {
    ArrowLeft,
    BadgeCheck,
    Check,
    ChevronDown,
    FilePenLine,
    History,
    Loader2,
    Lock,
    LockOpen,
    ShieldAlert,
    Stamp,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { BLOCKER_GROUPS } from '@/composables/estado-resultados/useErGovernance';
import type { ReportMeta } from './types';

const props = defineProps<{
    report: ReportMeta;
    canApprove: boolean;
    approving: boolean;
    reopening: boolean;
    /** Se consulta una versión anterior por ?version=N. */
    viewingHistoric: boolean;
}>();

const emit = defineEmits<{
    approve: [];
    reopen: [];
    history: [];
    backToCurrent: [];
    go: [tab: string];
}>();

const STEPS = [
    {
        key: 'borrador',
        label: 'Borrador',
        hint: 'Faltan datos o alguna validación no cuadra.',
    },
    {
        key: 'pendiente_conciliacion',
        label: 'Pendiente de conciliación',
        hint: 'Hay movimientos sin clasificar o la conciliación no está al día.',
    },
    {
        key: 'listo_revision',
        label: 'Listo para revisión',
        hint: 'Sin pendientes, todo cuadra en $0.00: se puede aprobar.',
    },
    {
        key: 'aprobado',
        label: 'Aprobado',
        hint: 'Cifras congeladas en una versión sellada.',
    },
];

const currentIndex = computed(() => {
    const s =
        props.report.status === 'sustituido' ? 'aprobado' : props.report.status;

    return Math.max(
        0,
        STEPS.findIndex((x) => x.key === s),
    );
});

const isSnapshot = computed(() => props.report.source === 'snapshot');
const superseded = computed(() => props.report.status === 'sustituido');

/** Bloqueos agrupados por tipo, en el orden en que conviene resolverlos. */
const groups = computed(() => {
    const order = [
        'procesamiento',
        'pendientes',
        'validaciones',
        'datos_faltantes',
    ];
    const map = new Map<string, string[]>();

    for (const b of props.report.blockers) {
        map.set(b.code, [...(map.get(b.code) ?? []), b.message]);
    }

    return [...map.entries()]
        .sort((a, b) => order.indexOf(a[0]) - order.indexOf(b[0]))
        .map(([code, messages]) => ({
            code,
            messages,
            meta: BLOCKER_GROUPS[code] ?? {
                title: 'Pendiente',
                action: 'Ver detalle',
                tab: 'control',
            },
        }));
});

const showBlockers = ref(true);
const shortHash = (h: string | null) => (h ? h.slice(0, 12) : '—');
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs"
        :class="
            isSnapshot
                ? superseded
                    ? 'border-slate-400 dark:border-slate-600'
                    : 'border-emerald-400 dark:border-emerald-700'
                : ''
        "
        aria-label="Estado y versión del reporte"
    >
        <!-- Banda de solo lectura -->
        <div
            v-if="isSnapshot"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm font-semibold"
            :class="
                superseded || viewingHistoric
                    ? 'bg-slate-800 text-white dark:bg-slate-700'
                    : 'bg-emerald-700 text-white dark:bg-emerald-800'
            "
            role="status"
        >
            <Lock class="size-4 shrink-0" />
            <span>
                {{
                    superseded
                        ? `Versión ${report.version} sustituida — solo lectura`
                        : `Versión ${report.version} aprobada — solo lectura`
                }}
            </span>
            <span class="text-xs font-medium opacity-85">
                {{
                    superseded
                        ? `Reemplazada el ${report.superseded_at ?? '—'}. Se conserva tal como se aprobó.`
                        : 'Las cifras están congeladas: no cambian aunque cambien las fuentes.'
                }}
            </span>
            <button
                v-if="viewingHistoric"
                type="button"
                class="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1 text-xs font-bold transition hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                @click="emit('backToCurrent')"
            >
                <ArrowLeft class="size-3.5" /> Volver a la versión vigente
            </button>
        </div>

        <div class="flex flex-col gap-4 p-4 2xl:flex-row 2xl:items-center">
            <!-- Versión -->
            <div class="flex shrink-0 items-center gap-3">
                <span
                    class="flex size-12 flex-col items-center justify-center rounded-xl border-2 leading-none"
                    :class="
                        isSnapshot && !superseded
                            ? 'border-emerald-600 text-emerald-700 dark:border-emerald-500 dark:text-emerald-300'
                            : 'border-border text-foreground'
                    "
                >
                    <span class="text-[10px] font-semibold opacity-70">v</span>
                    <span class="text-lg font-extrabold tabular-nums">{{
                        report.version
                    }}</span>
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-extrabold">
                        Versión {{ report.version }} · {{ report.status_label }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="isSnapshot">
                            Aprobada por {{ report.approved_by ?? '—' }} el
                            {{ report.approved_at ?? '—' }} · sello
                            <span
                                class="tabular-nums"
                                :title="report.snapshot_hash ?? ''"
                                >{{ shortHash(report.snapshot_hash) }}</span
                            >
                        </template>
                        <template v-else>
                            En preparación · se recalcula con cada cambio ·
                            {{ report.generated_at }}
                        </template>
                    </p>
                </div>
            </div>

            <!-- Stepper de estados -->
            <ol
                class="grid min-w-0 flex-1 grid-cols-2 gap-2 sm:grid-cols-4 sm:gap-0"
                aria-label="Avance del reporte"
            >
                <li
                    v-for="(s, i) in STEPS"
                    :key="s.key"
                    class="relative flex items-center gap-2 sm:flex-col sm:gap-1.5 sm:px-1 sm:text-center"
                    :aria-current="i === currentIndex ? 'step' : undefined"
                >
                    <span
                        v-if="i < STEPS.length - 1"
                        aria-hidden="true"
                        class="absolute top-3.5 left-[calc(50%+16px)] hidden h-0.5 w-[calc(100%-32px)] sm:block"
                        :class="
                            i < currentIndex ? 'bg-emerald-500/70' : 'bg-border'
                        "
                    />
                    <span
                        class="relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold"
                        :class="
                            i < currentIndex
                                ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-500 dark:text-emerald-950'
                                : i === currentIndex
                                  ? s.key === 'aprobado'
                                      ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-500 dark:text-emerald-950'
                                      : 'border-cyan-500 bg-card text-cyan-700 shadow-[0_0_0_4px_rgb(34_197_214/0.18)] dark:text-cyan-300'
                                  : 'border-border bg-card text-muted-foreground'
                        "
                    >
                        <Check
                            v-if="i < currentIndex"
                            class="size-3.5"
                            stroke-width="3"
                        />
                        <Stamp
                            v-else-if="
                                s.key === 'aprobado' && i === currentIndex
                            "
                            class="size-3.5"
                        />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="min-w-0">
                        <span
                            class="block text-xs leading-tight font-bold"
                            :class="
                                i > currentIndex ? 'text-muted-foreground' : ''
                            "
                            >{{ s.label }}</span
                        >
                        <span
                            v-if="i === currentIndex"
                            class="hidden text-[11px] leading-snug text-muted-foreground sm:block"
                            >{{
                                superseded && s.key === 'aprobado'
                                    ? 'Sustituida por una versión posterior.'
                                    : s.hint
                            }}</span
                        >
                    </span>
                </li>
            </ol>

            <!-- Acciones -->
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Tooltip v-if="canApprove && !isSnapshot">
                    <TooltipTrigger as-child>
                        <span tabindex="0" class="inline-flex rounded-xl">
                            <button
                                type="button"
                                class="inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-semibold transition"
                                :class="
                                    report.approvable
                                        ? 'bg-emerald-700 text-white shadow-[0_6px_16px_-8px_rgb(4_120_87/0.8)] hover:-translate-y-px hover:bg-emerald-800 dark:bg-emerald-500 dark:text-emerald-950 dark:hover:bg-emerald-400'
                                        : 'cursor-not-allowed border border-dashed text-muted-foreground'
                                "
                                :disabled="!report.approvable || approving"
                                :aria-disabled="!report.approvable"
                                @click="emit('approve')"
                            >
                                <Loader2
                                    v-if="approving"
                                    class="size-4 animate-spin"
                                />
                                <BadgeCheck v-else class="size-4" />
                                Aprobar versión {{ report.version }}
                            </button>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        {{
                            report.approvable
                                ? 'Congela las cifras de todos los alcances en una versión sellada.'
                                : `Aún no: ${report.blockers.length} punto(s) por resolver (ver abajo).`
                        }}
                    </TooltipContent>
                </Tooltip>
                <button
                    v-if="
                        canApprove &&
                        isSnapshot &&
                        report.current &&
                        report.locked
                    "
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition hover:border-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/30"
                    :disabled="reopening"
                    @click="emit('reopen')"
                >
                    <Loader2 v-if="reopening" class="size-4 animate-spin" />
                    <LockOpen v-else class="size-4" />
                    Abrir nueva versión
                </button>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl px-3 text-sm font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    @click="emit('history')"
                >
                    <History class="size-4" /> Versiones
                </button>
            </div>
        </div>

        <!-- Versión anterior reabierta -->
        <p
            v-if="!isSnapshot && report.previous_version"
            class="flex items-start gap-2 border-t bg-muted/40 px-4 py-2.5 text-xs"
        >
            <FilePenLine class="mt-0.5 size-3.5 shrink-0 text-amber-600" />
            <span>
                La versión {{ report.previous_version.version }} fue aprobada
                por {{ report.previous_version.approved_by ?? '—' }} el
                {{ report.previous_version.approved_at ?? '—' }}
                <template v-if="report.previous_version.reopened">
                    y {{ report.previous_version.reopened.by ?? '—' }} abrió
                    esta versión el {{ report.previous_version.reopened.at }}:
                    «{{ report.previous_version.reopened.reason }}».</template
                >
                Sigue siendo la oficial hasta que se apruebe la versión
                {{ report.version }}.
            </span>
        </p>
        <p
            v-if="isSnapshot && report.reopened"
            class="flex items-start gap-2 border-t bg-amber-50/70 px-4 py-2.5 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <LockOpen class="mt-0.5 size-3.5 shrink-0" />
            {{ report.reopened.by ?? '—' }} abrió una nueva versión el
            {{ report.reopened.at }}: «{{ report.reopened.reason }}».
        </p>

        <!-- Qué falta para aprobar -->
        <div v-if="!isSnapshot && groups.length" class="border-t">
            <button
                type="button"
                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm font-bold transition hover:bg-muted/40"
                :aria-expanded="showBlockers"
                @click="showBlockers = !showBlockers"
            >
                <ShieldAlert class="size-4 text-amber-600" />
                Qué falta para poder aprobar
                <span
                    class="rounded-full bg-amber-400 px-1.5 text-[10px] font-bold text-amber-950 tabular-nums"
                    >{{ report.blockers.length }}</span
                >
                <ChevronDown
                    class="ml-auto size-4 text-muted-foreground transition-transform"
                    :class="showBlockers ? 'rotate-180' : ''"
                />
            </button>
            <div
                v-show="showBlockers"
                class="grid gap-2 px-4 pb-4 md:grid-cols-2 2xl:grid-cols-4"
            >
                <div
                    v-for="g in groups"
                    :key="g.code"
                    class="flex flex-col gap-2 rounded-xl border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-900 dark:bg-amber-950/20"
                >
                    <p
                        class="text-sm font-bold text-amber-950 dark:text-amber-200"
                    >
                        {{ g.meta.title }}
                    </p>
                    <ul
                        class="space-y-1 text-xs leading-relaxed text-amber-900 dark:text-amber-200/90"
                    >
                        <li
                            v-for="(m, i) in g.messages"
                            :key="i"
                            class="flex gap-1.5"
                        >
                            <span
                                class="mt-1.5 size-1 shrink-0 rounded-full bg-current"
                            />
                            {{ m }}
                        </li>
                    </ul>
                    <button
                        type="button"
                        class="mt-auto self-start rounded-lg bg-white px-2.5 py-1 text-xs font-bold text-amber-900 shadow-sm transition hover:bg-amber-100 dark:bg-amber-900/50 dark:text-amber-100 dark:hover:bg-amber-900"
                        @click="emit('go', g.meta.tab)"
                    >
                        {{ g.meta.action }}
                    </button>
                </div>
            </div>
        </div>
        <p
            v-else-if="!isSnapshot && report.approvable"
            class="flex items-center gap-2 border-t bg-emerald-50/70 px-4 py-2.5 text-sm font-semibold text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200"
        >
            <BadgeCheck class="size-4" /> Todo cuadra y no hay pendientes: la
            versión {{ report.version }} está lista para aprobarse.
        </p>
    </section>
</template>
