<script setup lang="ts">
import { AlertOctagon, AlertTriangle, Info } from 'lucide-vue-next';
import { computed } from 'vue';
import { FAILURE_KIND_LABELS, formatNumber } from './format';
import type { Issue } from './types';

const props = withDefaults(
    defineProps<{ issues: Issue[]; limit?: number | null }>(),
    { limit: null },
);

const visible = computed(() =>
    props.limit ? props.issues.slice(0, props.limit) : props.issues,
);

const severityMeta = {
    critical: {
        icon: AlertOctagon,
        label: 'Crítica',
        tone: 'border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/30',
        iconTone: 'text-red-600 dark:text-red-400',
    },
    warning: {
        icon: AlertTriangle,
        label: 'Advertencia',
        tone: 'border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/30',
        iconTone: 'text-amber-600 dark:text-amber-400',
    },
    info: {
        icon: Info,
        label: 'Informativa',
        tone: 'border-border bg-muted/40',
        iconTone: 'text-muted-foreground',
    },
} as const;

function meta(issue: Issue) {
    return severityMeta[issue.severity] ?? severityMeta.info;
}

function facts(issue: Issue): Array<[string, string]> {
    const items: Array<[string, string]> = [];

    if (issue.file) {
        items.push(['Archivo', issue.file]);
    }

    if (issue.period) {
        items.push(['Periodo', issue.period]);
    }

    if (issue.sheet) {
        items.push(['Hoja', issue.sheet]);
    }

    if (issue.original_header) {
        items.push(['Encabezado original', issue.original_header]);
    }

    if (issue.expected_column) {
        items.push(['Columna esperada', issue.expected_column]);
    }

    if (issue.affected_rows) {
        items.push([
            'Filas afectadas',
            Array.isArray(issue.affected_rows)
                ? issue.affected_rows.join(', ')
                : String(issue.affected_rows),
        ]);
    }

    if (issue.record_count !== null && issue.record_count !== undefined) {
        items.push(['Registros', formatNumber(issue.record_count)]);
    }

    return items;
}
</script>

<template>
    <ul class="space-y-2">
        <li
            v-for="(issue, idx) in visible"
            :key="`${issue.code}-${idx}`"
            class="rounded-lg border p-3 text-sm"
            :class="meta(issue).tone"
        >
            <div class="flex items-start gap-2.5">
                <component
                    :is="meta(issue).icon"
                    class="mt-0.5 size-4 shrink-0"
                    :class="meta(issue).iconTone"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1 space-y-1.5">
                    <div
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                    >
                        <span class="font-medium">{{ meta(issue).label }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{
                            FAILURE_KIND_LABELS[issue.category] ??
                            issue.category
                        }}</span>
                        <span aria-hidden="true">·</span>
                        <code class="font-mono text-[11px]">{{
                            issue.code
                        }}</code>
                    </div>
                    <p class="leading-snug text-foreground">
                        {{ issue.message }}
                    </p>
                    <dl
                        v-if="facts(issue).length"
                        class="grid grid-cols-1 gap-x-4 gap-y-0.5 text-xs sm:grid-cols-2"
                    >
                        <div
                            v-for="[label, value] in facts(issue)"
                            :key="label"
                            class="flex min-w-0 gap-1"
                        >
                            <dt class="shrink-0 text-muted-foreground">
                                {{ label }}:
                            </dt>
                            <dd class="truncate font-medium" :title="value">
                                {{ value }}
                            </dd>
                        </div>
                    </dl>
                    <p class="text-xs">
                        <span class="font-medium">Acción recomendada:</span>
                        {{ issue.action }}
                    </p>
                </div>
            </div>
        </li>
    </ul>
</template>
