<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { Loader2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { draftFromRule, emptyDraft, normalizeText } from './conceptCatalog';
import type {
    CatalogOptions,
    ConceptRule,
    PreviewResult,
    RuleDraft,
} from './conceptCatalog';
import ConceptRulePreview from './ConceptRulePreview.vue';
import type { TreatmentMeta } from './types';

const props = defineProps<{
    open: boolean;
    /** Regla a editar (null = alta). */
    rule: ConceptRule | null;
    /** Valores iniciales para un alta (p. ej. desde el detalle de un movimiento). */
    initial: Partial<RuleDraft> | null;
    options: CatalogOptions | null;
    treatments: TreatmentMeta[];
    saving: boolean;
    periodLabel: string;
    preview: PreviewResult | null;
    previewLoading: boolean;
    previewError: string | null;
}>();

const emit = defineEmits<{
    'update:open': [v: boolean];
    submit: [draft: RuleDraft, id: number | null];
    preview: [draft: RuleDraft, id: number | null];
    clearPreview: [];
}>();

// ── Estado (antes de cualquier watch) ─────────────────────────────────
const draft = ref<RuleDraft>(emptyDraft());
const minText = computed(() => props.options?.min_text ?? 3);
const normalized = computed(() => normalizeText(draft.value.match_text));
const textTooShort = computed(() => normalized.value.length < minText.value);
const assignable = computed(() => props.treatments.filter((t) => t.assignable));
const groups = computed(() => [
    {
        label: 'Afectan el Estado de Resultados',
        items: assignable.value.filter((t) => t.group === 'er'),
    },
    {
        label: 'Fuera del Estado de Resultados',
        items: assignable.value.filter((t) => t.group === 'fuera'),
    },
    {
        label: 'Pendiente',
        items: assignable.value.filter((t) => t.group === 'pendiente'),
    },
]);
const chosen = computed(
    () => assignable.value.find((t) => t.key === draft.value.treatment) ?? null,
);
const valid = computed(
    () =>
        draft.value.name.trim().length >= 3 &&
        !textTooShort.value &&
        draft.value.fields.length > 0 &&
        !!chosen.value,
);
const title = computed(() =>
    props.rule
        ? `Editar regla «${props.rule.name}»`
        : 'Nueva regla del catálogo',
);
/** Sólo lo que cambia qué movimientos coinciden (el nombre o las notas no). */
const matchKey = computed(() =>
    JSON.stringify([
        normalized.value,
        draft.value.match_mode,
        draft.value.fields,
        draft.value.account_slot,
        draft.value.direction,
        draft.value.treatment,
        Number(draft.value.priority) || 0,
    ]),
);

const requestPreview = useDebounceFn(() => {
    if (props.open && !textTooShort.value) {
        emit('preview', { ...draft.value }, props.rule?.id ?? null);
    }
}, 400);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        draft.value = props.rule
            ? draftFromRule(props.rule)
            : emptyDraft(props.initial ?? {});
        emit('clearPreview');
        requestPreview();
    },
    { immediate: true },
);
watch(matchKey, () => {
    if (textTooShort.value) {
        emit('clearPreview');

        return;
    }

    requestPreview();
});

