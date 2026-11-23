<script setup lang="ts">
/**
 * ConceptCatalogPanel — pestaña «Catálogo de conceptos» del Estado de
 * Resultados.
 *
 * La usa la página EstadoResultados/Index. Lista las reglas que dicen qué
 * texto del estado de cuenta va a qué concepto del reporte (valen para todos
 * los periodos y se aplican al procesar la conciliación), con buscador y
 * acciones de editar, activar/desactivar y borrar. Abre ConceptRuleDialog
 * para crear o editar una regla, con vista previa sobre el periodo. Los
 * datos y acciones vienen de useConceptCatalog.
 *
 * Props:
 * - periodId: periodo para la vista previa y el reproceso.
 * - periodLabel: nombre del periodo (se muestra en el diálogo).
 * - treatments: catálogo de tratamientos financieros.
 * - canEdit: permiso «catálogo» (y acceso general).
 * - canProcess: si puede procesar la conciliación del periodo mostrado.
 * - lockReason: motivo de solo lectura del periodo, o null.
 * - prefill: alta precargada desde el detalle de un movimiento, o null.
 * - process: función que reprocesa la conciliación tras guardar.
 *
 * Emits:
 * - prefillUsed(): ya se abrió el diálogo con la precarga (el padre la limpia).
 */
import {
    BookMarked,
    Loader2,
    Pause,
    Pencil,
    Play,
    Plus,
    Search,
    Trash2,
} from 'lucide-vue-next';
import { computed, onMounted, ref, toRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConceptCatalog } from '@/composables/estado-resultados/useConceptCatalog';
import { normalizeText } from './conceptCatalog';
import type { ConceptRule, RuleDraft } from './conceptCatalog';
import ConceptRuleDialog from './ConceptRuleDialog.vue';
import type { TreatmentMeta } from './types';

const props = defineProps<{
    periodId: number;
    periodLabel: string;
    treatments: TreatmentMeta[];
    /** Permiso «catálogo» (y acceso general). */
    canEdit: boolean;
    /** Puede procesar la conciliación del periodo mostrado. */
    canProcess: boolean;
    lockReason: string | null;
    /** Alta precargada desde el detalle de un movimiento. */
    prefill: Partial<RuleDraft> | null;
    process: () => Promise<void>;
}>();

const emit = defineEmits<{ prefillUsed: [] }>();

// ── Estado (antes de cualquier watch) ─────────────────────────────────
/** Estado y acciones del catálogo (cargar, guardar, activar, borrar, vista previa). */
const catalog = useConceptCatalog({
    periodId: toRef(props, 'periodId'),
    canProcess: () => props.canProcess,
    lockReason: () => props.lockReason,
    process: () => props.process(),
});
const { rules, options, loading, loadError, saving, busyId } = catalog;
/** Texto del buscador. */
const search = ref('');
/** Si el diálogo de regla está abierto. */
const dialogOpen = ref(false);
/** Regla que se edita, o null si es alta. */
const editing = ref<ConceptRule | null>(null);
/** Valores precargados para una regla nueva, o null. */
const initial = ref<Partial<RuleDraft> | null>(null);

/** Reglas que coinciden con el buscador (nombre, texto, destino o notas, sin acentos). */
const filtered = computed(() => {
    const q = normalizeText(search.value);

    if (!q) {
        return rules.value;
    }

    return rules.value.filter((r) =>
        normalizeText(
            [r.name, r.match_text, r.treatment_label, r.notes ?? ''].join(' '),
        ).includes(q),
    );
});
/** Número de reglas activas. */
const activeCount = computed(() => rules.value.filter((r) => r.active).length);

/**
 * Abre el diálogo para una regla nueva.
 * @param over valores precargados opcionales.
 */
function openNew(over: Partial<RuleDraft> | null = null): void {
    editing.value = null;
    initial.value = over;
    dialogOpen.value = true;
}

/**
 * Abre el diálogo para editar una regla existente.
 * @param r regla a editar.
 */
function openEdit(r: ConceptRule): void {
    editing.value = r;
    initial.value = null;
    dialogOpen.value = true;
}

/**
 * Guarda la regla (alta o edición) y cierra el diálogo si se guardó.
 * @param draft valores capturados.
 * @param id id de la regla editada, o null si es alta.
 */
async function submit(draft: RuleDraft, id: number | null): Promise<void> {
    if (await catalog.save(draft, id)) {
        dialogOpen.value = false;
    }
}

/** Carga el catálogo al montar. */
onMounted(() => catalog.load());
/**
 * Si llega una precarga y se puede editar, abre el alta y avisa que se usó.
 * Sin permiso de edición la precarga se descarta (también avisa), para que no
 * quede pendiente y abra el alta por sorpresa si el permiso cambia después.
 */
