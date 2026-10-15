<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <!-- Fase 8.4: el logo oficial (negro/blanco, fondo transparente) va sobre
         placa blanca para que también se lea con el menú en modo oscuro. -->
    <div class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-lg bg-white p-1 ring-1 ring-sidebar-border">
        <AppLogoIcon class="size-full object-contain" />
    </div>
    <div class="ml-1 grid flex-1 text-left leading-tight">
        <span class="truncate text-sm font-bold tracking-tight">MR LANA</span>
        <span class="truncate text-[11px] text-sidebar-foreground/60">Reportes financieros</span>
    </div>
</template>
