<script setup lang="ts">
/**
 * MovementRow — fila limpia de la lista de control de movimientos.
 *
 * La usa MovementGroupList. Fecha, concepto en negrita, cuenta en pequeño, chip de estado,
 * señales a revisar (con el detalle en el tooltip) e importe a la derecha: cargos en rosa,
 * abonos en verde. La explicación larga NO va en la fila: está en el encabezado del grupo
 * (si la de esta fila es distinta, queda en el tooltip del icono de información).
 *
 * Props:
 * - row: fila ya preparada (ControlRowView).
 * - selectable: mostrar la columna de selección.
 * - checked: la fila está marcada.
 * - groupExplain: explicación del grupo (para no repetirla).
 * - showStatus: mostrar el chip de estado (en Dirección General cada fila trae el suyo).
 *
 * Emits:
 * - toggle: marcar o desmarcar.
 * - open: abrir el detalle (huella).
 */
import {
    AlertTriangle,
    ArrowDownLeft,
    ArrowUpRight,
    Info,
    Lock,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { STATUS_META } from './movementControl';
import type { ControlRowView } from './movementGroups';
import { mxn } from './types';

const props = withDefaults(
    defineProps<{
        row: ControlRowView;
        selectable?: boolean;
        checked?: boolean;
        groupExplain?: string | null;
        showStatus?: boolean;
    }>(),
    {
        selectable: false,
        checked: false,
        groupExplain: null,
        showStatus: true,
    },
);
const emit = defineEmits<{ toggle: []; open: [fingerprint: string] }>();

/** Severidad más alta de las señales (colorea el chip «Revisar»). */
const flagTone = computed(() =>
    props.row.flags.some((f) => f.severity === 'alta')
        ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
        : 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
);
/** Explicación propia de la fila, sólo si difiere de la del grupo. */
const ownExplain = computed(() =>
    props.row.explain && props.row.explain !== props.groupExplain
        ? props.row.explain
        : null,
);

/** Abre el detalle si la fila lo permite. */
function open(): void {
    if (props.row.openable && props.row.fingerprint) {
        emit('open', props.row.fingerprint);
    }
}
</script>

<template>
    <div
        class="group/row grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2.5 transition-colors sm:grid-cols-[auto_6.5rem_minmax(0,1fr)_auto_auto]"
        :class="
            row.openable
                ? 'cursor-pointer hover:bg-emerald-50/60 focus-visible:bg-emerald-50 focus-visible:outline-none dark:hover:bg-emerald-950/20 dark:focus-visible:bg-emerald-950/30'
                : ''
        "
        :role="row.openable ? 'button' : undefined"
        :tabindex="row.openable ? 0 : undefined"
        :aria-label="
            row.openable
                ? `Abrir ${row.concept ?? 'movimiento'} del ${row.date ?? '—'}`
                : undefined
        "
        @click="open"
        @keydown.enter.prevent="open"
        @keydown.space.prevent="open"
    >
        <!-- Selección -->
        <span class="flex w-5 justify-center" @click.stop @keydown.stop>
            <input
                v-if="selectable && row.selectable"
                type="checkbox"
                class="size-4 cursor-pointer rounded accent-emerald-600"
                :checked="checked"
                :aria-label="'Seleccionar ' + (row.concept ?? 'movimiento')"
                @change="emit('toggle')"
            />
            <Lock
                v-else-if="row.redacted"
                class="size-3.5 text-muted-foreground"
                aria-label="Restringido por permisos"
            />
        </span>

        <!-- Fecha (en pantallas anchas) -->
        <span
            class="hidden text-sm text-muted-foreground tabular-nums sm:block"
            >{{ row.date ?? '—' }}</span
        >

        <!-- Concepto y cuenta -->
        <span class="min-w-0">
            <span
                class="block truncate text-[15px] font-semibold text-foreground"
                :title="row.concept ?? ''"
                >{{ row.concept ?? '—' }}</span
            >
            <span
                class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
            >
                <span class="tabular-nums sm:hidden"
                    >{{ row.date ?? '—' }} ·</span
                >
                <span class="truncate" :title="row.file ?? row.account">{{
                    row.account
                }}</span>
                <span
                    v-if="ownExplain"
                    class="inline-flex shrink-0"
                    :title="ownExplain"
                    role="img"
                    :aria-label="ownExplain"
                >
                    <Info class="size-3.5 text-muted-foreground/80" />
                </span>
            </span>
        </span>

        <!-- Chips: señales y estado -->
        <span
            class="col-span-2 col-start-2 flex flex-wrap items-center gap-1.5 sm:col-span-1 sm:col-start-auto sm:justify-end"
        >
            <span
                v-if="row.flags.length"
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold"
                :class="flagTone"
                :title="row.flags.map((f) => '• ' + f.label).join('\n')"
            >
                <AlertTriangle class="size-3.5" />
                Revisar{{
                    row.flags.length > 1 ? ` (${row.flags.length})` : ''
                }}
            </span>
            <span
                v-if="showStatus"
                class="inline-flex max-w-52 items-center gap-1.5 truncate rounded-full px-2.5 py-0.5 text-xs font-semibold"
                :class="STATUS_META[row.status].cls"
                :title="row.explain || row.chip"
            >
                <span
                    class="size-1.5 shrink-0 rounded-full"
                    :class="STATUS_META[row.status].dot"
                />
                <span class="truncate">{{ row.chip }}</span>
            </span>
        </span>

        <!-- Importe -->
        <span
            class="col-start-3 row-start-1 text-right text-[15px] font-bold whitespace-nowrap tabular-nums sm:col-start-auto sm:row-start-auto"
        >
            <span
                v-if="row.cargo"
                class="flex items-center justify-end gap-1 text-rose-700 dark:text-rose-400"
            >
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
                {{ mxn(row.cargo) }}<span class="sr-only"> cargo</span>
            </span>
            <span
                v-if="row.abono"
                class="flex items-center justify-end gap-1 text-emerald-700 dark:text-emerald-400"
            >
                <ArrowDownLeft class="size-3.5" aria-hidden="true" />
                {{ mxn(row.abono) }}<span class="sr-only"> abono</span>
            </span>
            <span
                v-if="!row.cargo && !row.abono"
                class="text-muted-foreground"
                >{{ mxn(0) }}</span
            >
        </span>
    </div>
</template>
