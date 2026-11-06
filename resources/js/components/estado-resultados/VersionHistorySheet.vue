<script setup lang="ts">
import {
    Eye,
    FileClock,
    Loader2,
    Lock,
    LockOpen,
    Stamp,
} from 'lucide-vue-next';
import { computed } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { ReportMeta, VersionHistory } from './types';

const props = defineProps<{
    open: boolean;
    history: VersionHistory | null;
    loading: boolean;
    /** Versión que se está mostrando (null = la vigente / en vivo). */
    viewing: number | null;
    report: ReportMeta | null;
}>();

const emit = defineEmits<{
    'update:open': [v: boolean];
    view: [version: number | null];
}>();

const ACTION_ICON: Record<string, unknown> = {
    aprobado: Stamp,
    nueva_version: LockOpen,
    sustituido: FileClock,
};

const draft = computed(() => props.history?.draft_version ?? null);
</script>

<template>
    <Sheet :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
            <SheetHeader>
                <SheetTitle>Versiones del reporte</SheetTitle>
                <SheetDescription>
                    Cada aprobación congela las cifras. Elige una versión para
                    consultarla tal como se aprobó (solo lectura).
                </SheetDescription>
            </SheetHeader>

            <div class="space-y-6 px-4 pb-8">
                <Loader2
                    v-if="loading && !history"
                    class="mx-auto my-10 size-6 animate-spin text-emerald-600"
                />

                <template v-else-if="history">
                    <!-- Versión en preparación -->
                    <button
                        v-if="draft"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl border-2 border-dashed p-3 text-left transition hover:border-cyan-400"
                        :class="
                            viewing === null
                                ? 'border-cyan-500 bg-cyan-50/60 dark:bg-cyan-950/30'
                                : ''
                        "
                        @click="emit('view', null)"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-lg bg-muted text-sm font-extrabold tabular-nums"
                            >v{{ draft }}</span
                        >
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold"
                                >Versión {{ draft }} · en preparación</span
                            >
                            <span class="block text-xs text-muted-foreground"
                                >Cifras en vivo
                                <template v-if="report && viewing === null">
                                    · {{ report.status_label }}</template
                                ></span
                            >
                        </span>
                        <span
                            v-if="viewing === null"
                            class="text-xs font-bold text-cyan-700 dark:text-cyan-300"
                            >Viendo</span
                        >
                    </button>

                    <!-- Versiones aprobadas -->
                    <ol
                        v-if="history.versions.length"
                        class="relative space-y-3 border-l-2 border-border pl-5"
                    >
                        <li
                            v-for="v in history.versions"
                            :key="v.id"
                            class="relative"
                        >
                            <span
                                class="absolute top-3 -left-[27px] size-3 rounded-full ring-4 ring-card"
                                :class="
                                    v.status === 'aprobado'
                                        ? 'bg-emerald-500'
                                        : 'bg-slate-400'
                                "
                            />
                            <div
                                class="rounded-xl border p-3 transition"
                                :class="
                                    viewing === v.version ||
                                    (viewing === null && v.locked)
                                        ? 'border-cyan-500 bg-cyan-50/50 dark:bg-cyan-950/20'
                                        : ''
                                "
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="flex items-center gap-1.5 text-sm font-bold"
                                        >
                                            <Lock
                                                v-if="v.locked"
                                                class="size-3.5 text-emerald-600"
                                            />
                                            Versión {{ v.version }}
                                            <span
                                                class="rounded-full px-1.5 py-px text-[10px] font-bold"
                                                :class="
                                                    v.status === 'aprobado'
                                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                        : 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                                "
                                                >{{ v.status_label }}</span
                                            >
                                        </p>
                                        <p
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            Aprobó {{ v.approved_by ?? '—' }} ·
                                            {{ v.approved_at ?? '—' }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-lg border px-2 py-1 text-xs font-semibold transition hover:border-cyan-400 hover:text-cyan-800 disabled:opacity-50 dark:hover:text-cyan-300"
                                        :disabled="viewing === v.version"
                                        :aria-label="`Consultar la versión ${v.version}`"
                                        @click="
                                            emit(
                                                'view',
                                                v.locked ? null : v.version,
                                            )
                                        "
                                    >
                                        <Eye class="size-3.5" />
                                        {{
                                            viewing === v.version
                                                ? 'Viendo'
                                                : 'Consultar'
                                        }}
                                    </button>
                                </div>
                                <p
                                    v-if="v.approval_notes"
                                    class="mt-2 text-xs leading-relaxed"
                                >
                                    «{{ v.approval_notes }}»
                                </p>
                                <p
                                    v-if="v.reopened"
                                    class="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                                >
                                    Reabierta por {{ v.reopened.by ?? '—' }} el
                                    {{ v.reopened.at }}: «{{
                                        v.reopened.reason
                                    }}»
                                </p>
                                <p
                                    v-if="v.superseded_at"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Sustituida el {{ v.superseded_at }}
                                </p>
                                <p
                                    class="mt-1 truncate text-[11px] text-muted-foreground tabular-nums"
                                    :title="v.snapshot_hash ?? ''"
                                >
                                    Sello
                                    {{ (v.snapshot_hash ?? '—').slice(0, 16) }}
                                    · reglas {{ v.rules_version ?? '—' }} ·
                                    {{ v.scopes.length }} alcance(s)
                                </p>
                            </div>
                        </li>
                    </ol>
                    <p
                        v-else
                        class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
                    >
                        Este periodo todavía no tiene versiones aprobadas.
                    </p>

                    <!-- Bitácora -->
                    <section v-if="history.logs.length">
                        <h3 class="mb-2 text-sm font-extrabold">Bitácora</h3>
                        <ul class="space-y-2">
                            <li
                                v-for="l in history.logs"
                                :key="l.id"
                                class="flex gap-2.5 text-xs"
                            >
                                <component
                                    :is="ACTION_ICON[l.action] ?? FileClock"
                                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0">
                                    <span class="font-semibold"
                                        >{{ l.action_label }} · v{{
                                            l.version
                                        }}</span
                                    >
                                    <span class="block text-muted-foreground"
                                        >{{ l.user ?? '—' }} · {{ l.at }}</span
                                    >
                                    <span v-if="l.reason" class="block">{{
                                        l.reason
                                    }}</span>
                                </span>
                            </li>
                        </ul>
                    </section>
                </template>
            </div>
        </SheetContent>
    </Sheet>
</template>
