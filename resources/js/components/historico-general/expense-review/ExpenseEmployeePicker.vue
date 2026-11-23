<script setup lang="ts">
/**
 * ExpenseEmployeePicker — selector con búsqueda de un colaborador del periodo.
 *
 * Se usa en el diálogo de decisión para asignar un gasto a un colaborador. Filtra por nombre
 * o sucursal (sin distinguir acentos ni mayúsculas) y muestra como máximo 40 resultados.
 *
 * Props: `employees` = colaboradores elegibles del periodo.
 * Modelo: id del colaborador elegido (`null` = ninguno).
 */
import { Check, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import type { ReviewOptions } from './types';

/** Colaborador elegible tal como lo devuelve `/opciones`. */
type Emp = ReviewOptions['employees'][number];

/** Props: lista de colaboradores elegibles. */
const props = defineProps<{ employees: Emp[] }>();
/** Id del colaborador seleccionado (v-model). */
const model = defineModel<number | null>({ required: true });

/** Texto de búsqueda. */
const query = ref('');

/**
 * Normaliza un texto para comparar: quita acentos (diacríticos) y pasa a minúsculas.
 *
 * @param s Texto a normalizar.
 * @returns Texto normalizado.
 */
const norm = (s: string) =>
    s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Colaborador actualmente seleccionado (o `null`). */
const selected = computed(
    () => props.employees.find((e) => e.id === model.value) ?? null,
);

/** Colaboradores que coinciden con la búsqueda (por nombre o sucursal), máximo 40. */
const matches = computed(() => {
    const q = norm(query.value.trim());
    const list = q
        ? props.employees.filter(
              (e) =>
                  norm(e.name).includes(q) ||
                  norm(e.branch_name ?? '').includes(q),
          )
        : props.employees;

    return list.slice(0, 40);
});
</script>

<template>
    <div class="space-y-2">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                v-model="query"
                type="search"
                class="pl-9"
                placeholder="Buscar colaborador del periodo"
                aria-label="Buscar colaborador"
            />
        </div>
        <ul
            class="max-h-52 overflow-y-auto rounded-xl border border-border"
            role="listbox"
            aria-label="Colaboradores del periodo"
        >
            <li v-for="emp in matches" :key="emp.id">
                <button
                    type="button"
                    role="option"
                    :aria-selected="model === emp.id"
                    class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition hover:bg-muted/60"
                    :class="model === emp.id ? 'bg-primary/10' : ''"
                    @click="model = emp.id"
                >
                    <span class="min-w-0">
                        <span
                            class="block truncate font-medium text-foreground"
                            >{{ emp.name }}</span
                        >
                        <span class="block text-xs text-muted-foreground">{{
                            emp.branch_name ?? 'Sin sucursal operativa'
                        }}</span>
                    </span>
                    <Check
                        v-if="model === emp.id"
                        class="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                </button>
            </li>
            <li
                v-if="!matches.length"
                class="px-3 py-4 text-center text-sm text-muted-foreground"
            >
                No hay colaboradores del periodo con ese nombre.
            </li>
        </ul>
        <p v-if="selected" class="text-xs text-muted-foreground">
            Seleccionado:
            <span class="font-semibold text-foreground">{{
                selected.name
            }}</span>
        </p>
    </div>
</template>
