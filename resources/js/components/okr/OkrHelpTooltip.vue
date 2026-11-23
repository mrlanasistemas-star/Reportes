<script setup lang="ts">
/**
 * OkrHelpTooltip — ícono "?" con una explicación contextual en tooltip.
 *
 * Guía al usuario dentro del propio módulo OKR (sin depender de un manual
 * aparte). Se coloca junto a etiquetas que no son obvias (Objetivo vs KR vs
 * KPI, línea base, peso...). Se usa en OkrKpiResultsTable, OkrObjectiveSummary,
 * OkrAssignDialog y en la página Okr/KpiCatalog.
 *
 * Props:
 * - text: texto de ayuda que aparece al pasar el cursor o enfocar el ícono.
 */
import { HelpCircle } from 'lucide-vue-next';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

defineProps<{ text: string }>();
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="inline-flex size-4 shrink-0 items-center justify-center rounded-full text-muted-foreground/70 transition hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:outline-none"
                    aria-label="Ayuda"
                    @click.stop.prevent
                >
                    <HelpCircle class="size-3.5" />
                </button>
            </TooltipTrigger>
            <TooltipContent class="max-w-xs text-xs leading-relaxed">
                {{ text }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
