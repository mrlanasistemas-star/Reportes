<script setup lang="ts">
import { CheckCircle2, CircleSlash, Loader2, SearchX } from 'lucide-vue-next';
import type { PreviewResult } from './conceptCatalog';
import { mxn } from './types';

defineProps<{
    preview: PreviewResult | null;
    loading: boolean;
    error: string | null;
    periodLabel: string;
    /** El texto todavía es muy corto para buscar. */
    waiting: boolean;
}>();
</script>

<template>
    <section
        class="space-y-2 rounded-2xl border bg-muted/30 p-3"
        aria-live="polite"
    >
        <header class="flex items-center justify-between gap-2">
            <h4 class="text-sm font-semibold">
                Vista previa · {{ periodLabel }}
            </h4>
            <Loader2
                v-if="loading"
                class="size-4 animate-spin text-muted-foreground"
                aria-label="Buscando coincidencias"
            />
        </header>

        <p v-if="waiting" class="text-xs text-muted-foreground">
            Escribe el texto a buscar y aquí verás qué movimientos del periodo
            coinciden, antes de guardar.
        </p>
        <p v-else-if="error" class="text-xs text-rose-700 dark:text-rose-300">
            {{ error }}
        </p>
        <template v-else-if="preview">
            <p
                v-if="preview.total === 0"
                class="flex items-center gap-2 text-xs text-muted-foreground"
            >
                <SearchX class="size-4 shrink-0" />
                Ningún movimiento de este periodo coincide. Revisa el texto o
                los campos donde se busca.
            </p>
            <template v-else>
                <p class="text-xs">
                    <b>{{ preview.total }}</b> movimiento(s) coinciden;
                    <b class="text-emerald-700 dark:text-emerald-400"
                        >{{ preview.applies }} se clasificarían</b
                    >
                    con esta regla ({{ mxn(preview.amount) }})<template
                        v-if="preview.blocked"
                    >
                        y {{ preview.blocked }} se quedan como están (abajo te
                        decimos por qué)</template
                    >.
                </p>
                <ul class="max-h-64 space-y-1.5 overflow-y-auto pr-1">
                    <li
                        v-for="it in preview.items"
                        :key="it.fingerprint"
                        class="rounded-xl border bg-card p-2 text-xs"
                        :class="it.applies ? '' : 'opacity-75'"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="flex min-w-0 items-start gap-1.5">
                                <CheckCircle2
                                    v-if="it.applies"
                                    class="mt-0.5 size-3.5 shrink-0 text-emerald-600"
                                    aria-label="Se aplicaría"
                                />
                                <CircleSlash
                                    v-else
                                    class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                    aria-label="No se aplicaría"
                                />
                                <span class="min-w-0">
                                    <span class="font-medium break-words">{{
                                        it.concept || '(sin concepto)'
                                    }}</span>
                                    <span
                                        v-if="it.observations"
                                        class="block break-words text-muted-foreground"
                                        >{{ it.observations }}</span
                                    >
                                </span>
                            </span>
                            <span class="shrink-0 text-right tabular-nums">
                                <span class="block font-semibold">{{
                                    Number(it.cargo) > 0
                                        ? mxn(it.cargo)
                                        : `+${mxn(it.abono)}`
                                }}</span>
                                <span class="text-muted-foreground">{{
                                    it.date
                                }}</span>
                            </span>
                        </div>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            Hoy: {{ it.current }}
                            <template v-if="it.blocked_reason">
                                · {{ it.blocked_reason }}</template
                            >
                        </p>
                    </li>
                </ul>
                <p
                    v-if="preview.truncated"
                    class="text-[11px] text-muted-foreground"
                >
                    Se muestran los primeros {{ preview.items.length }}.
                </p>
            </template>
        </template>
    </section>
</template>
