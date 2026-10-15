<script setup lang="ts">
import { Check, Monitor, Moon, Sun } from 'lucide-vue-next';
import { useAppearance } from '@/composables/useAppearance';

// Fase 8.2: selector de apariencia como tarjetas con vista previa. "Claro" es
// el predeterminado para quien nunca eligió nada; la elección se guarda en
// localStorage + cookie (useAppearance) para que el servidor pinte la primera
// carga ya en el tema correcto.
const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', Icon: Sun, label: 'Claro', hint: 'Recomendado. Fondo claro, ideal para oficina y para imprimir.' },
    { value: 'dark', Icon: Moon, label: 'Oscuro', hint: 'Fondo oscuro, cómodo en ambientes con poca luz.' },
    { value: 'system', Icon: Monitor, label: 'Igual que mi equipo', hint: 'Cambia solo según la configuración de tu sistema operativo.' },
] as const;
</script>

<template>
    <div role="radiogroup" aria-label="Tema de la interfaz" class="grid gap-3 sm:grid-cols-3">
        <button
            v-for="{ value, Icon, label, hint } in options"
            :key="value"
            type="button"
            role="radio"
            :aria-checked="appearance === value"
            :class="[
                'group relative flex flex-col overflow-hidden rounded-xl border text-left transition-colors',
                appearance === value
                    ? 'border-primary ring-2 ring-primary/20'
                    : 'border-border hover:border-foreground/25',
            ]"
            @click="updateAppearance(value)"
        >
            <!-- Mini vista previa del tema -->
            <span
                aria-hidden="true"
                :class="[
                    'flex h-16 gap-1.5 border-b p-2.5 sm:h-20',
                    value === 'light' ? 'bg-slate-50' : value === 'dark' ? 'bg-neutral-950' : 'bg-gradient-to-r from-slate-50 from-50% to-neutral-950 to-50%',
                ]"
            >
                <span :class="['w-1/4 rounded-md', value === 'dark' ? 'bg-neutral-800' : 'bg-white shadow-sm']"></span>
                <span class="flex flex-1 flex-col gap-1.5">
                    <span :class="['h-3 w-2/3 rounded', value === 'dark' ? 'bg-neutral-700' : 'bg-slate-200']"></span>
                    <span :class="['flex-1 rounded-md', value === 'dark' ? 'bg-neutral-800' : 'bg-white shadow-sm']"></span>
                </span>
            </span>
            <span class="flex items-start gap-2.5 p-3">
                <component :is="Icon" class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-foreground">{{ label }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-muted-foreground">{{ hint }}</span>
                </span>
                <span
                    :class="[
                        'flex size-5 shrink-0 items-center justify-center rounded-full border',
                        appearance === value ? 'border-primary bg-primary text-primary-foreground' : 'border-border',
                    ]"
                >
                    <Check v-if="appearance === value" class="size-3" />
                </span>
            </span>
        </button>
    </div>
</template>
