<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BackgroundActivityBell from '@/components/BackgroundActivityBell.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import InstallAppButton from '@/components/InstallAppButton.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 sm:px-6 md:px-4"
    >
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <!-- En móvil el menú vive abajo (MobileBottomNav); este trigger solo aplica a partir de md -->
            <SidebarTrigger class="-ml-1 hidden md:inline-flex" aria-label="Mostrar u ocultar el menú" />
            <!-- Fase 8.4: en móvil no hay menú lateral visible; la marca da contexto y lleva al inicio. -->
            <Link :href="dashboard()" class="flex shrink-0 items-center gap-2 md:hidden" aria-label="MR LANA — ir al inicio">
                <span class="flex size-8 items-center justify-center rounded-lg bg-white p-1 ring-1 ring-border">
                    <AppLogoIcon class="size-full object-contain" alt="" />
                </span>
                <span v-if="!breadcrumbs.length" class="text-sm font-bold tracking-tight">MR LANA</span>
            </Link>
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <div class="scrollbar-none min-w-0 overflow-x-auto">
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </div>
            </template>
        </div>

        <div class="ml-auto flex items-center gap-1">
            <InstallAppButton />
            <BackgroundActivityBell />
        </div>
    </header>
</template>
