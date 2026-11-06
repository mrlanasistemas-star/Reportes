<script setup lang="ts">
import { ArrowRight, Check } from 'lucide-vue-next';
import type { CloseStep } from '@/composables/estado-resultados/useCloseRoute';

defineProps<{ steps: CloseStep[]; doneCount: number }>();
defineEmits<{ go: [step: CloseStep] }>();
</script>

<template>
    <nav
        aria-label="Ruta de cierre del periodo"
        class="rounded-2xl border bg-card px-3 py-3 shadow-soft-xs sm:px-4"
    >
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-sm font-bold">
                Ruta de cierre
                <span class="font-medium text-muted-foreground"
                    >· {{ doneCount }} de {{ steps.length }} pasos listos</span
                >
            </p>
            <p class="text-xs text-muted-foreground">
                Cuando los cinco pasos estén listos, las cifras dejan de ser
                preliminares.
            </p>
        </div>
        <ol class="grid gap-2 md:grid-cols-5 md:gap-0">
            <li
                v-for="(s, i) in steps"
                :key="s.key"
                class="relative md:px-1.5 md:first:pl-0 md:last:pr-0"
            >
                <!-- conector entre pasos -->
                <span
                    v-if="i < steps.length - 1"
                    aria-hidden="true"
                    class="absolute top-[22px] left-[calc(50%+22px)] hidden h-0.5 w-[calc(100%-44px)] md:block"
                    :class="
                        s.done
                            ? 'bg-emerald-500/70'
                            : 'bg-[repeating-linear-gradient(90deg,var(--color-border)_0_6px,transparent_6px_10px)]'
                    "
                />
                <button
                    type="button"
                    class="group relative flex w-full items-center gap-3 rounded-xl p-2 text-left transition hover:bg-muted/60 focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none md:flex-col md:gap-2 md:text-center"
                    :aria-current="s.state === 'current' ? 'step' : undefined"
                    :aria-label="`Paso ${i + 1}: ${s.title}. ${s.detail}. ${s.go.label}`"
                    @click="$emit('go', s)"
                >
                    <span
                        class="relative z-10 flex size-9 shrink-0 items-center justify-center rounded-full border-2 text-sm font-extrabold tabular-nums transition group-hover:scale-105"
                        :class="
                            s.done
                                ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-400 dark:bg-emerald-400 dark:text-emerald-950'
                                : s.state === 'current'
                                  ? 'border-cyan-500 bg-card text-cyan-700 shadow-[0_0_0_4px_rgb(34_197_214/0.18)] dark:text-cyan-300'
                                  : 'border-border bg-card text-muted-foreground'
                        "
                    >
                        <Check
                            v-if="s.done"
                            class="size-4.5"
                            stroke-width="3"
                        />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="min-w-0 flex-1 md:w-full">
                        <span
                            class="block text-[13px] leading-tight font-bold"
                            :class="
                                s.state === 'todo'
                                    ? 'text-muted-foreground'
                                    : ''
                            "
                            >{{ s.title }}</span
                        >
                        <span
                            class="mt-0.5 block truncate text-xs"
                            :class="
                                s.done
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : s.state === 'current'
                                      ? 'font-semibold text-amber-700 dark:text-amber-400'
                                      : 'text-muted-foreground'
                            "
                            >{{ s.detail }}</span
                        >
                        <span
                            v-if="!s.done"
                            class="mx-auto mt-1.5 block h-1 w-full max-w-36 overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full bg-amber-500 transition-[width] duration-700"
                                :style="{
                                    width: `${Math.round(s.progress * 100)}%`,
                                }"
                            />
                        </span>
                        <span
                            v-if="s.state === 'current'"
                            class="mt-1.5 inline-flex items-center gap-1 text-xs font-bold text-cyan-700 group-hover:underline dark:text-cyan-300"
                        >
                            {{ s.go.label }}
                            <ArrowRight
                                class="size-3.5 transition group-hover:translate-x-0.5"
                            />
                        </span>
                    </span>
                </button>
            </li>
        </ol>
    </nav>
</template>
