<script setup lang="ts">
import { ChevronDown, Info } from 'lucide-vue-next';
import { reactive } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { isNegative, mxn, pct } from './types';
import type { StatementBlock } from './types';

defineProps<{ blocks: StatementBlock[] }>();

const collapsed = reactive<Record<string, boolean>>({});

const RESULT_BG: Record<number, string> = {
    1: 'from-teal-700 to-cyan-500',
    2: 'from-cyan-700 to-sky-500',
    3: 'from-teal-900 to-teal-600',
    4: 'from-slate-700 to-slate-500',
    5: 'from-slate-700 to-slate-500',
    6: 'from-emerald-800 via-emerald-600 to-cyan-500',
};
</script>

<template>
    <div class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs">
        <div
            class="grid grid-cols-[1fr_auto_auto] gap-x-4 border-b bg-teal-700 px-4 py-2 text-[11px] font-bold tracking-wider text-white uppercase"
        >
            <span>Concepto</span>
            <span class="w-36 text-right">Importe (MXN)</span>
            <span class="hidden w-20 text-right sm:block">% ingresos</span>
        </div>

        <template v-for="b in blocks" :key="b.key">
            <!-- Sección -->
            <div v-if="b.type === 'section'" class="border-b last:border-b-0">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 bg-emerald-50/70 px-4 py-2 text-left text-xs font-extrabold tracking-wide text-teal-800 hover:bg-emerald-50 dark:bg-emerald-950/20 dark:text-teal-300"
                    @click="collapsed[b.key] = !collapsed[b.key]"
                >
                    <span
                        class="rounded-md bg-teal-700 px-1.5 py-0.5 text-[10px] text-white"
                        >{{ b.number }}</span
                    >
                    {{ b.title }}
                    <ChevronDown
                        class="ml-auto size-4 transition"
                        :class="collapsed[b.key] ? '-rotate-90' : ''"
                    />
                </button>
                <div v-if="!b.applies">
                    <p class="px-4 py-2 text-xs text-muted-foreground italic">
                        {{ b.note }}
                    </p>
                </div>
                <template v-else>
                    <div
                        v-show="!collapsed[b.key]"
                        class="divide-y divide-dashed"
                    >
                        <div
                            v-for="l in b.lines"
                            :key="l.key"
                            class="grid grid-cols-[1fr_auto_auto] items-center gap-x-4 px-4 py-1.5 text-sm hover:bg-muted/30"
                        >
                            <span
                                class="flex min-w-0 items-center gap-1.5 pl-3"
                            >
                                <span
                                    class="truncate"
                                    :class="
                                        l.state === 'sin_informacion'
                                            ? 'text-muted-foreground italic'
                                            : ''
                                    "
                                    >{{ l.label }}</span
                                >
                                <span
                                    v-if="l.state === 'sin_informacion'"
                                    class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-1.5 text-[10px] font-bold text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                                    >Sin información</span
                                >
                                <Tooltip v-if="l.hint || l.source">
                                    <TooltipTrigger as-child>
                                        <Info
                                            class="size-3.5 shrink-0 cursor-help text-muted-foreground/70"
                                        />
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-xs">
                                        <p class="font-semibold">
                                            {{ l.source }}
                                        </p>
                                        <p v-if="l.hint">
                                            {{ l.hint }}
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </span>
                            <span
                                class="w-36 text-right tabular-nums"
                                :class="
                                    l.state === 'sin_informacion'
                                        ? 'text-muted-foreground'
                                        : ''
                                "
                                >{{
                                    l.state === 'sin_informacion'
                                        ? '—'
                                        : mxn(l.amount)
                                }}</span
                            >
                            <span
                                class="hidden w-20 text-right text-xs text-muted-foreground tabular-nums sm:block"
                                >{{ pct(l.pct) }}</span
                            >
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-[1fr_auto_auto] items-center gap-x-4 border-t-2 border-teal-700/30 bg-muted/40 px-4 py-2 text-sm font-bold"
                    >
                        <span>{{ b.total.label }}</span>
                        <span class="w-36 text-right tabular-nums">{{
                            mxn(b.total.amount)
                        }}</span>
                        <span
                            class="hidden w-20 text-right text-xs tabular-nums sm:block"
                            >{{ pct(b.total.pct) }}</span
                        >
                    </div>
                    <div
                        v-if="b.subtotals"
                        class="px-4 py-1.5 text-xs text-muted-foreground"
                    >
                        <span
                            v-for="s in b.subtotals"
                            :key="s.label"
                            class="mr-4"
                            >{{ s.label }}:
                            <b class="tabular-nums">{{
                                mxn(s.amount)
                            }}</b></span
                        >
                    </div>
                </template>
            </div>

            <!-- Resultado (EBITDA / EBIT / EBT / Utilidad) -->
            <div
                v-else
                class="grid grid-cols-[1fr_auto_auto] items-center gap-x-4 bg-gradient-to-r px-4 py-3 text-white"
                :class="RESULT_BG[b.level] ?? RESULT_BG[4]"
            >
                <div class="min-w-0">
                    <p
                        class="flex flex-wrap items-center gap-2 text-sm font-extrabold tracking-wide"
                    >
                        = {{ b.label }}
                        <Tooltip v-if="!b.complete">
                            <TooltipTrigger as-child>
                                <span
                                    class="cursor-help rounded-full bg-amber-300 px-1.5 text-[10px] font-bold text-amber-950"
                                    >Preliminar</span
                                >
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">
                                Falta: {{ b.missing.join(', ') }}
                            </TooltipContent>
                        </Tooltip>
                    </p>
                    <p class="truncate text-[11px] opacity-80">
                        {{ b.formula }}
                    </p>
                </div>
                <span
                    class="w-36 rounded-lg bg-white/95 px-2 py-1 text-right text-sm font-extrabold tabular-nums"
                    :class="
                        isNegative(b.amount)
                            ? 'text-rose-600'
                            : 'text-emerald-700'
                    "
                    >{{ mxn(b.amount) }}</span
                >
                <span
                    class="hidden w-20 text-right text-xs font-bold tabular-nums sm:block"
                    >{{ pct(b.pct) }}</span
                >
            </div>
        </template>
    </div>
</template>
