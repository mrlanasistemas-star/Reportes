<script setup lang="ts">
/**
 * AssignBranchModal — modal "Asignar sucursal" para fijar manualmente la
 * sucursal de un colaborador en el periodo, con buscador y notas opcionales.
 *
 * Se usa en pages/AsignacionSucursal/Index. Va sobre el Dialog de shadcn/reka-ui
 * (role="dialog"), con
 * foco atrapado, Escape y clic fuera cierran, botón cerrar con etiqueta en
 * español y cada campo con su <label for>. Misma API (open/close/save) que antes.
 *
 * Props:
 * - open: si el modal está abierto (al abrirse se reinician los campos).
 * - saving: hay un guardado en curso (bloquea el cierre y los botones).
 * - employeeName: nombre del colaborador (título del modal).
 * - periodLabel: nombre del periodo (opcional, se muestra arriba del formulario).
 * - branches: sucursales disponibles.
 * - initialBranchId / initialNotes: valores con los que se precarga al abrir.
 *
 * Emits:
 * - close: se pidió cerrar (Cancelar, Escape o clic fuera).
 * - save: sucursal elegida y notas para guardar la asignación.
 */
import { PenSquare, Search } from 'lucide-vue-next';
import { computed, ref, useId, watch } from 'vue';
import SelectField from '@/components/forms/SelectField.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Branch } from '@/types/asignaciones';

const props = defineProps<{
    open: boolean;
    saving: boolean;
    employeeName: string;
    periodLabel?: string | null;
    branches: Branch[];
    initialBranchId?: string;
    initialNotes?: string;
}>();

const emit = defineEmits<{
    close: [];
    save: [payload: { branchId: string; notes: string }];
}>();

/** Id único de la instancia, para enlazar cada <label for> con su campo. */
const uid = useId();
const searchId = `abm-search-${uid}`;
const branchFieldId = `abm-branch-${uid}`;
const notesId = `abm-notes-${uid}`;

/** Texto del buscador de sucursales. */
const branchQuery = ref('');
/** Sucursal seleccionada. */
const branchId = ref('');
/** Notas opcionales del ajuste manual. */
const notes = ref('');
/** Mensaje de validación del selector de sucursal. */
const branchError = ref<string | null>(null);

/** Al abrir el modal reinicia el buscador y precarga sucursal y notas. */
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        branchQuery.value = '';
        branchId.value = props.initialBranchId ?? '';
        notes.value = props.initialNotes ?? '';
        branchError.value = null;
    },
);

/** Limpia el error en cuanto se elige una sucursal. */
watch(branchId, (value) => {
    if (value) {
        branchError.value = null;
    }
});

/** Sucursales que coinciden con el buscador (sin distinguir mayúsculas). */
const modalBranches = computed(() => {
    const q = branchQuery.value.trim().toLowerCase();

    if (!q) {
        return props.branches;
    }

    return props.branches.filter((b) => b.name.toLowerCase().includes(q));
});

/**
 * Opciones del selector a partir de las sucursales filtradas. El id va como
 * texto porque `branchId` y el evento `save` trabajan con texto (SelectField
 * devuelve el tipo original de la opción).
 */
const modalBranchOptions = computed(() =>
    modalBranches.value.map((b) => ({ value: String(b.id), label: b.name })),
);

/**
 * Atiende el cambio de apertura del Dialog: solo emite `close` si no se está guardando.
 * @param value nuevo estado de apertura
 */
function onOpenChange(value: boolean) {
    // Mientras guarda no se cierra (evita perder la respuesta del servidor).
    if (!value && !props.saving) {
        emit('close');
    }
}

/** Valida que haya sucursal y emite `save` con la sucursal y las notas. */
function save() {
    if (!branchId.value) {
        branchError.value =
            'Selecciona una sucursal para guardar la asignación.';

        return;
    }

    emit('save', { branchId: branchId.value, notes: notes.value });
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="max-h-[90vh] gap-0 overflow-y-auto rounded-2xl p-0 sm:max-w-md"
        >
            <!-- Encabezado -->
            <div class="border-b px-6 py-5 pr-12">
                <p class="app-kicker text-brand-accent">Asignar sucursal</p>
                <DialogTitle
                    class="mt-1 truncate text-xl font-bold text-foreground"
                    :title="employeeName"
                    >{{ employeeName }}</DialogTitle
                >
                <DialogDescription class="mt-1 text-sm text-muted-foreground">
                    Elige la sucursal a la que pertenece este colaborador.
                </DialogDescription>
            </div>

            <!-- Cuerpo -->
            <form class="space-y-4 p-6" novalidate @submit.prevent="save">
                <div
                    v-if="periodLabel"
                    class="rounded-2xl border bg-muted px-4 py-3"
                >
                    <p class="text-xs text-muted-foreground">Periodo</p>
                    <p class="mt-0.5 text-sm font-bold text-foreground">
                        {{ periodLabel }}
                    </p>
                </div>

                <div class="space-y-2">
                    <label
                        :for="searchId"
                        class="text-sm font-semibold text-foreground"
                        >Buscar sucursal</label
                    >
                    <div class="relative">
                        <Search
                            aria-hidden="true"
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            :id="searchId"
                            v-model="branchQuery"
                            type="search"
                            autocomplete="off"
                            @keydown.enter.prevent
                            class="app-input h-10 pl-10"
                            placeholder="Filtrar sucursales..."
                        />
                    </div>
                    <p
                        v-if="!modalBranches.length"
                        class="text-xs text-danger"
                        role="status"
                    >
                        Sin resultados para "{{ branchQuery }}".
                    </p>
                </div>

                <SelectField
                    :id="branchFieldId"
                    v-model="branchId"
                    label="Sucursal"
                    :options="modalBranchOptions"
                    placeholder="— Selecciona sucursal —"
                    :error="branchError"
                />

                <div class="space-y-2">
                    <label
                        :for="notesId"
                        class="text-sm font-semibold text-foreground"
                    >
                        Notas
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        >
                    </label>
                    <textarea
                        :id="notesId"
                        v-model="notes"
                        class="app-input min-h-[80px] resize-none py-2"
                        placeholder="Razón del ajuste manual, aclaración, etc."
                    />
                </div>

                <!-- Pie -->
                <div
                    class="-mx-6 -mb-6 flex items-center justify-end gap-3 border-t px-6 py-4"
                >
                    <button
                        type="button"
                        class="app-btn app-btn-secondary h-10"
                        :disabled="saving"
                        @click="onOpenChange(false)"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="app-btn app-btn-primary h-10 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="saving"
                        :aria-busy="saving"
                    >
                        <PenSquare aria-hidden="true" class="size-4" />
                        {{ saving ? 'Guardando…' : 'Guardar asignación' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
