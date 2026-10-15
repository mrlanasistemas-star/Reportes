<script setup lang="ts">
// Fase 8.3: marca MR LANA. El logo oficial es negro/blanco con fondo
// transparente: sobre fondos oscuros desaparecía, así que siempre va sobre
// una placa blanca.
withDefaults(defineProps<{ inverted?: boolean; size?: 'sm' | 'md' }>(), { inverted: false, size: 'md' });
</script>

<template>
    <div class="flex items-center gap-3">
        <span
            :class="[
                'flex shrink-0 items-center justify-center rounded-[12px] bg-white ring-1',
                size === 'sm' ? 'size-10 p-1.5' : 'size-12 p-2',
                inverted ? 'ring-white/20 shadow-lg shadow-black/30' : 'ring-slate-200 shadow-sm dark:ring-white/10',
            ]"
        >
            <img src="/logoMrLana.png" alt="MR LANA" draggable="false" class="size-full object-contain" />
        </span>
        <span class="leading-tight">
            <span :class="['block text-base font-bold tracking-tight', inverted ? 'text-white' : 'text-foreground']">MR LANA</span>
            <span :class="['block text-xs font-medium', inverted ? 'text-white/70' : 'text-muted-foreground']">Reportes financieros</span>
        </span>
    </div>
</template>
