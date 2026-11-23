<script setup lang="ts">
/**
 * FileControlTable — control por archivo de estado de cuenta (pestaña Validación).
 *
 * La usa ValidationPanel. Por cada archivo que aporta al periodo muestra el
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
    <section
        id="er-control-archivos"
        class="rounded-2xl border bg-card p-5 shadow-soft-xs"
        aria-label="Qué pasó con cada peso de cada archivo"
    >
        <!-- Encabezado y regla de la partición -->
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-base font-extrabold">
                Qué pasó con cada peso de cada archivo
            </h3>
            <p class="text-xs text-muted-foreground">
                {{ control.totals.files }} archivo(s) aportan a este periodo ·
                {{ control.totals.files - control.totals.own_files }} subido(s)
                en otro periodo
            </p>
        </div>
        <p class="mt-1 text-xs text-muted-foreground">
            {{ control.totals.rule }}
        </p>

        <p
            v-if="!control.uploads.length"
            class="mt-4 rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground"
        >
            Ningún estado de cuenta aporta movimientos a este periodo.
        </p>

        <!-- Una tarjeta por archivo -->
        <ul class="mt-4 grid gap-3 xl:grid-cols-2">
            <li
                v-for="u in control.uploads"
                :key="u.upload_id"
                class="rounded-xl border p-3"
                :class="
                    u.saved_ok
                        ? ''
                        : 'border-rose-300 bg-rose-50/40 dark:border-rose-800 dark:bg-rose-950/20'
                "
            >
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <FileSpreadsheet class="size-4 shrink-0 text-emerald-600" />
                    <span class="min-w-0 flex-1 truncate" :title="u.file">{{
                        u.file
                    }}</span>
                    <span
                        class="shrink-0 rounded-full px-2 py-px text-[10.5px] font-bold"
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
                <p class="mt-0.5 text-[11px] text-muted-foreground">
                    {{ u.account_label ?? u.slot }}
                    <template v-if="u.file_part && u.file_part > 1">
                        · archivo {{ u.file_part }}</template
                    >
                    · {{ u.movements.toLocaleString('es-MX') }} filas leídas
                </p>

                <!-- Total leído, control del banco y partes de la partición -->
                <table class="mt-2 w-full text-xs tabular-nums">
                    <tbody>
                        <tr class="border-b font-semibold">
                            <td class="py-1">Total del archivo (leído)</td>
                            <td class="text-right text-muted-foreground">
                                {{ u.movements.toLocaleString('es-MX') }}
                            </td>
                            <td class="w-32 text-right">
                                {{ mxn(u.read_total) }}
                            </td>
                            <td class="w-5" />
                        </tr>
                        <tr v-if="u.control_total !== null" class="border-b">
                            <td class="py-1 text-muted-foreground">
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
                            class="cursor-pointer transition hover:bg-muted/60"
                            :title="'Ver los movimientos: ' + p.label"
                            @click="drill(u.upload_id, u.file, p)"
                        >
                            <td class="py-1">
                                <span class="flex items-center gap-1.5">
                                    <span
                                        class="size-2 shrink-0 rounded-full"
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
                                    class="ml-3.5 block text-[11px] text-muted-foreground"
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
                                    class="ml-auto size-3.5 text-muted-foreground"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Resultado del guardado -->
                <p
                    class="mt-2 flex items-center gap-1.5 text-xs font-semibold"
                    :class="
                        u.saved_ok
                            ? 'text-emerald-700 dark:text-emerald-400'
                            : 'text-rose-700 dark:text-rose-400'
                    "
                >
                    <CheckCircle2 v-if="u.saved_ok" class="size-3.5" />
                    <XCircle v-else class="size-3.5" />
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
    </section>
</template>
