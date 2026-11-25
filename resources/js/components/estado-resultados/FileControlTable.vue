<script setup lang="ts">
/**
 * FileControlTable — control por archivo de estado de cuenta (pestaña Validación).
 *
 * La usa ValidationPanel (dentro de su modal). Por cada archivo que aporta al periodo muestra el
 * total leído, el control del banco (TOTAL EXCEL) con su diferencia, cada
 * parte de la partición (entra aquí, otros periodos, repetido, no guardado...)
 * con conteo y monto, y si todas las filas quedaron guardadas.
 *
 * Props:
 * - control: control de archivos del periodo (totales y archivos con sus partes).
 *
 * Emits:
 * - drill: abrir la lista de movimientos de una parte (uploadId, filtro y título).
 */
import {
    CheckCircle2,
    ChevronRight,
    FileSpreadsheet,
    XCircle,
} from 'lucide-vue-next';
import type { ControlFilter, FileControl, FilePart } from './movementControl';
import { isZero, mxn } from './types';

/**
 * Partición completa de cada archivo que aporta al periodo: total del archivo = entra aquí +
 * va a otros periodos + repetido + sin periodo + cerrado/existente + no guardado. Cada renglón
 * abre la lista de movimientos que lo componen.
 */
defineProps<{ control: FileControl }>();
const emit = defineEmits<{
    drill: [
        payload: { uploadId: number; filter: ControlFilter; title: string },
    ];
}>();

/** Filtro de la lista de movimientos que corresponde a cada parte de la partición. */
const PART_FILTER: Record<string, ControlFilter> = {
    entra: 'todos',
    otro_periodo: 'fuera',
    duplicado: 'duplicado',
    sin_periodo: 'fuera',
    periodo_cerrado: 'fuera',
    periodo_existente: 'fuera',
    fuera_periodo: 'fuera',
    no_guardado: 'no_guardado',
};

/** Color del punto de cada parte (gris si no está aquí). */
const PART_DOT: Record<string, string> = {
    entra: 'bg-emerald-500',
    otro_periodo: 'bg-sky-500',
    duplicado: 'bg-violet-500',
    no_guardado: 'bg-rose-500',
};

/**
 * Pide abrir los movimientos de una parte de un archivo.
 * @param uploadId id del archivo cargado.
 * @param file nombre del archivo (para el título).
 * @param p parte de la partición.
 */
function drill(uploadId: number, file: string, p: FilePart): void {
    emit('drill', {
        uploadId,
        filter: PART_FILTER[p.key] ?? 'todos',
        title: `${file} · ${p.label}`,
    });
}
</script>

