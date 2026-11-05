<script setup lang="ts">
import { Filter, X } from 'lucide-vue-next';
import SelectField from '@/components/forms/SelectField.vue';

type Opcion = { value: string; label: string };
type Clave = 'sucursal' | 'oficina' | 'promotor';

defineProps<{
    opcionesSucursal: Opcion[];
    opcionesOficina: Opcion[];
    opcionesPromotor: Opcion[];
    activos: Array<{ clave: Clave; titulo: string; valor: string }>;
    disabled?: boolean;
}>();

defineEmits<{ quitar: [Clave]; limpiar: [] }>();

const sucursal = defineModel<string>('sucursal', { required: true });
const oficina = defineModel<string>('oficina', { required: true });
const promotor = defineModel<string>('promotor', { required: true });
</script>

<template>
    <div class="rounded-2xl border bg-card p-4 shadow-sm md:p-5">
        <div
            class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between"
        >
            <div class="min-w-0 flex-1 space-y-3">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <Filter
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    Filtrar resultado
                    <span class="font-normal text-muted-foreground"
                        >· Sucursal → Ruta → Promotor</span
                    >
                </p>
                <div class="grid gap-3 sm:grid-cols-3 xl:max-w-3xl">
                    <SelectField
                        v-model="sucursal"
                        label="Sucursal"
                        :options="opcionesSucursal"
                        :disabled="disabled"
                    />
                    <SelectField
                        v-model="oficina"
                        label="Ruta"
                        :options="opcionesOficina"
                        :disabled="disabled"
                    />
                    <SelectField
                        v-model="promotor"
                        label="Promotor"
                        :options="opcionesPromotor"
                        :disabled="disabled"
                    />
                </div>
            </div>
            <div v-if="$slots.acciones" class="flex flex-wrap gap-2">
                <slot name="acciones" />
            </div>
        </div>

        <div
            v-if="activos.length"
            class="mt-4 flex flex-wrap items-center gap-2 border-t pt-3"
        >
            <span class="text-xs text-muted-foreground">Vista filtrada:</span>
            <button
                v-for="f in activos"
                :key="f.clave"
                type="button"
                class="inline-flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-1.5 pl-2.5 text-xs transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:opacity-60"
                :disabled="disabled"
                :aria-label="`Quitar filtro ${f.titulo} ${f.valor}`"
                @click="$emit('quitar', f.clave)"
            >
                <span class="text-muted-foreground">{{ f.titulo }}:</span>
                <span class="font-medium">{{ f.valor }}</span>
                <X class="size-3.5 text-muted-foreground" aria-hidden="true" />
            </button>
            <button
                type="button"
                class="text-xs font-medium text-primary underline-offset-2 hover:underline disabled:opacity-60"
                :disabled="disabled"
                @click="$emit('limpiar')"
            >
                Quitar filtros
            </button>
            <span class="text-xs text-muted-foreground">
                · Las descargas respetan estos filtros.
            </span>
        </div>
    </div>
</template>
