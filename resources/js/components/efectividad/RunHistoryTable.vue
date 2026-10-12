<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import { Eye, FileSpreadsheet, FilterX } from 'lucide-vue-next';
import { computed, reactive } from 'vue';
import SelectField from '@/components/forms/SelectField.vue';
import AppPagination from '@/components/navigation/AppPagination.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    download as downloadRoute,
    index as efectividadIndex,
} from '@/routes/efectividad-cobranza';
import {
    formatDateTime,
    formatDuration,
    secondsSince,
    STATUS_FILTER_OPTIONS,
} from './format';
import RunStatusBadge from './RunStatusBadge.vue';
import type { HistoryFilters, RunSummary } from './types';

const props = defineProps<{
    history: {
        data: RunSummary[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    filters: HistoryFilters;
    periods: Array<{ id: number; label: string }>;
    branches: Array<{ id: number; name: string }>;
    requesters: Array<{ id: number; name: string }>;
    canFilterByUser: boolean;
    selected: { period_id: number | null; branch_id: number | null };
}>();

const emit = defineEmits<{ (e: 'view', runId: number): void }>();

const now = useNow({ interval: 1000 });

const form = reactive({
    f_period: props.filters.f_period ?? '',
    f_branch: props.filters.f_branch ?? '',
    f_status: props.filters.f_status ?? '',
    f_from: props.filters.f_from ?? '',
    f_to: props.filters.f_to ?? '',
    f_user: props.filters.f_user ?? '',
});

const periodOptions = computed(() => [
    { value: '', label: 'Todos los periodos' },
    ...props.periods.map((p) => ({ value: p.id, label: p.label })),
]);
const branchOptions = computed(() => [
    { value: '', label: 'Todas las sucursales' },
    { value: 'general', label: 'General (sin sucursal)' },
    ...props.branches.map((b) => ({ value: String(b.id), label: b.name })),
]);
const userOptions = computed(() => [
    { value: '', label: 'Todos los solicitantes' },
    ...props.requesters.map((u) => ({ value: u.id, label: u.name })),
]);

const hasFilters = computed(() =>
    Object.values(form).some((v) => v !== '' && v !== null),
);

function visit(page?: number) {
    const query: Record<string, string | number> = {};

    if (props.selected.period_id) {
        query.period_id = props.selected.period_id;
    }

    if (props.selected.branch_id) {
        query.branch_id = props.selected.branch_id;
    }

    for (const [key, value] of Object.entries(form)) {
        if (value !== '' && value !== null) {
            query[key] = value as string | number;
        }
    }

    if (page && page > 1) {
        query.page = page;
    }

    router.get(efectividadIndex().url, query, {
        preserveState: true,
        preserveScroll: true,
        only: ['history', 'filters'],
    });
}

function clearFilters() {
    Object.assign(form, {
        f_period: '',
        f_branch: '',
        f_status: '',
        f_from: '',
        f_to: '',
        f_user: '',
    });
    visit();
}

function duration(run: RunSummary): string {
    if (run.is_active) {
        return run.started_at
            ? formatDuration(secondsSince(run.started_at, now.value.getTime()))
            : 'En espera';
    }

    return formatDuration(run.duration_seconds);
}
</script>

<template>
    <Card class="gap-4">
        <CardHeader
            class="flex flex-row flex-wrap items-center justify-between gap-2"
        >
            <CardTitle class="text-base">
                Historial de generaciones
                <span class="font-normal text-muted-foreground"
                    >({{ history.meta.total }})</span
                >
            </CardTitle>
            <Button
                v-if="hasFilters"
                variant="ghost"
                size="sm"
                @click="clearFilters"
            >
                <FilterX />
                Limpiar filtros
            </Button>
        </CardHeader>
        <CardContent class="space-y-4">
            <!-- Filtros -->
            <div
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                :class="canFilterByUser ? 'xl:grid-cols-6' : 'xl:grid-cols-5'"
            >
                <SelectField
                    v-model="form.f_period"
                    label="Periodo"
                    :options="periodOptions"
                    @update:model-value="visit()"
                />
                <SelectField
                    v-model="form.f_branch"
                    label="Sucursal"
                    :options="branchOptions"
                    @update:model-value="visit()"
                />
                <SelectField
                    v-model="form.f_status"
                    label="Estado"
                    :options="STATUS_FILTER_OPTIONS"
                    @update:model-value="visit()"
                />
                <div class="space-y-2">
                    <Label for="hist-from">Desde</Label>
                    <Input
                        id="hist-from"
                        v-model="form.f_from"
                        type="date"
                        @change="visit()"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="hist-to">Hasta</Label>
                    <Input
                        id="hist-to"
                        v-model="form.f_to"
                        type="date"
                        @change="visit()"
                    />
                </div>
                <SelectField
                    v-if="canFilterByUser"
                    v-model="form.f_user"
                    label="Solicitante"
                    :options="userOptions"
                    @update:model-value="visit()"
                />
            </div>

            <p
                v-if="!history.data.length"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                {{
                    hasFilters
                        ? 'No hay generaciones que coincidan con los filtros.'
                        : 'Todavía no hay generaciones registradas.'
                }}
            </p>

            <template v-else>
                <!-- Escritorio: tabla -->
                <div class="hidden overflow-x-auto rounded-lg border md:block">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Reporte</th>
                                <th class="px-3 py-2 font-medium">Periodo</th>
                                <th class="px-3 py-2 font-medium">Sucursal</th>
                                <th class="px-3 py-2 font-medium">
                                    Solicitado
                                </th>
                                <th class="px-3 py-2 font-medium">Estado</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Duración
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Acción
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="run in history.data"
                                :key="run.id"
                                class="hover:bg-muted/30"
                            >
                                <td class="px-3 py-2.5">
                                    <p class="font-medium">{{ run.report }}</p>
                                    <span
                                        class="inline-block rounded border px-1.5 text-[10px] font-medium"
                                        :class="
                                            run.is_current_engine
                                                ? 'border-emerald-300 text-emerald-700 dark:border-emerald-800 dark:text-emerald-300'
                                                : 'border-amber-300 text-amber-700 dark:border-amber-800 dark:text-amber-300'
                                        "
                                        :title="run.engine_version ?? ''"
                                        >{{
                                            run.is_current_engine
                                                ? 'Motor V4'
                                                : 'Calculadora anterior'
                                        }}</span
                                    >
                                    <p class="text-xs text-muted-foreground">
                                        #{{ run.id
                                        }}<template v-if="run.requested_by">
                                            ·
                                            {{
                                                run.requested_by.name
                                            }}</template
                                        >
                                    </p>
                                </td>
                                <td class="px-3 py-2.5">
                                    {{ run.period_label ?? '—' }}
                                    <p
                                        v-if="run.mode"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ run.mode }} · corte
                                        {{
                                            run.cutoff_date
                                                ?.split('-')
                                                .reverse()
                                                .join('/')
                                        }}
                                    </p>
                                </td>
                                <td class="px-3 py-2.5">
                                    {{ run.branch_label }}
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    {{ formatDateTime(run.queued_at) }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <RunStatusBadge
                                        :state="run.display_state"
                                        :label="run.display_label"
                                    />
                                    <p
                                        v-if="
                                            run.is_active && run.current_stage
                                        "
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Paso {{ run.current_stage.position }}/{{
                                            run.stage_count
                                        }}
                                        · {{ run.current_stage.label }}
                                    </p>
                                </td>
                                <td
                                    class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ duration(run) }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            v-if="run.has_excel"
                                            as-child
                                            variant="ghost"
                                            size="icon"
                                            class="size-8"
                                            :title="`Descargar Excel #${run.id}`"
                                        >
                                            <a
                                                :href="
                                                    downloadRoute(run.id).url
                                                "
                                                :aria-label="`Descargar Excel de la ejecución ${run.id}`"
                                                ><FileSpreadsheet
                                            /></a>
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click="emit('view', run.id)"
                                        >
                                            <Eye />
                                            Ver detalle
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Móvil: tarjetas -->
                <ul class="space-y-2 md:hidden">
                    <li
                        v-for="run in history.data"
                        :key="run.id"
                        class="rounded-lg border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ run.period_label }} ·
                                    {{ run.branch_label }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    #{{ run.id }} ·
                                    {{ formatDateTime(run.queued_at) }} ·
                                    {{
                                        run.is_current_engine
                                            ? 'Motor V4'
                                            : 'Calculadora anterior'
                                    }}
                                </p>
                            </div>
                            <RunStatusBadge
                                :state="run.display_state"
                                :label="run.display_label"
                            />
                        </div>
                        <div
                            class="mt-2 flex items-center justify-between gap-2"
                        >
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >Duración: {{ duration(run) }}</span
                            >
                            <div class="flex gap-1">
                                <Button
                                    v-if="run.has_excel"
                                    as-child
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                >
                                    <a
                                        :href="downloadRoute(run.id).url"
                                        :aria-label="`Descargar Excel de la ejecución ${run.id}`"
                                        ><FileSpreadsheet
                                    /></a>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="emit('view', run.id)"
                                    >Ver detalle</Button
                                >
                            </div>
                        </div>
                    </li>
                </ul>

                <AppPagination
                    v-if="history.meta.last_page > 1"
                    :current-page="history.meta.current_page"
                    :last-page="history.meta.last_page"
                    @change="visit"
                />
            </template>
        </CardContent>
    </Card>
</template>
