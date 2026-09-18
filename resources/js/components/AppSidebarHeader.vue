<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import InstallAppButton from '@/components/InstallAppButton.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
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
            <SidebarTrigger class="-ml-1 hidden md:inline-flex" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <div class="scrollbar-none min-w-0 overflow-x-auto">
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </div>
            </template>
        </div>

        <InstallAppButton class="ml-auto" />
    </header>
</template>
