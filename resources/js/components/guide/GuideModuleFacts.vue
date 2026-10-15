<script setup lang="ts">
// Ayuda «?» de cada módulo: además de qué hace (description), qué datos usa y
// qué hacer si falta información. Contenido en tour/modules.ts.
import { Database, LifeBuoy } from 'lucide-vue-next'
import type { GuideModule } from '@/tour/modules'

defineProps<{ module: Pick<GuideModule, 'uses' | 'missing'> }>()
</script>

<template>
    <dl class="space-y-2 text-xs leading-relaxed">
        <div>
            <dt class="sr-only">Datos que usa</dt>
            <dd class="flex gap-2 text-muted-foreground"><Database class="mt-0.5 size-3.5 shrink-0 text-primary" aria-hidden="true" /><span><span class="font-semibold text-foreground">Datos que usa:</span> {{ module.uses }}</span></dd>
        </div>
        <div>
            <dt class="sr-only">Si falta información</dt>
            <dd class="flex gap-2 text-muted-foreground"><LifeBuoy class="mt-0.5 size-3.5 shrink-0 text-warning" aria-hidden="true" /><span><span class="font-semibold text-foreground">Si falta información:</span> {{ module.missing }}</span></dd>
        </div>
    </dl>
</template>
