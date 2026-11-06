<script setup lang="ts">
import { CheckCircle2, Download, Fingerprint, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import StatusBadge from './StatusBadge.vue';
import { mxn } from './types';
import type { Dataset } from './types';

const props = defineProps<{ dataset: Dataset }>();

const okCount = computed(() => props.dataset.checks.filter((c) => c.ok).length);
const proc = computed(() => props.dataset.sources.procesamiento);
</script>

<template>
    <div class="space-y-4">
        <div
            class="rounded-2xl border bg-gradient-to-br from-teal-700 to-cyan-600 p-4 text-white shadow-soft-xs"
        >
            <p
                class="flex items-center gap-1.5 text-xs font-bold tracking-wide uppercase opacity-90"
            >
                <Fingerprint class="size-4" /> Hash del dataset
            </p>
            <p class="mt-1 font-mono text-xs break-all">
                {{ dataset.meta.dataset_hash }}
            </p>
            <p class="mt-2 text-xs opacity-85">
                El mismo hash aparece en el Excel (hoja FUENTES Y CONTROL) y en
                el PDF: web = Excel = PDF. Reglas
                {{ dataset.meta.rules_version }} · tolerancia de fechas
                {{ dataset.meta.tolerance_days }} día(s) · generado
                {{ dataset.meta.generated_at }}.
            </p>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs">
            <div class="flex items-center justify-between border-b px-4 py-2.5">
                <p class="text-sm font-bold">Validaciones matemáticas</p>
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-bold"
                    :class="
                        okCount === dataset.checks.length
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
                            : 'bg-rose-100 text-rose-800'
                    "
                    >{{ okCount }} / {{ dataset.checks.length }} en $0.00</span
                >
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-xs">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-1.5">Validación</th>
                            <th class="px-3 py-1.5 text-right">Esperado</th>
                            <th class="px-3 py-1.5 text-right">Calculado</th>
                            <th class="px-3 py-1.5 text-right">Diferencia</th>
                            <th class="px-3 py-1.5 text-center">OK</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in dataset.checks"
                            :key="c.key"
                            class="border-t"
                            :class="
                                c.ok ? '' : 'bg-rose-50/60 dark:bg-rose-950/20'
                            "
                        >
                            <td class="px-3 py-1.5">
                                {{ c.label }}
                                <span
                                    v-if="!c.ok && c.explain"
                                    class="block text-[10.5px] text-rose-700"
                                    >{{ c.explain }}</span
                                >
                            </td>
                            <td class="px-3 py-1.5 text-right tabular-nums">
                                {{ mxn(c.expected) }}
                            </td>
                            <td class="px-3 py-1.5 text-right tabular-nums">
                                {{ mxn(c.actual) }}
                            </td>
                            <td
                                class="px-3 py-1.5 text-right font-semibold tabular-nums"
                                :class="
                                    c.ok
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-rose-600'
                                "
                            >
                                {{ mxn(c.difference) }}
                            </td>
                            <td class="px-3 py-1.5 text-center">
                                <CheckCircle2
                                    v-if="c.ok"
                                    class="mx-auto size-4 text-emerald-600"
                                />
                                <XCircle
                                    v-else
                                    class="mx-auto size-4 text-rose-600"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <div class="rounded-2xl border bg-card p-4 shadow-soft-xs">
                <p class="mb-2 text-sm font-bold">Procesamiento</p>
                <StatusBadge :status="proc.status" />
                <p
                    v-if="proc.last_run"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    Último: {{ proc.last_run.at ?? '—' }} por
                    {{ proc.last_run.user ?? '—' }} · reglas
                    {{ proc.last_run.rules_version }} ·
                    {{ proc.last_run.status }}
                    <span
                        v-if="proc.last_run.error"
                        class="block text-rose-600"
                        >{{ proc.last_run.error }}</span
                    >
                </p>
                <p v-else class="mt-2 text-xs text-muted-foreground">
                    Aún no se procesa la conciliación del periodo.
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4 shadow-soft-xs">
                <p class="mb-2 flex items-center gap-1.5 text-sm font-bold">
                    <Download class="size-4" /> Exportaciones
                </p>
                <p
                    v-if="!dataset.sources.exportaciones.length"
                    class="text-xs text-muted-foreground"
                >
                    Sin exportaciones registradas.
                </p>
                <ul class="space-y-1 text-xs">
                    <li
                        v-for="(e, i) in dataset.sources.exportaciones"
                        :key="i"
                        class="flex justify-between gap-2"
                    >
                        <span
                            ><b class="uppercase">{{ e.format }}</b> ·
                            {{ e.scope }} · {{ e.user ?? '—' }}</span
                        >
                        <span class="font-mono text-muted-foreground"
                            >{{ e.at }} · {{ e.hash }}</span
                        >
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
