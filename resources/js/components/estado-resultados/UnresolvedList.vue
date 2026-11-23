<script setup lang="ts">
/**
 * UnresolvedList — lista «Sin resolver» de la pestaña Validación.
 *
 * Se usa en ValidationPanel. Cada partida muestra si bloquea la aprobación,
 * cuántas siguen abiertas, el importe abierto y, si hay algo que la explique,
 * un botón «Ver movimientos». No se dibuja si la lista está vacía.
 *
 * Props:
 * - unresolved: partidas sin resolver (ControlUnresolved) con sus movimientos/archivos.
 *
 * Emits:
 * - show(payload): pide abrir la lista de control con título, nota, movimientos
 *   y archivos de la partida.
 */
import { ListTree, XCircle } from 'lucide-vue-next';
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
    <section
        v-if="unresolved.length"
        class="rounded-2xl border border-rose-300 bg-card shadow-soft-xs dark:border-rose-900"
        aria-label="Diferencias sin resolver"
    >
        <header
            class="flex items-center gap-2 border-b border-rose-200 px-5 py-3 dark:border-rose-900"
        >
            <XCircle class="size-4 text-rose-600" />
            <h3 class="text-sm font-extrabold text-rose-800 dark:text-rose-300">
                Sin resolver ({{ unresolved.length }})
            </h3>
        </header>
        <ul class="divide-y divide-rose-100 dark:divide-rose-950">
            <li
                v-for="u in unresolved"
                :key="u.key"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 text-sm"
            >
                <span class="size-2 shrink-0 rounded-full bg-rose-500" />
                <span class="min-w-0 flex-1 font-medium">{{ u.label }}</span>
                <span
                    v-if="u.blocking !== undefined"
                    class="shrink-0 rounded-full px-2 py-px text-[10.5px] font-bold"
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
                        u.blocking ? 'Bloquea la aprobación' : 'Informativo'
                    }}</span
                >
                <span
                    class="shrink-0 text-xs text-muted-foreground tabular-nums"
                    :title="
                        u.open_count !== undefined ? 'Abiertas / total' : ''
                    "
                    >{{ (u.open_count ?? u.count).toLocaleString('es-MX')
                    }}<template
                        v-if="
                            u.open_count !== undefined &&
                            u.open_count !== u.count
                        "
                    >
                        de {{ u.count.toLocaleString('es-MX') }}</template
                    >
                    abiertas</span
                >
                <span
                    class="w-32 shrink-0 text-right font-bold text-rose-700 tabular-nums dark:text-rose-400"
                    >{{ mxn(u.open_amount ?? u.amount) }}</span
                >
                <button
                    v-if="explainable(u)"
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border px-2 py-1 text-xs font-semibold transition hover:bg-muted"
                    @click="
                        emit('show', {
                            title: u.label,
                            note: u.note ?? null,
                            items: u.items ?? [],
                            files: u.files ?? [],
                        })
                    "
                >
                    <ListTree class="size-3.5" /> Ver movimientos
                </button>
            </li>
        </ul>
    </section>
</template>
