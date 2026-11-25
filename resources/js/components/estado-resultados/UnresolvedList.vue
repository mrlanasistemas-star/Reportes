<script setup lang="ts">
/**
 * UnresolvedList — lista «Sin resolver» de la pestaña Validación.
 *
 * Se usa en ValidationPanel (dentro del modal «Sin resolver»). Cada partida es una
 * tarjeta con si bloquea la aprobación, cuántas siguen abiertas, el importe abierto y,
 * si hay algo que la explique, un botón «Ver movimientos». Sin partidas muestra un
 * estado vacío en verde.
 *
 * Props:
 * - unresolved: partidas sin resolver (ControlUnresolved) con sus movimientos/archivos.
 *
 * Emits:
 * - show(payload): pide abrir la lista de control con título, nota, movimientos
 *   y archivos de la partida.
 */
import { CheckCircle2, ListTree, XCircle } from 'lucide-vue-next';
import type {
    ControlFileRef,
    ControlRef,
    ControlUnresolved,
} from './movementControl';
import { mxn } from './types';

/**
 * Partidas sin resolver de la validación. Cada una trae los movimientos (o archivos) que la
 * explican: «Ver movimientos» abre la lista de control enfocada en ellos.
 */
defineProps<{ unresolved: ControlUnresolved[] }>();
const emit = defineEmits<{
    show: [
        payload: {
            title: string;
            note: string | null;
            items: ControlRef[];
            files: ControlFileRef[];
        },
    ];
}>();

/**
 * Indica si la partida tiene movimientos, archivos o nota que mostrar.
 * @param u partida sin resolver.
 */
const explainable = (u: ControlUnresolved) =>
    (u.items?.length ?? 0) > 0 || (u.files?.length ?? 0) > 0 || !!u.note;
</script>

<template>
    <div aria-label="Diferencias sin resolver">
        <p
            v-if="!unresolved.length"
            class="flex items-center justify-center gap-2 rounded-2xl border border-dashed border-emerald-300 bg-emerald-50/60 p-8 text-[15px] font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-300"
        >
            <CheckCircle2 class="size-5" /> No hay diferencias sin resolver.
        </p>
        <ul v-else class="space-y-3">
            <li
                v-for="u in unresolved"
                :key="u.key"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border bg-card p-4 shadow-soft-xs transition hover:shadow-md"
                :class="
                    u.blocking
                        ? 'border-rose-300 dark:border-rose-900'
                        : 'border-border'
                "
            >
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-xl"
                    :class="
                        u.blocking
                            ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                            : 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                    "
                    aria-hidden="true"
                >
                    <XCircle class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[15px] leading-snug font-bold">
                        {{ u.label }}
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                        <span
                            v-if="u.blocking !== undefined"
                            class="rounded-full px-2.5 py-0.5 text-xs font-bold"
                            :class="
                                u.blocking
                                    ? 'bg-rose-600 text-white'
                                    : 'bg-muted text-muted-foreground'
                            "
                            :title="
                                u.blocking
                                    ? 'Impide aprobar la versión.'
                                    : 'Informativo: no impide aprobar.'
                            "
                            >{{
                                u.blocking
                                    ? 'Bloquea la aprobación'
                                    : 'Informativo'
                            }}</span
                        >
                        <span
                            class="text-muted-foreground tabular-nums"
                            :title="
                                u.open_count !== undefined
                                    ? 'Abiertas / total'
                                    : ''
                            "
                            >{{
                                (u.open_count ?? u.count).toLocaleString(
                                    'es-MX',
                                )
                            }}<template
                                v-if="
                                    u.open_count !== undefined &&
                                    u.open_count !== u.count
                                "
                            >
                                de
                                {{ u.count.toLocaleString('es-MX') }}</template
                            >
                            abiertas</span
                        >
                    </p>
                </div>
                <span
                    class="shrink-0 text-right text-lg font-extrabold text-rose-700 tabular-nums dark:text-rose-400"
                    >{{ mxn(u.open_amount ?? u.amount) }}</span
                >
                <button
                    v-if="explainable(u)"
                    type="button"
                    class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl bg-foreground px-3.5 text-sm font-semibold text-background transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 focus-visible:outline-none"
                    @click="
                        emit('show', {
                            title: u.label,
                            note: u.note ?? null,
                            items: u.items ?? [],
                            files: u.files ?? [],
                        })
                    "
                >
                    <ListTree class="size-4" /> Ver movimientos
                </button>
            </li>
        </ul>
    </div>
</template>
