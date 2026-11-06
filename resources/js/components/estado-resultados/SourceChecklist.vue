<script setup lang="ts">
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    CircleDashed,
    ExternalLink,
    Play,
    UploadCloud,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { cargaUrl } from './sourceLinks';
import type { ChecklistItem, SourceChecklist } from './types';

const props = defineProps<{
    checklist: SourceChecklist;
    periodId: number | null;
    periodLabel?: string | null;
    canUpload: boolean;
    canProcess?: boolean;
    lockReason?: string | null;
}>();

const emit = defineEmits<{
    upload: [slot: string];
    tab: [tab: string];
    process: [];
}>();

/** Primero lo que falta o tiene problemas; las listas al final. */
const ordered = computed(() => {
    const rank = { falta: 0, con_problemas: 1, lista: 2 } as const;

    return [...props.checklist.items].sort(
        (a, b) =>
            rank[a.state] - rank[b.state] ||
            Number(b.required) - Number(a.required),
    );
});

const s = computed(() => props.checklist.summary);

const TONE: Record<string, { row: string; badge: string; icon: string }> = {
    falta: {
        row: 'border-l-rose-500',
        badge: 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300',
        icon: 'text-rose-600 dark:text-rose-400',
    },
    con_problemas: {
        row: 'border-l-amber-500',
        badge: 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-300',
        icon: 'text-amber-600 dark:text-amber-400',
    },
    lista: {
        row: 'border-l-emerald-500',
        badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
        icon: 'text-emerald-600 dark:text-emerald-400',
    },
};

const iconFor = (i: ChecklistItem) =>
    i.state === 'lista'
        ? CheckCircle2
        : i.state === 'falta'
          ? CircleDashed
          : AlertTriangle;

function canAct(i: ChecklistItem): boolean {
    if (i.action.kind === 'upload') {
        return props.canUpload && !props.lockReason;
    }

    if (i.action.kind === 'process') {
        return !!props.canProcess && !props.lockReason;
    }

    return true;
}

function act(i: ChecklistItem): void {
    if (i.action.kind === 'upload' && i.action.slot) {
        emit('upload', i.action.slot);
    } else if (i.action.kind === 'tab' && i.action.tab) {
        emit('tab', i.action.tab);
    } else if (i.action.kind === 'process') {
        emit('process');
    }
}
</script>

<template>
    <section
        class="rounded-2xl border bg-card shadow-soft-xs"
        aria-labelledby="er-checklist-title"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4"
        >
            <div class="min-w-0">
                <h3 id="er-checklist-title" class="text-base font-extrabold">
                    Qué necesita {{ periodLabel ?? 'este periodo' }}
                </h3>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Cada fuente dice si está lista, qué falta exactamente y
                    dónde se resuelve. Las que se suben aquí tienen su botón;
                    las de Carga de archivos te llevan directo a ese módulo.
                </p>
            </div>
            <div class="flex flex-wrap gap-1.5 text-xs font-bold">
                <span
                    class="rounded-full bg-emerald-100 px-2.5 py-1 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                    >{{ s.lista }} de {{ s.total }} listas</span
                >
                <span
                    v-if="s.falta"
                    class="rounded-full bg-rose-100 px-2.5 py-1 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300"
                    >{{ s.falta }} faltan</span
                >
                <span
                    v-if="s.con_problemas"
                    class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-900 dark:bg-amber-950/60 dark:text-amber-300"
                    >{{ s.con_problemas }} con problemas</span
                >
            </div>
        </header>

        <ul class="divide-y">
            <li
                v-for="i in ordered"
                :key="i.key"
                class="flex flex-col gap-3 border-l-4 px-5 py-4 md:flex-row md:items-start"
                :class="TONE[i.state].row"
            >
                <component
                    :is="iconFor(i)"
                    class="mt-0.5 size-5 shrink-0"
                    :class="TONE[i.state].icon"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-bold">{{ i.label }}</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-bold"
                            :class="TONE[i.state].badge"
                            >{{ i.state_label }}</span
                        >
                        <span
                            class="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground"
                            >{{ i.where_label }}</span
                        >
                    </p>
                    <p class="text-sm">{{ i.message }}</p>
                    <p
                        v-if="i.fix && i.state !== 'lista'"
                        class="text-sm font-semibold"
                    >
                        Qué hacer: {{ i.fix }}
                    </p>
                    <ul
                        v-if="
                            i.diagnosis?.uploads?.length && i.state !== 'lista'
                        "
                        class="space-y-0.5 text-xs text-muted-foreground"
                    >
                        <li v-for="u in i.diagnosis.uploads" :key="u.id">
                            Archivo «{{ u.name }}» · {{ u.status_label }} ·
                            {{ u.rows }} registro(s){{
                                u.uploaded_at
                                    ? ` · subido ${u.uploaded_at}`
                                    : ''
                            }}
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Por qué importa: {{ i.affects }}
                    </p>
                </div>
                <div class="shrink-0 md:pt-0.5">
                    <Button
                        v-if="i.action.kind === 'carga'"
                        as="a"
                        :href="cargaUrl(periodId)"
                        :variant="i.state === 'lista' ? 'ghost' : 'default'"
                        size="sm"
                        class="w-full md:w-auto"
                    >
                        {{ i.action.label }}
                        <ExternalLink class="size-3.5" />
                    </Button>
                    <Button
                        v-else-if="
                            i.state !== 'lista' || i.action.kind === 'upload'
                        "
                        type="button"
                        :variant="i.state === 'lista' ? 'ghost' : 'default'"
                        size="sm"
                        class="w-full md:w-auto"
                        :disabled="!canAct(i)"
                        :title="
                            !canAct(i)
                                ? (lockReason ??
                                  'No tienes permiso para esta acción.')
                                : undefined
                        "
                        @click="act(i)"
                    >
                        <UploadCloud
                            v-if="i.action.kind === 'upload'"
                            class="size-3.5"
                        />
                        <Play
                            v-else-if="i.action.kind === 'process'"
                            class="size-3.5"
                        />
                        {{ i.action.label }}
                        <ArrowRight
                            v-if="i.action.kind === 'tab'"
                            class="size-3.5"
                        />
                    </Button>
                </div>
            </li>
        </ul>
    </section>
</template>
