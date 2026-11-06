<script setup lang="ts">
import {
    CheckCircle2,
    ChevronDown,
    CircleSlash,
    Loader2,
    RefreshCw,
    XCircle,
} from 'lucide-vue-next';
import { onMounted, reactive, ref, watch } from 'vue';
import { apiJson } from './types';
import type { UploadAttempt } from './types';

const props = defineProps<{
    url: string;
    /** Cambia tras cada recarga del dataset para volver a leer los intentos. */
    refreshKey: number;
}>();

const attempts = ref<UploadAttempt[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const open = reactive<Record<number, boolean>>({});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const res = await apiJson<{ attempts: UploadAttempt[] }>(props.url);
        attempts.value = res.attempts;
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'No se pudieron leer los intentos.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch(() => props.refreshKey, load);

const ok = (a: UploadAttempt) => !!a.upload_id && !a.errors_count;
</script>

<template>
    <section class="rounded-2xl border bg-card shadow-soft-xs">
        <header
            class="flex items-center justify-between gap-2 border-b px-5 py-4"
        >
            <div>
                <h3 class="text-base font-extrabold">Intentos de carga</h3>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Cada archivo que se intentó subir, aunque se haya rechazado,
                    con sus errores por fila.
                </p>
            </div>
            <button
                type="button"
                class="rounded-lg p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                aria-label="Actualizar intentos"
                @click="load"
            >
                <Loader2 v-if="loading" class="size-4 animate-spin" />
                <RefreshCw v-else class="size-4" />
            </button>
        </header>
        <p
            v-if="error"
            class="px-5 py-4 text-sm text-rose-700 dark:text-rose-300"
        >
            {{ error }}
        </p>
        <p
            v-else-if="!loading && !attempts.length"
            class="px-5 py-6 text-sm text-muted-foreground"
        >
            Aún no hay intentos de carga en este periodo.
        </p>
        <ul v-else class="max-h-[520px] divide-y overflow-y-auto">
            <li v-for="a in attempts" :key="a.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 px-5 py-3 text-left transition hover:bg-muted/40 disabled:cursor-default"
                    :disabled="!a.errors.length"
                    :aria-expanded="!!open[a.id]"
                    @click="open[a.id] = !open[a.id]"
                >
                    <CheckCircle2
                        v-if="ok(a)"
                        class="size-4 shrink-0 text-emerald-600"
                    />
                    <XCircle
                        v-else-if="a.errors_count"
                        class="size-4 shrink-0 text-rose-600"
                    />
                    <CircleSlash
                        v-else
                        class="size-4 shrink-0 text-muted-foreground"
                    />
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm font-semibold"
                            :title="a.file"
                            >{{ a.file }}</span
                        >
                        <span class="block text-xs text-muted-foreground">
                            {{ a.slot_label }} · {{ a.user ?? '—' }} ·
                            {{ a.at }}
                        </span>
                        <span
                            v-if="a.message"
                            class="mt-0.5 block text-xs"
                            :class="
                                ok(a)
                                    ? 'text-muted-foreground'
                                    : 'text-rose-700 dark:text-rose-300'
                            "
                            >{{ a.message }}</span
                        >
                    </span>
                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold"
                        :class="
                            ok(a)
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                : a.errors_count
                                  ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
                                  : 'bg-muted text-muted-foreground'
                        "
                        >{{ a.status_label }}
                        <template v-if="a.errors_count">
                            · {{ a.errors_count }} error(es)</template
                        ></span
                    >
                    <ChevronDown
                        v-if="a.errors.length"
                        class="size-4 shrink-0 text-muted-foreground transition-transform"
                        :class="open[a.id] ? 'rotate-180' : ''"
                    />
                </button>
                <div v-if="open[a.id] && a.errors.length" class="px-5 pb-4">
                    <div class="max-h-64 overflow-auto rounded-xl border">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-muted text-left">
                                <tr>
                                    <th class="px-3 py-1.5 font-semibold">
                                        Fila
                                    </th>
                                    <th class="px-3 py-1.5 font-semibold">
                                        Columna
                                    </th>
                                    <th class="px-3 py-1.5 font-semibold">
                                        Qué corregir
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(e, i) in a.errors"
                                    :key="i"
                                    class="border-t"
                                >
                                    <td class="px-3 py-1.5 tabular-nums">
                                        {{ e.row ?? '—' }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        {{ e.column ?? '—' }}
                                    </td>
                                    <td class="px-3 py-1.5">{{ e.message }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p
                        v-if="a.errors_count > a.errors.length"
                        class="mt-1 text-[11px] text-muted-foreground"
                    >
                        Se muestran {{ a.errors.length }} de
                        {{ a.errors_count }}
                        errores.
                    </p>
                </div>
            </li>
        </ul>
    </section>
</template>