<template>
    <div
        id="er-control-archivos"
        aria-label="Qué pasó con cada peso de cada archivo"
    >
        <!-- Resumen -->
        <p class="flex flex-wrap gap-2 text-sm">
            <span class="rounded-full bg-muted px-3 py-1 font-semibold"
                >{{ control.totals.files }} archivo(s) aportan a este
                periodo</span
            >
            <span
                v-if="control.totals.files - control.totals.own_files"
                class="rounded-full bg-sky-100 px-3 py-1 font-semibold text-sky-800 dark:bg-sky-950/60 dark:text-sky-300"
                >{{ control.totals.files - control.totals.own_files }} subido(s)
                en otro periodo</span
            >
        </p>

        <p
            v-if="!control.uploads.length"
            class="mt-4 rounded-2xl border border-dashed p-8 text-center text-[15px] text-muted-foreground"
        >
            Ningún estado de cuenta aporta movimientos a este periodo.
        </p>

        <!-- Una tarjeta por archivo -->
        <ul class="mt-4 grid gap-4 lg:grid-cols-2">
            <li
                v-for="u in control.uploads"
                :key="u.upload_id"
                class="rounded-2xl border bg-card p-4 shadow-soft-xs"
                :class="
                    u.saved_ok
                        ? ''
                        : 'border-rose-300 bg-rose-50/40 dark:border-rose-800 dark:bg-rose-950/20'
                "
            >
                <p class="flex items-center gap-2 font-semibold">
                    <FileSpreadsheet class="size-5 shrink-0 text-emerald-600" />
                    <span class="min-w-0 flex-1 truncate" :title="u.file">{{
                        u.file
                    }}</span>
                    <span
                        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-bold"
                        :class="
                            u.own
                                ? 'bg-muted text-muted-foreground'
                                : 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'
                        "
                        >{{
                            u.own
                                ? 'Subido en este periodo'
                                : `Subido en ${u.upload_period.label ?? 'otro periodo'}`
                        }}</span
                    >
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ u.account_label ?? u.slot }}
                    <template v-if="u.file_part && u.file_part > 1">
                        · archivo {{ u.file_part }}</template
                    >
                    · {{ u.movements.toLocaleString('es-MX') }} filas leídas
                </p>

                <!-- Total leído, control del banco y partes de la partición -->
                <table class="mt-3 w-full text-sm tabular-nums">
                    <tbody>
                        <tr class="border-b font-bold">
                            <td class="py-2">Total del archivo (leído)</td>
                            <td class="text-right text-muted-foreground">
                                {{ u.movements.toLocaleString('es-MX') }}
                            </td>
                            <td class="w-36 text-right">
                                {{ mxn(u.read_total) }}
                            </td>
                            <td class="w-6" />
                        </tr>
                        <tr v-if="u.control_total !== null" class="border-b">
                            <td class="py-2 text-muted-foreground">
                                Control del banco (TOTAL EXCEL)
                            </td>
                            <td />
                            <td class="text-right text-muted-foreground">
                                {{ mxn(u.control_total) }}
                                <span
                                    v-if="!isZero(u.control_difference)"
                                    class="block font-bold text-rose-700 dark:text-rose-400"
                                    >dif. {{ mxn(u.control_difference) }}</span
                                >
                            </td>
                            <td />
                        </tr>
                        <tr
                            v-for="p in u.parts"
                            :key="p.key + '|' + (p.period_id ?? '')"
                            class="group cursor-pointer transition hover:bg-emerald-50/60 focus-visible:bg-emerald-50 focus-visible:outline-none dark:hover:bg-emerald-950/20"
                            :title="'Ver los movimientos: ' + p.label"
                            tabindex="0"
                            role="button"
                            @click="drill(u.upload_id, u.file, p)"
                            @keydown.enter.prevent="
                                drill(u.upload_id, u.file, p)
                            "
                        >
                            <td class="py-2">
                                <span class="flex items-center gap-2">
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :class="
                                            PART_DOT[p.key] ?? 'bg-slate-400'
                                        "
                                    />
                                    <span
                                        :class="
                                            p.key === 'no_guardado'
                                                ? 'font-bold text-rose-700 dark:text-rose-400'
                                                : ''
                                        "
                                        >{{ p.label }}</span
                                    >
                                </span>
                                <span
                                    v-for="a in p.against"
                                    :key="a.upload_id"
                                    class="ml-4.5 block text-xs text-muted-foreground"
                                    >choca con {{ a.file ?? '—' }}
                                    <template v-if="a.period_label"
                                        >({{ a.period_label }})</template
                                    >
                                    · {{ a.count }} · {{ mxn(a.total) }}</span
                                >
                            </td>
                            <td class="text-right text-muted-foreground">
                                {{ p.count.toLocaleString('es-MX') }}
                            </td>
                            <td class="text-right font-semibold">
                                {{ mxn(p.total) }}
                            </td>
                            <td class="text-right">
                                <ChevronRight
                                    class="ml-auto size-4 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-emerald-600"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Resultado del guardado -->
                <p
                    class="mt-3 flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold"
                    :class="
                        u.saved_ok
                            ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300'
                            : 'bg-rose-50 text-rose-800 dark:bg-rose-950/30 dark:text-rose-300'
                    "
                >
                    <CheckCircle2 v-if="u.saved_ok" class="size-4 shrink-0" />
                    <XCircle v-else class="size-4 shrink-0" />
                    <template v-if="u.saved_ok"
                        >Cada fila leída quedó guardada · aporta a este periodo
                        {{ mxn(u.period_share.total) }}</template
                    >
                    <template v-else
                        >{{ u.not_saved.count }} fila(s) leída(s) no guardada(s)
                        · {{ mxn(u.not_saved.total) }}</template
                    >
                </p>
            </li>
        </ul>
    </div>
</template>
