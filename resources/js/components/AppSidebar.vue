<script setup lang="ts">
/**
 * AppSidebar — menú lateral principal de la aplicación.
 *
 * Lo usa AppSidebarLayout. Muestra el logo (enlace al dashboard), los módulos
 * visibles para el rol del usuario (NavMain) y el menú de usuario (NavUser).
 * También monta los componentes globales de la guía: TourOverlay,
 * WelcomeTourModal y GuideHelpFab. El slot por defecto se renderiza después.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import GuideHelpFab from '@/components/tour/GuideHelpFab.vue';
import TourOverlay from '@/components/tour/TourOverlay.vue';
import WelcomeTourModal from '@/components/tour/WelcomeTourModal.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

import { visibleMainNavItems } from '@/config/mainNav';
import { dashboard } from '@/routes';

const page = usePage();
/** Módulos del menú filtrados según los permisos del usuario autenticado. */
const navItems = computed(() => visibleMainNavItems(page.props.auth));
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="navItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>

    <TourOverlay />
    <WelcomeTourModal />
    <GuideHelpFab />

    <slot />
</template>