watch(
    () => props.prefill,
    (p) => {
        if (!p) {
            return;
        }

        if (props.canEdit) {
            openNew(p);
        }

        emit('prefillUsed');
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-4">
        <header
            class="flex flex-col gap-3 rounded-2xl border bg-card p-4 md:flex-row md:items-center md:justify-between"
        >
            <div class="flex items-start gap-3">
                <BookMarked
                    class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
                <div>
                    <h2 class="text-base font-bold">Catálogo de conceptos</h2>
                    <p class="text-sm text-muted-foreground">
                        Define qué texto del estado de cuenta va a qué concepto
                        del reporte. Vale para todos los periodos y se aplica al
                        procesar la conciliación.
                        <span v-if="rules.length"
                            >{{ activeCount }} de {{ rules.length }} regla(s)
                            activas.</span
                        >
                    </p>
                </div>
            </div>
            <Button
                v-if="canEdit"
                class="shrink-0 bg-emerald-700 text-white hover:bg-emerald-800"
                @click="openNew()"
            >
                <Plus class="size-4" /> Nueva regla
            </Button>
        </header>

        <!-- Buscador de reglas -->
        <div class="relative max-w-sm">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                class="pl-9"
                placeholder="Buscar por concepto, texto o destino"
                aria-label="Buscar reglas del catálogo"
            />
        </div>

        <!-- Estados: cargando, error, sin reglas, sin coincidencias o tabla de reglas -->
        <div
            v-if="loading && !rules.length"
            class="flex items-center gap-2 rounded-2xl border bg-card p-6 text-sm text-muted-foreground"
        >
            <Loader2 class="size-4 animate-spin" /> Leyendo el catálogo…
        </div>
        <div
            v-else-if="loadError"
            class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"
        >
            {{ loadError }}
            <Button
                size="sm"
                variant="outline"
                class="ml-2"
                @click="catalog.load()"
                >Reintentar</Button
            >
        </div>
        <div
            v-else-if="!rules.length"
            class="rounded-2xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground"
        >
            Todavía no hay reglas.
            <template v-if="canEdit">
                Crea la primera con «Nueva regla» o desde el detalle de un
                movimiento («Crear regla de catálogo con este texto»).
            </template>
        </div>
        <p
            v-else-if="!filtered.length"
            class="rounded-2xl border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
        >
            Ninguna regla coincide con «{{ search }}».
        </p>

        <div v-else class="overflow-x-auto rounded-2xl border bg-card">
            <table class="w-full min-w-[760px] text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2 font-semibold">Concepto</th>
                        <th class="px-3 py-2 font-semibold">
                            Cuando el texto…
                        </th>
                        <th class="px-3 py-2 font-semibold">Aplica a</th>
                        <th class="px-3 py-2 font-semibold">Va a</th>
                        <th class="px-3 py-2 text-right font-semibold">
                            Prioridad
                        </th>
                        <th class="px-3 py-2 font-semibold">Estado</th>
                        <th v-if="canEdit" class="px-3 py-2">
                            <span class="sr-only">Acciones</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in filtered"
                        :key="r.id"
                        class="border-t align-top"
                        :class="r.active ? '' : 'opacity-60'"
                    >
                        <td class="px-3 py-2">
                            <p class="font-semibold">{{ r.name }}</p>
                            <p
                                v-if="r.notes"
                                class="text-xs text-muted-foreground"
                            >
                                {{ r.notes }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                {{ r.updated_by ?? r.created_by ?? '—' }} ·
                                {{ r.updated_at }}
                            </p>
                        </td>
                        <td class="px-3 py-2">
                            <span class="text-xs text-muted-foreground">{{
                                r.match_mode_label
                            }}</span>
                            <p class="font-mono text-xs font-semibold">
                                “{{ r.match_text }}”
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                en {{ r.fields_label }}
                            </p>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            {{ r.account_label }}<br />
                            <span class="text-muted-foreground">{{
                                r.direction_label
                            }}</span>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            <span class="font-medium">{{
                                r.treatment_label
                            }}</span>
                            <br />
                            <span class="text-muted-foreground">{{
                                r.line_label
                            }}</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ r.priority }}
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                :class="
                                    r.active
                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300'
                                        : 'border-border bg-muted text-muted-foreground'
                                "
                                >{{ r.active ? 'Activa' : 'Desactivada' }}</span
                            >
                        </td>
                        <td v-if="canEdit" class="px-3 py-2">
                            <div class="flex justify-end gap-1">
                                <Loader2
                                    v-if="busyId === r.id"
                                    class="m-2 size-4 animate-spin text-muted-foreground"
                                />
                                <template v-else>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        :aria-label="`Editar ${r.name}`"
                                        title="Editar"
                                        @click="openEdit(r)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        :aria-label="
                                            r.active
                                                ? `Desactivar ${r.name}`
                                                : `Activar ${r.name}`
                                        "
                                        :title="
                                            r.active ? 'Desactivar' : 'Activar'
                                        "
                                        @click="catalog.toggle(r)"
                                    >
                                        <Pause v-if="r.active" class="size-4" />
                                        <Play v-else class="size-4" />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="text-rose-700 hover:text-rose-800 dark:text-rose-400"
                                        :aria-label="`Borrar ${r.name}`"
                                        title="Borrar"
                                        @click="catalog.remove(r)"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </template>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Diálogo de alta/edición de regla con vista previa -->
        <ConceptRuleDialog
            v-model:open="dialogOpen"
            :rule="editing"
            :initial="initial"
            :options="options"
            :treatments="treatments"
            :saving="saving"
            :period-label="periodLabel"
            :preview="catalog.preview.value"
            :preview-loading="catalog.previewLoading.value"
            :preview-error="catalog.previewError.value"
            @submit="submit"
            @preview="catalog.runPreview"
            @clear-preview="catalog.clearPreview"
        />
    </div>
</template>
