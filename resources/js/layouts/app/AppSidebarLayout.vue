<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import MobileBottomNav from '@/components/MobileBottomNav.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Transición entre páginas (27-oct-2026): solo el CONTENIDO entra con un fade
// + slide sutil (.app-page-enter); sidebar, header y barra inferior quedan
// fuera del wrapper y nunca parpadean. La key es el componente de la página:
// cambiar de módulo vuelve a montar la página (Inertia ya lo hacía), pero un
// filtro/paginación con preserveState/preserveScroll sobre el MISMO componente
// no cambia la key, así que no se anima ni se pierde estado ni scroll.
const page = usePage();
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="min-h-screen overflow-x-hidden overflow-y-auto scrollbar-gutter-stable pb-24 md:pb-0"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div :key="page.component" class="app-page-enter flex min-w-0 flex-1 flex-col">
                <slot />
            </div>
        </AppContent>
        <MobileBottomNav />
    </AppShell>
</template>
