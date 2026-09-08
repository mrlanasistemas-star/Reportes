<script setup lang="ts">
// Ícono "?" con explicación contextual — guía al usuario dentro del propio
// módulo (sin depender de un manual aparte). Úsalo junto a cualquier label
// que no sea obvio a simple vista (Objective vs KR vs KPI, línea base, peso...).
import { HelpCircle } from 'lucide-vue-next'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'

defineProps<{ text: string }>()
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="inline-flex size-4 shrink-0 items-center justify-center rounded-full text-muted-foreground/70 transition hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
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
