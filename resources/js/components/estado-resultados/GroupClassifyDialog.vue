<script setup lang="ts">
/**
 * GroupClassifyDialog — «Clasificar grupo» en 3 pasos (destino → vista previa → confirmar).
 *
 * Lo usa PendingGroupsView. Dos caminos:
 * - Regla del catálogo (recomendado): crea una regla «contiene» con el texto del grupo. Vale
 *   para este y los próximos periodos, queda en la bitácora del catálogo y respeta la
 *   precedencia y protecciones del clasificador (se aplica al procesar la conciliación).
 *   Vista previa = la del catálogo (POST /catalogo-conceptos/vista-previa).
 * - Sólo estos movimientos: clasificación manual del periodo con bitácora
 *   (POST /control-movimientos/grupos/…). El servidor recalcula qué se excluye.
 * Confirmación final con SweetAlert2; botones deshabilitados mientras se guarda.
 *
 * Props: open, group, treatments, canClassify, canCatalog, lockReason, periodId, pending
 * (composable usePendingGroups), canProcess, process.
 * Emits: update:open, done (para recargar), open-catalog (editar la regla en el catálogo).
 */
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    BookMarked,
    CheckCircle2,
    Loader2,
    ShieldCheck,
    Tags,
} from 'lucide-vue-next';
import { computed, ref, toRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { showError, swal } from '@/composables/estado-resultados/erSwal';
import { useConceptCatalog } from '@/composables/estado-resultados/useConceptCatalog';
import type { PendingGroups } from '@/composables/estado-resultados/usePendingGroups';
import { emptyDraft } from './conceptCatalog';
import type { RuleDraft } from './conceptCatalog';
import type { BulkPreview, PendingGroup } from './movementControl';
import { escapeHtml, mxn } from './types';
import type { TreatmentMeta } from './types';

const props = defineProps<{
    open: boolean;
    group: PendingGroup | null;
    treatments: TreatmentMeta[];
    canClassify: boolean;
    canCatalog: boolean;
    lockReason?: string | null;
    periodId: number;
    pending: PendingGroups;
    canProcess: boolean;
    process: () => Promise<void>;
}>();
const emit = defineEmits<{
    'update:open': [v: boolean];
    done: [];
    'open-catalog': [draft: Partial<RuleDraft>];
}>();

// ── Estado (antes de cualquier watch) ─────────────────────────────────
type Method = 'regla' | 'manual';
/** Paso actual: 1 destino, 2 vista previa, 3 confirmar. */
const step = ref<1 | 2 | 3>(1);
const method = ref<Method>('regla');
const treatment = ref('');
const ruleName = ref('');
const ruleText = ref('');
const notes = ref('');
/** Vista previa de la clasificación manual. */
const manualPreview = ref<BulkPreview | null>(null);
const previewLoading = ref(false);
const previewError = ref<string | null>(null);
/** Guardando la regla o la clasificación. */
const saving = ref(false);

/** Catálogo (sólo se usa su vista previa y su alta, que ya deja bitácora y ofrece procesar). */
const catalog = useConceptCatalog({
    periodId: toRef(props, 'periodId'),
    canProcess: () => props.canProcess,
    lockReason: () => props.lockReason ?? null,
    process: () => props.process(),
});

const assignable = computed(() => props.treatments.filter((t) => t.assignable));
const treatmentGroups = computed(() => [
    {
        label: 'Afectan el Estado de Resultados',
        items: assignable.value.filter((t) => t.group === 'er'),
    },
    {
        label: 'Fuera del Estado de Resultados',
        items: assignable.value.filter((t) => t.group === 'fuera'),
    },
]);
const chosen = computed(
    () => assignable.value.find((t) => t.key === treatment.value) ?? null,
);
/** Huellas del grupo (para cruzar con la vista previa de la regla). */
const groupFps = computed(() => new Set(props.group?.fingerprints ?? []));
const locked = computed(() => !!props.lockReason);

/** Borrador de regla del catálogo armado desde el grupo. */
const draft = computed<RuleDraft>(() =>
    emptyDraft({
        name: ruleName.value.trim(),
        match_text: ruleText.value.trim(),
        match_mode: 'contiene',
        fields: ['concept'],
        direction: props.group?.direction ?? 'cualquiera',
        treatment: treatment.value,
        notes: notes.value.trim()
            ? notes.value.trim()
            : `Creada desde «Clasificar grupo» (${props.group?.count ?? 0} pendientes de «${props.group?.label ?? ''}»).`,
    }),
);

/** Resumen de la vista previa de la regla: del grupo, extra del periodo y bloqueados por motivo. */
const ruleSummary = computed(() => {
    const p = catalog.preview.value;

    if (!p) {
        return null;
    }

    const applying = p.items.filter((i) => i.applies);
    const inGroup = applying.filter((i) =>
        groupFps.value.has(i.fingerprint),
    ).length;
    const reasons = new Map<string, number>();

    for (const i of p.items) {
        if (!i.applies && i.blocked_reason) {
            reasons.set(
                i.blocked_reason,
                (reasons.get(i.blocked_reason) ?? 0) + 1,
            );
        }
    }

    return {
        total: p.total,
        applies: p.applies,
        blocked: p.blocked,
        amount: p.amount,
        inGroup,
        extra: p.applies - inGroup,
        missing: Math.max(0, (props.group?.count ?? 0) - inGroup),
        reasons: [...reasons.entries()].map(([label, count]) => ({
            label,
            count,
        })),
        periodLocked: !!(p as { period_locked?: boolean }).period_locked,
        truncated: p.truncated,
    };
});

/** Paso 1 completo. */
const step1Ok = computed(() => {
    if (!chosen.value) {
        return false;
    }

    return method.value === 'regla'
        ? ruleName.value.trim().length >= 3 && ruleText.value.trim().length >= 3
        : notes.value.trim().length >= 3;
});
/** Cuántos cambiarían (según el camino elegido). */
const willChange = computed(() =>
    method.value === 'regla'
        ? (ruleSummary.value?.applies ?? 0)
        : (manualPreview.value?.applies ?? 0),
);
/** Se puede confirmar. */
const canConfirm = computed(() =>
    method.value === 'regla'
        ? props.canCatalog && !!ruleSummary.value
        : !locked.value && willChange.value > 0,
);

const STEPS = [
    { n: 1, label: 'Destino' },
    { n: 2, label: 'Vista previa' },
    { n: 3, label: 'Confirmar' },
] as const;

/** Al abrir: reinicia y precarga con el grupo (sugerencia del sistema y texto de regla). */
watch(
    () => props.open,
    (open) => {
        if (!open || !props.group) {
            return;
        }

        const g = props.group;
        step.value = 1;
        method.value = props.canCatalog ? 'regla' : 'manual';
        treatment.value = g.suggested?.treatment ?? '';
        ruleName.value = g.rule_name;
        ruleText.value = g.rule_text;
        notes.value = '';
        manualPreview.value = null;
        previewError.value = null;
        catalog.clearPreview();
    },
    { immediate: true },
);

/** Paso 2: pide la vista previa del camino elegido. */
async function goPreview(): Promise<void> {
    if (!props.group || !step1Ok.value) {
        return;
    }

    step.value = 2;
    previewError.value = null;

    if (method.value === 'regla') {
        await catalog.runPreview(draft.value, null);
        previewError.value = catalog.previewError.value;

        return;
    }

    previewLoading.value = true;

    try {
        manualPreview.value = await props.pending.preview(
            props.group.fingerprints,
            treatment.value,
        );
    } catch (e) {
        previewError.value =
            e instanceof Error
                ? e.message
                : 'No se pudo calcular la vista previa.';
    } finally {
        previewLoading.value = false;
    }
}

/** Paso 3 → confirmación SweetAlert2 → guardar (regla o clasificación manual). */
async function confirmAndSave(): Promise<void> {
    if (saving.value || !props.group || !canConfirm.value) {
        return;
    }

    const label = chosen.value?.label ?? treatment.value;
    const ok = await swal.fire({
        icon: 'question',
        title:
            method.value === 'regla'
                ? '¿Crear la regla del catálogo?'
                : `¿Clasificar ${willChange.value} movimiento(s)?`,
        html:
            method.value === 'regla'
                ? `<p>«${escapeHtml(draft.value.match_text)}» → <b>${escapeHtml(label)}</b></p><p style="margin-top:6px;font-size:13px">Vale para todos los periodos y se aplica al procesar la conciliación. Puedes desactivarla en el catálogo.</p>`
                : `<p>Destino: <b>${escapeHtml(label)}</b> · ${escapeHtml(mxn(manualPreview.value?.amount ?? 0))}</p><p style="margin-top:6px;font-size:13px">Queda en la bitácora con tu usuario y motivo. Se puede deshacer.</p>`,
        showCancelButton: true,
        confirmButtonText:
            method.value === 'regla' ? 'Crear regla' : 'Clasificar',
        cancelButtonText: 'Volver',
    });

    if (!ok.isConfirmed) {
        return;
    }

    saving.value = true;

    try {
        if (method.value === 'regla') {
            // save() ya avisa el resultado, deja bitácora y ofrece procesar la conciliación.
            if (await catalog.save(draft.value, null)) {
                emit('update:open', false);
                emit('done');
            }

            return;
        }

        const fps = props.group.fingerprints;
        const res = await props.pending.apply(
            fps,
            treatment.value,
            notes.value.trim(),
        );
        emit('update:open', false);
        emit('done');
        const after = await swal.fire({
            icon: 'success',
            title: res.message,
            showDenyButton: res.count > 0,
            denyButtonText: 'Deshacer',
            confirmButtonText: 'Listo',
        });

        if (after.isDenied) {
            try {
                const undo = await props.pending.revert(
                    fps,
                    `Deshizo «Clasificar grupo» de «${props.group.label}».`,
                );
                emit('done');
                await swal.fire({ icon: 'success', title: undo.message });
            } catch (e) {
                await showError(e, 'No se pudo deshacer');
            }
        }
    } catch (e) {
        await showError(e, 'No se guardó la clasificación');
    } finally {
        saving.value = false;
    }
}

/** Abre el alta completa en el catálogo (campos avanzados: cuenta, prioridad, campos). */
function openInCatalog(): void {
    emit('open-catalog', { ...draft.value });
    emit('update:open', false);
}

function close(v: boolean): void {
    if (!saving.value && !props.pending.busy.value) {
        emit('update:open', v);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Tags class="size-5 text-emerald-600" />
                    Clasificar grupo
                </DialogTitle>
                <DialogDescription v-if="group">
                    <span class="font-semibold text-foreground">{{
                        group.label
                    }}</span>
                    · {{ group.count }} pendiente(s) · {{ mxn(group.total) }}
                </DialogDescription>
            </DialogHeader>

            <!-- Pasos -->
            <ol class="flex items-center gap-1.5 text-xs" aria-label="Pasos">
                <li
                    v-for="s in STEPS"
                    :key="s.n"
                    class="flex flex-1 items-center gap-1.5"
                >
                    <span
                        class="grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-bold"
                        :class="
                            step > s.n
                                ? 'bg-emerald-600 text-white'
                                : step === s.n
                                  ? 'bg-foreground text-background'
                                  : 'bg-muted text-muted-foreground'
                        "
                        :aria-current="step === s.n ? 'step' : undefined"
                    >
                        <CheckCircle2 v-if="step > s.n" class="size-3.5" />
                        <template v-else>{{ s.n }}</template>
                    </span>
                    <span
                        class="font-semibold"
                        :class="step === s.n ? '' : 'text-muted-foreground'"
                        >{{ s.label }}</span
                    >
                    <span v-if="s.n < 3" class="h-px flex-1 bg-border" />
                </li>
            </ol>

            <p
                v-if="locked"
                class="flex items-center gap-2 rounded-xl bg-slate-100 p-2.5 text-xs dark:bg-slate-800"
            >
                <ShieldCheck class="size-4 shrink-0" /> {{ lockReason }}
            </p>

            <!-- Paso 1: destino -->
            <div v-if="step === 1" class="space-y-3">
                <div
                    class="grid gap-2 sm:grid-cols-2"
                    role="radiogroup"
                    aria-label="Cómo clasificar"
                >
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="method === 'regla'"
                        :disabled="!canCatalog"
                        class="rounded-xl border p-3 text-left transition disabled:cursor-not-allowed disabled:opacity-50"
                        :class="
                            method === 'regla'
                                ? 'border-emerald-600 ring-2 ring-emerald-600/20'
                                : 'hover:bg-muted/50'
                        "
                        @click="method = 'regla'"
                    >
                        <span
                            class="flex items-center gap-1.5 text-sm font-bold"
                        >
                            <BookMarked class="size-4 text-emerald-600" /> Regla
                            del catálogo
                            <span
                                class="rounded-full bg-emerald-100 px-1.5 text-[10px] text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                                >recomendado</span
                            >
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground"
                            >Este y próximos periodos</span
                        >
                    </button>
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="method === 'manual'"
                        :disabled="!canClassify || locked"
                        class="rounded-xl border p-3 text-left transition disabled:cursor-not-allowed disabled:opacity-50"
                        :class="
                            method === 'manual'
                                ? 'border-emerald-600 ring-2 ring-emerald-600/20'
                                : 'hover:bg-muted/50'
                        "
                        @click="method = 'manual'"
                    >
                        <span
                            class="flex items-center gap-1.5 text-sm font-bold"
                        >
                            <Tags class="size-4 text-sky-600" /> Sólo estos
                            {{ group?.count }}
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground"
                            >Manual, sólo este periodo</span
                        >
                    </button>
                </div>

                <label class="block space-y-1 text-sm">
                    <span class="font-semibold">Destino</span>
                    <select
                        v-model="treatment"
                        class="h-10 w-full rounded-xl border bg-background px-3 text-sm"
                    >
                        <option value="" disabled>Selecciona…</option>
                        <optgroup
                            v-for="g in treatmentGroups"
                            :key="g.label"
                            :label="g.label"
                        >
                            <option
                                v-for="t in g.items"
                                :key="t.key"
                                :value="t.key"
                            >
                                {{ t.label
                                }}{{
                                    group?.suggested?.treatment === t.key
                                        ? ' (sugerido)'
                                        : ''
                                }}
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

                <template v-if="method === 'regla'">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block space-y-1 text-sm">
                            <span class="font-semibold"
                                >Concepto del reporte</span
                            >
                            <Input v-model="ruleName" maxlength="120" />
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span class="font-semibold"
                                >El concepto contiene</span
                            >
                            <Input v-model="ruleText" maxlength="200" />
                            <span
                                class="text-[11px]"
                                :class="
                                    group && group.rule_covers < group.count
                                        ? 'text-amber-700 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                                >Atrapa {{ group?.rule_covers }} de
                                {{ group?.count }} del grupo</span
                            >
                        </label>
                    </div>
                    <button
                        type="button"
                        class="text-xs font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
                        @click="openInCatalog"
                    >
                        Opciones avanzadas en el catálogo
                    </button>
                </template>

                <label class="block space-y-1 text-sm">
                    <span class="font-semibold"
                        >Motivo
                        {{
                            method === 'manual' ? '(obligatorio)' : '(opcional)'
                        }}</span
                    >
                    <textarea
                        v-model="notes"
                        rows="2"
                        maxlength="2000"
                        placeholder="Ej. Aportaciones de sucursales al crédito LEROM"
                        class="w-full rounded-xl border bg-background p-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                    />
                </label>
            </div>

            <!-- Paso 2: vista previa -->
            <div v-else-if="step === 2" class="space-y-3">
                <div
                    v-if="previewLoading || catalog.previewLoading.value"
                    class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" /> Calculando…
                </div>
                <p
                    v-else-if="previewError"
                    class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                >
                    {{ previewError }}
                </p>

                <!-- Manual -->
                <template v-else-if="method === 'manual' && manualPreview">
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div
                            class="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-950/30"
                        >
                            <p
                                class="text-2xl font-extrabold text-emerald-700 tabular-nums dark:text-emerald-300"
                            >
                                {{ manualPreview.applies }}
                            </p>
                            <p
                                class="text-[11px] font-semibold text-emerald-800 dark:text-emerald-300"
                            >
                                cambian
                            </p>
                        </div>
                        <div class="rounded-xl bg-muted p-3">
                            <p class="text-2xl font-extrabold tabular-nums">
                                {{ manualPreview.excluded }}
                            </p>
                            <p
                                class="text-[11px] font-semibold text-muted-foreground"
                            >
                                excluidos
                            </p>
                        </div>
                        <div
                            class="rounded-xl bg-sky-50 p-3 dark:bg-sky-950/30"
                        >
                            <p
                                class="text-base font-extrabold text-sky-800 tabular-nums sm:text-lg dark:text-sky-300"
                            >
                                {{ mxn(manualPreview.amount) }}
                            </p>
                            <p
                                class="text-[11px] font-semibold text-sky-800 dark:text-sky-300"
                            >
                                importe
                            </p>
                        </div>
                    </div>
                    <ul v-if="manualPreview.reasons.length" class="space-y-1">
                        <li
                            v-for="r in manualPreview.reasons"
                            :key="r.key"
                            class="flex items-center justify-between gap-2 rounded-lg border px-2.5 py-1.5 text-xs"
                        >
                            <span class="flex min-w-0 items-center gap-1.5"
                                ><ShieldCheck
                                    class="size-3.5 shrink-0 text-slate-500"
                                />
                                {{ r.label }}</span
                            >
                            <span class="shrink-0 font-bold tabular-nums"
                                >{{ r.count }} · {{ mxn(r.amount) }}</span
                            >
                        </li>
                    </ul>
                    <p
                        v-for="w in manualPreview.warnings"
                        :key="w"
                        class="flex items-start gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400"
                    >
                        <AlertTriangle class="mt-px size-3.5 shrink-0" />
                        {{ w }}
                    </p>
                </template>

                <!-- Regla del catálogo -->
                <template v-else-if="method === 'regla' && ruleSummary">
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div
                            class="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-950/30"
                        >
                            <p
                                class="text-2xl font-extrabold text-emerald-700 tabular-nums dark:text-emerald-300"
                            >
                                {{ ruleSummary.applies }}
                            </p>
                            <p
                                class="text-[11px] font-semibold text-emerald-800 dark:text-emerald-300"
                            >
                                se clasifican
                            </p>
                        </div>
                        <div class="rounded-xl bg-muted p-3">
                            <p class="text-2xl font-extrabold tabular-nums">
                                {{ ruleSummary.blocked }}
                            </p>
                            <p
                                class="text-[11px] font-semibold text-muted-foreground"
                            >
                                protegidos
                            </p>
                        </div>
                        <div
                            class="rounded-xl bg-sky-50 p-3 dark:bg-sky-950/30"
                        >
                            <p
                                class="text-base font-extrabold text-sky-800 tabular-nums sm:text-lg dark:text-sky-300"
                            >
                                {{ mxn(ruleSummary.amount) }}
                            </p>
                            <p
                                class="text-[11px] font-semibold text-sky-800 dark:text-sky-300"
                            >
                                importe
                            </p>
                        </div>
                    </div>
                    <div
                        class="flex flex-wrap gap-1.5 text-[11px] font-semibold"
                    >
                        <span
                            class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                            >{{ ruleSummary.inGroup }} del grupo</span
                        >
                        <span
                            v-if="ruleSummary.extra"
                            class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                            >+{{ ruleSummary.extra }} fuera del grupo</span
                        >
                        <span
                            v-if="ruleSummary.missing"
                            class="rounded-full bg-slate-200 px-2 py-0.5 dark:bg-slate-800"
                            >{{ ruleSummary.missing }} del grupo no entran</span
                        >
                    </div>
                    <ul v-if="ruleSummary.reasons.length" class="space-y-1">
                        <li
                            v-for="r in ruleSummary.reasons"
                            :key="r.label"
                            class="flex items-center justify-between gap-2 rounded-lg border px-2.5 py-1.5 text-xs"
                        >
                            <span class="flex min-w-0 items-center gap-1.5"
                                ><ShieldCheck
                                    class="size-3.5 shrink-0 text-slate-500"
                                />
                                {{ r.label }}</span
                            >
                            <span class="shrink-0 font-bold tabular-nums">{{
                                r.count
                            }}</span>
                        </li>
                    </ul>
                    <p
                        class="flex items-start gap-1.5 text-xs text-muted-foreground"
                    >
                        <BookMarked class="mt-px size-3.5 shrink-0" /> Vale para
                        todos los periodos · se aplica al procesar la
                        conciliación.
                    </p>
                    <p
                        v-if="ruleSummary.periodLocked"
                        class="flex items-start gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400"
                    >
                        <AlertTriangle class="mt-px size-3.5 shrink-0" /> Este
                        periodo está aprobado: aquí no cambia hasta abrir una
                        nueva versión.
                    </p>
                </template>
            </div>

            <!-- Paso 3: confirmar -->
            <div v-else class="space-y-2 rounded-xl border p-3 text-sm">
                <p class="flex items-center gap-2 font-bold">
                    <component
                        :is="method === 'regla' ? BookMarked : Tags"
                        class="size-4"
                    />
                    {{
                        method === 'regla'
                            ? 'Regla del catálogo'
                            : 'Clasificación manual'
                    }}
                </p>
                <p v-if="method === 'regla'">
                    «{{ draft.match_text }}» → <b>{{ chosen?.label }}</b> ({{
                        draft.name
                    }})
                </p>
                <p>
                    <b class="tabular-nums">{{ willChange }}</b> movimiento(s) ·
                    <b class="tabular-nums">{{
                        mxn(
                            method === 'regla'
                                ? ruleSummary?.amount
                                : manualPreview?.amount,
                        )
                    }}</b>
                    → {{ chosen?.label }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Queda en la bitácora con tu usuario y fecha. Se puede
                    revertir.
                </p>
            </div>

            <!-- Navegación -->
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-t pt-3"
            >
                <Button
                    v-if="step > 1"
                    variant="outline"
                    :disabled="saving"
                    @click="step = (step - 1) as 1 | 2"
                >
                    <ArrowLeft class="size-4" /> Atrás
                </Button>
                <Button
                    v-else
                    variant="outline"
                    :disabled="saving"
                    @click="close(false)"
                    >Cancelar</Button
                >

                <Button
                    v-if="step === 1"
                    :disabled="!step1Ok"
                    class="bg-emerald-700 text-white hover:bg-emerald-800"
                    @click="goPreview"
                >
                    Vista previa <ArrowRight class="size-4" />
                </Button>
                <Button
                    v-else-if="step === 2"
                    :disabled="
                        !canConfirm ||
                        previewLoading ||
                        catalog.previewLoading.value
                    "
                    class="bg-emerald-700 text-white hover:bg-emerald-800"
                    @click="step = 3"
                >
                    Continuar <ArrowRight class="size-4" />
                </Button>
                <Button
                    v-else
                    :disabled="
                        !canConfirm ||
                        saving ||
                        pending.busy.value ||
                        catalog.saving.value
                    "
                    class="bg-emerald-700 text-white hover:bg-emerald-800"
                    @click="confirmAndSave"
                >
                    <Loader2
                        v-if="
                            saving || pending.busy.value || catalog.saving.value
                        "
                        class="size-4 animate-spin"
                    />
                    {{
                        method === 'regla'
                            ? 'Crear regla'
                            : `Clasificar ${willChange}`
                    }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
