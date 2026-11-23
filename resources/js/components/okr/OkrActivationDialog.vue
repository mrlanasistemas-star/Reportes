<script setup lang="ts">
/**
 * OkrActivationDialog — diálogo "Activar OKR" con revisión previa
 * (15-oct-2026): antes de activar muestra, por cada OKR (sucursal y cada
 * gestor en borrador), si está listo o qué le falta. Nada se activa "a medias"
 * sin que el usuario lo vea; el que no esté listo se queda en borrador con su
 * motivo. Sin pantallas de carga globales: spinner local. Se usa en
 * pages/Okr/Show.
 *
 * Props:
 * - objectiveId: id del OKR a activar.
 *
 * v-model:
 * - open: abre/cierra el diálogo (al abrir consulta /activation-check).
 *
 * Emits:
 * - configure(objectiveId): abrir la configuración de KR de ese OKR.
 */
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Info,
    Loader2,
    Rocket,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { okrRequest } from '@/lib/okrHttp';

/** Pendiente de un OKR con la acción opcional que lo resuelve. */
type ReadinessItem = {
    message: string;
    action: string | null;
    action_label: string | null;
};
/** Estado de preparación de un OKR para activarse. */
type Readiness = {
    id: number;
    label: string;
    scope: string;
    status: string;
    ready: boolean;
    issues: string[];
    items?: ReadinessItem[];
    notes: string[];
};

const props = defineProps<{ objectiveId: number }>();
const emit = defineEmits<{ configure: [objectiveId: number] }>();

/**
 * Ejecuta la acción que resuelve un pendiente: Key Results/línea base/meta se
 * configuran en el mismo OKR; vigencia o sucursal se concilian en Colaboradores.
 * @param objectiveId OKR al que pertenece el pendiente.
 * @param action clave de la acción.
 */
function runAction(objectiveId: number, action: string | null) {
    if (action === 'configure_kr') {
        emit('configure', objectiveId);
    } else if (action === 'employees' || action === 'reconcile_branch') {
        router.visit('/asignaciones-empleado-sucursal');
    }
}

/**
 * Pendientes del OKR con su acción; si el servidor no manda items, se derivan de issues.
 * @param item OKR revisado.
 */
function itemsOf(item: Readiness): ReadinessItem[] {
    return (
        item.items ??
        item.issues.map((message) => ({
            message,
            action: null,
            action_label: null,
        }))
    );
}
const open = defineModel<boolean>('open', { default: false });

/** true mientras se consulta la revisión previa. */
const loading = ref(false);
/** Qué activación está en curso: todo, solo este OKR o ninguna. */
const submitting = ref<'all' | 'single' | null>(null);
/** Revisión del OKR principal. */
const main = ref<Readiness | null>(null);
/** Revisión de los OKR individuales ligados. */
const children = ref<Readiness[]>([]);
/** Mensaje de error de la consulta. */
const error = ref<string | null>(null);

/** Consulta qué está listo para activarse en /okr/{id}/activation-check. */
async function load() {
    loading.value = true;
    error.value = null;
    const res = await okrRequest<{
        objective: Readiness;
        children: Readiness[];
    }>(`/okr/${props.objectiveId}/activation-check`);
    loading.value = false;

    if (!res.ok) {
        error.value = res.message;

        return;
    }

    main.value = res.data.objective;
    children.value = res.data.children;
}

/** Al abrir el diálogo carga la revisión. */
watch(open, (v) => v && load());

/** OKR individuales que siguen en borrador. */
const draftChildren = computed(() =>
    children.value.filter((c) => c.status === 'draft'),
);
/** Individuales en borrador listos para activarse. */
const readyChildren = computed(() =>
    draftChildren.value.filter((c) => c.ready),
);
/** Individuales en borrador a los que aún les falta algo. */
const pendingChildren = computed(() =>
    draftChildren.value.filter((c) => !c.ready),
);

/**
 * Activa el OKR (y, si se pide, sus individuales listos). Si el servidor lo
 * rechaza, avisa el motivo, ofrece la acción que lo resuelve y recarga la revisión.
 * @param includeChildren incluir los OKR individuales listos.
 */
