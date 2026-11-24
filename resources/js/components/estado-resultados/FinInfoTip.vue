<script setup lang="ts">
/**
 * FinInfoTip — ayuda breve en un popover (funciona con clic/toque, no sólo hover).
 *
 * Lo usan los componentes de «Gastos financieros» para sacar las explicaciones
 * largas de la vista. Sin slot muestra un ícono «i»; con slot, el slot es el disparador
 * (p. ej. un chip de cobertura).
 *
 * Props:
 * - text: texto de ayuda (una o varias líneas separadas por \n).
 * - label: nombre accesible del disparador.
 */
import { Info } from 'lucide-vue-next';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

defineProps<{ text: string; label?: string }>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex items-center rounded-full text-muted-foreground transition hover:text-foreground focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:outline-none"
                :aria-label="label ?? 'Más información'"
            >
                <slot><Info class="size-3.5" /></slot>
            </button>
        </PopoverTrigger>
        <PopoverContent
            class="w-72 space-y-1.5 p-3 text-xs leading-relaxed whitespace-pre-line"
        >
            {{ text }}
        </PopoverContent>
    </Popover>
</template>