function close(v: boolean): void {
    if (!props.saving) {
        emit('update:open', v);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    Cuando el estado de cuenta traiga este texto, el movimiento
                    se clasifica en el concepto que elijas. Se aplica al
                    procesar la conciliación y nunca toca lo conciliado con el
                    ERP, los traspasos entre cuentas ni lo que se clasificó a
                    mano.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <label class="block space-y-1 text-sm">
                        <span class="font-semibold">Concepto del reporte</span>
                        <Input
                            v-model="draft.name"
                            maxlength="120"
                            placeholder="Ej. Renta de oficinas"
                        />
                        <span class="text-[11px] text-muted-foreground"
                            >Así aparecerá la línea en el Estado de Resultados
                            («Bancos · …»).</span
                        >
                    </label>

                    <label class="block space-y-1 text-sm">
                        <span class="font-semibold">Texto a buscar</span>
                        <Input
                            v-model="draft.match_text"
                            maxlength="200"
                            placeholder="Ej. RENTA OFICINA"
                        />
                        <span
                            class="text-[11px]"
                            :class="
                                textTooShort && draft.match_text
                                    ? 'text-amber-700 dark:text-amber-300'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                textTooShort && draft.match_text
                                    ? `Escribe al menos ${minText} caracteres para no atrapar movimientos de más.`
                                    : 'No importan mayúsculas, acentos ni espacios dobles.'
                            }}
                        </span>
                    </label>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="block space-y-1 text-sm">
                            <span class="font-semibold">Cómo coincide</span>
                            <select
                                v-model="draft.match_mode"
                                class="h-9 w-full rounded-md border bg-background px-2 text-sm"
                            >
                                <option
                                    v-for="o in options?.modes ?? []"
                                    :key="o.key"
                                    :value="o.key"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span class="font-semibold">Prioridad</span>
                            <Input
                                v-model="draft.priority"
                                type="number"
                                min="0"
                                max="1000"
                                step="1"
                            />
                        </label>
                    </div>
                    <p class="-mt-1 text-[11px] text-muted-foreground">
                        Si dos reglas coinciden gana la de prioridad más alta;
                        si empatan, la del texto más largo.
                    </p>

                    <fieldset class="space-y-1 text-sm">
                        <legend class="font-semibold">Buscar en</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <label
                                v-for="f in options?.fields ?? []"
                                :key="f.key"
                                class="flex items-center gap-1.5 text-xs"
                            >
                                <input
                                    v-model="draft.fields"
                                    type="checkbox"
                                    :value="f.key"
                                    class="size-4 rounded border accent-emerald-700"
                                />
                                {{ f.label }}
                            </label>
                        </div>
                        <span
                            v-if="!draft.fields.length"
                            class="text-[11px] text-amber-700 dark:text-amber-300"
                            >Elige al menos un campo.</span
                        >
                    </fieldset>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="block space-y-1 text-sm">
                            <span class="font-semibold">Cuenta</span>
                            <select
                                v-model="draft.account_slot"
                                class="h-9 w-full rounded-md border bg-background px-2 text-sm"
                            >
                                <option
                                    v-for="o in options?.accounts ?? []"
                                    :key="o.key"
                                    :value="o.key"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span class="font-semibold">Movimientos</span>
                            <select
                                v-model="draft.direction"
                                class="h-9 w-full rounded-md border bg-background px-2 text-sm"
                            >
                                <option
                                    v-for="o in options?.directions ?? []"
                                    :key="o.key"
                                    :value="o.key"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <label class="block space-y-1 text-sm">
                        <span class="font-semibold">A dónde va</span>
                        <select
                            v-model="draft.treatment"
                            class="h-9 w-full rounded-md border bg-background px-2 text-sm"
                        >
                            <option value="" disabled>Selecciona…</option>
                            <optgroup
                                v-for="g in groups"
                                :key="g.label"
                                :label="g.label"
                            >
                                <option
                                    v-for="t in g.items"
                                    :key="t.key"
                                    :value="t.key"
                                >
                                    {{ t.label }}
                                </option>
                            </optgroup>
                        </select>
                    </label>
                    <p
                        v-if="chosen"
                        class="rounded-xl bg-muted/50 p-2.5 text-xs leading-relaxed"
                    >
                        {{ chosen.explain }}
                    </p>

                    <label class="block space-y-1 text-sm">
                        <span class="font-semibold">Notas (opcional)</span>
                        <textarea
                            v-model="draft.notes"
                            rows="2"
                            maxlength="2000"
                            placeholder="Ej. Contrato de arrendamiento de la oficina central."
                            class="w-full rounded-xl border bg-background p-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                        />
                    </label>

                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="draft.active"
                            type="checkbox"
                            class="size-4 rounded border accent-emerald-700"
                        />
                        Regla activa
                    </label>
                </div>

                <ConceptRulePreview
                    :preview="preview"
                    :loading="previewLoading"
                    :error="previewError"
                    :period-label="periodLabel"
                    :waiting="textTooShort"
                />
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="saving"
                    @click="close(false)"
                >
                    Cancelar
                </Button>
                <Button
                    :disabled="!valid || saving"
                    class="bg-emerald-700 text-white hover:bg-emerald-800"
                    @click="emit('submit', { ...draft }, rule?.id ?? null)"
                >
                    <Loader2 v-if="saving" class="size-4 animate-spin" />
                    {{ rule ? 'Guardar cambios' : 'Crear regla' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