function activate(includeChildren: boolean) {
    submitting.value = includeChildren ? 'all' : 'single';
    router.post(
        `/okr/${props.objectiveId}/activate`,
        { include_children: includeChildren },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                const action = errors.activate_action ?? null;
                const message = errors.activate ?? Object.values(errors)[0];
                Swal.fire({
                    icon: 'warning',
                    title: 'El OKR sigue en borrador',
                    text: String(
                        message ??
                            'Revisa los pendientes indicados en esta ventana.',
                    ),
                    showCancelButton: !!action,
                    confirmButtonText:
                        errors.activate_action_label ?? 'Entendido',
                    cancelButtonText: 'Cerrar',
                }).then((r) => {
                    if (r.isConfirmed && action) {
                        runAction(props.objectiveId, action);
                    }
                });
                load();
            },
            onFinish: () => {
                submitting.value = null;
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] w-[96vw] max-w-2xl overflow-y-auto sm:max-w-2xl"
        >
            <DialogTitle class="flex items-center gap-2 text-base font-bold"
                ><Rocket class="size-5 text-primary" /> Activar OKR</DialogTitle
            >
            <p class="-mt-1 text-xs text-muted-foreground">
                Al activar se congela la línea base de cada OKR con Reportería.
                Revisa qué está listo antes de continuar.
            </p>

            <div v-if="loading" class="space-y-2 py-2">
                <div
                    v-for="n in 3"
                    :key="n"
                    class="app-skeleton h-14 rounded-xl bg-muted"
                />
            </div>
            <div
                v-else-if="error"
                class="space-y-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
            >
                <p>{{ error }}</p>
                <Button
                    size="sm"
                    variant="outline"
                    class="rounded-lg"
                    @click="load"
                    >Reintentar</Button
                >
            </div>

            <div v-else-if="main" class="space-y-3">
                <!-- Un bloque por OKR revisado -->
                <div
                    v-for="item in [main, ...draftChildren]"
                    :key="item.id"
                    class="rounded-xl border p-3 transition"
                    :class="
                        item.ready
                            ? 'border-emerald-500/30 bg-emerald-500/5'
                            : 'border-amber-500/40 bg-amber-500/5'
                    "
                >
                    <div class="flex items-center justify-between gap-2">
                        <p
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <CheckCircle2
                                v-if="item.ready"
                                class="size-4 text-emerald-600"
                            /><AlertTriangle
                                v-else
                                class="size-4 text-amber-600"
                            />
                            {{ item.label }}
                            <span
                                class="text-[11px] font-normal text-muted-foreground uppercase"
                                >{{
                                    item.scope === 'branch'
                                        ? 'Sucursal'
                                        : 'Individual'
                                }}</span
                            >
                        </p>
                        <span
                            class="text-[11px] font-bold"
                            :class="
                                item.ready
                                    ? 'text-emerald-700 dark:text-emerald-300'
                                    : 'text-amber-700 dark:text-amber-300'
                            "
                            >{{
                                item.ready
                                    ? 'Listo'
                                    : item.status === 'draft'
                                      ? 'Pendiente'
                                      : 'No aplica'
                            }}</span
                        >
                    </div>
                    <ul
                        v-if="item.issues.length"
                        class="mt-1.5 space-y-1 pl-6 text-xs text-amber-800 dark:text-amber-200"
                    >
                        <li
                            v-for="issue in itemsOf(item)"
                            :key="issue.message"
                            class="list-disc"
                        >
                            {{ issue.message }}
                            <button
                                v-if="issue.action"
                                type="button"
                                class="ml-1 font-bold text-primary hover:underline"
                                @click="runAction(item.id, issue.action)"
                            >
                                {{ issue.action_label }} →
                            </button>
                        </li>
                    </ul>
                    <ul
                        v-if="item.notes.length"
                        class="mt-1 space-y-0.5 pl-6 text-[11px] text-muted-foreground"
                    >
                        <li
                            v-for="note in item.notes"
                            :key="note"
                            class="list-disc"
                        >
                            {{ note }}
                        </li>
                    </ul>
                </div>

                <p
                    v-if="pendingChildren.length"
                    class="flex items-start gap-2 rounded-xl bg-muted/40 p-3 text-xs text-muted-foreground"
                >
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <span
                        >{{ pendingChildren.length }} OKR individual(es)
                        seguirán en <strong>borrador</strong> hasta completar lo
                        indicado. Puedes activarlos después desde su
                        detalle.</span
                    >
                </p>

                <!-- Botones de activación -->
                <div
                    class="flex flex-wrap justify-end gap-2 border-t border-border pt-3"
                >
                    <Button
                        variant="ghost"
                        class="rounded-xl"
                        :disabled="!!submitting"
                        @click="open = false"
                        >Cancelar</Button
                    >
                    <Button
                        v-if="draftChildren.length"
                        variant="outline"
                        class="gap-1.5 rounded-xl"
                        :disabled="!main.ready || !!submitting"
                        @click="activate(false)"
                    >
                        <Loader2
                            v-if="submitting === 'single'"
                            class="size-4 animate-spin"
                        />
                        Solo
                        {{
                            main.scope === 'branch' ? 'la sucursal' : 'este OKR'
                        }}
                    </Button>
                    <Button
                        class="gap-1.5 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500"
                        :disabled="!main.ready || !!submitting"
                        @click="activate(draftChildren.length > 0)"
                    >
                        <Loader2
                            v-if="submitting === 'all'"
                            class="size-4 animate-spin"
                        /><Rocket v-else class="size-4" />
                        {{
                            submitting === 'all'
                                ? 'Activando…'
                                : readyChildren.length
                                  ? `Activar sucursal + ${readyChildren.length} listo(s)`
                                  : 'Activar'
                        }}
                    </Button>
                </div>
                <p
                    v-if="!main.ready"
                    class="text-right text-[11px] font-semibold text-amber-700 dark:text-amber-300"
                >
                    Corrige los pendientes de "{{ main.label }}" para poder
                    activarlo.
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
